<?php
declare(strict_types=1);

/** De la donnée brute à l'annonce classée, dédoublonnée et enregistrée. */
final class Pipeline
{
    /**
     * @param array $raw url, title, text, price(array|null), location, photos, phone, beds, land_m2, built_m2, posted_at, author
     * @return array{id:int, new:bool, bucket:string}|null
     */
    public static function ingest(array $raw, array $source): ?array
    {
        $title = Text::clean((string)($raw['title'] ?? ''));
        $text = Text::clean((string)($raw['text'] ?? ''));
        if ($title === '' && $text === '') return null;
        if ($title === '') $title = Text::cut(trim(strtok($text, "\n")), 110);
        $url = (string)($raw['url'] ?? '');
        $cfg = $source['cfg_arr'] ?? [];

        $kind = $raw['kind'] ?? Classify::kind($title, $text, $url);
        $geo = Geo::detect($title . ' ' . ($raw['location'] ?? '') . ' ' . str_replace(['-', '_', '/'], ' ', (string)parse_url($url, PHP_URL_PATH)), mb_substr($text, 0, 1500));
        if (!empty($raw['zone'])) {
            $g2 = Geo::detect((string)$raw['zone']);
            if ($g2['zone']) $geo = $g2;
        }

        $price = $raw['price'] ?? Text::price($title) ?? Text::price(mb_substr($text, 0, 1800));
        $areas = Text::areas($title . "\n" . mb_substr($text, 0, 2500), $kind);
        $land = $raw['land_m2'] ?? $areas['land'];
        $built = $raw['built_m2'] ?? $areas['built'];
        if (str_starts_with($kind, 'terrain')) { $land = $land ?: $built; $built = null; }
        $phone = $raw['phone'] ?? Text::phone($title . "\n" . $text);
        $beds = $raw['beds'] ?? Text::beds($title . "\n" . mb_substr($text, 0, 1500));

        $l = [
            'source_id' => $source['id'] ?? null,
            'source_name' => $source['name'] ?? 'Ajout manuel',
            'source_kind' => $source['kind'] ?? 'manual',
            'seller_hint' => $cfg['seller'] ?? null,
            'url' => $url,
            'title' => Text::cut($title, 200),
            'text' => Text::cut($text, 3500),
            'price' => $price['amount'] ?? null,
            'price_unit' => $price['unit'] ?? null,
            'kind' => $kind,
            'zone' => $geo['zone'],
            'zone_group' => $geo['group'],
            'beds' => $beds,
            'land_m2' => $land,
            'built_m2' => $built,
            'phone' => $phone,
            'author' => $raw['author'] ?? null,
            'photos' => array_values(array_slice($raw['photos'] ?? [], 0, 8)),
            'posted_at' => $raw['posted_at'] ?? null,
            'first_seen' => now(),
        ];
        $l['fp'] = self::fingerprint($l);

        $existing = Db::row('SELECT * FROM listings WHERE fp = ? OR (url = ? AND url <> \'\')', [$l['fp'], $url]);
        if ($existing) {
            $l['first_seen'] = (int)$existing['first_seen'];
            if ($existing['price'] && $l['price'] && (int)$existing['price'] !== (int)$l['price'] && !$l['price_unit']) {
                $l['price_prev'] = (int)$existing['price'] > (int)$l['price'] ? (int)$existing['price'] : null;
            } else {
                $l['price_prev'] = $existing['price_prev'];
            }
            $l = array_merge($l, self::aiExtras($existing['ai_summary'] ?? null));
            if ($existing['ai_done']) { // on garde ce que l'IA avait complété
                foreach (['kind', 'zone', 'zone_group'] as $k) if (!$l[$k] || $l[$k] === 'inconnu') $l[$k] = $existing[$k];
                $l['price'] ??= $existing['price'] ? (int)$existing['price'] : null;
            }
        }

        $v = Classify::evaluate($l);
        if (isset($v['price'])) $l['price'] = $v['price'];

        $cols = [
            'source_id' => $l['source_id'], 'source_name' => $l['source_name'], 'source_kind' => $l['source_kind'],
            'url' => $l['url'], 'title' => $l['title'], 'text' => $l['text'],
            'price' => $l['price'], 'price_note' => $v['price_note'] ?? null, 'price_prev' => $l['price_prev'] ?? null,
            'kind' => $l['kind'], 'zone' => $l['zone'], 'zone_group' => $l['zone_group'],
            'beds' => $l['beds'], 'land_m2' => $l['land_m2'], 'built_m2' => $l['built_m2'],
            'phone' => $l['phone'], 'author' => $l['author'],
            'photos' => json_encode($l['photos'], JSON_UNESCAPED_SLASHES),
            'seller' => $v['seller'], 'beach' => $v['beach'],
            'profile' => $v['profile'], 'bucket' => $v['bucket'], 'score' => $v['score'],
            'reasons' => json_encode($v['reasons'] ?? [], JSON_UNESCAPED_UNICODE),
            'flags' => json_encode($v['flags'] ?? [], JSON_UNESCAPED_UNICODE),
            'posted_at' => $l['posted_at'], 'last_seen' => now(),
        ];

        if ($existing) {
            if ($existing['dup_of']) $cols['bucket'] = 'none';
            $priceDropped = !empty($l['price_prev']) && $l['price_prev'] !== ($existing['price_prev'] ?? null);
            // Une annonce écartée le reste ; un favori aussi. On ne touche pas au suivi.
            $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols)));
            Db::run("UPDATE listings SET $set" . ($priceDropped ? ', seen = 0, notified = 0' : '') . ' WHERE id = ?', [...array_values($cols), $existing['id']]);
            return ['id' => (int)$existing['id'], 'new' => false, 'bucket' => $v['bucket']];
        }

        $cols['fp'] = $l['fp'];
        $cols['first_seen'] = now();
        $cols['dup_of'] = $v['bucket'] !== 'none' ? self::findDuplicate($l) : null;
        Db::run('INSERT INTO listings (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($cols));
        return ['id' => Db::lastId(), 'new' => true, 'bucket' => $cols['dup_of'] ? 'none' : $v['bucket']];
    }

    /** Recalcule profil et note d'une annonce déjà en base (après un changement de critères ou un passage de l'IA). */
    public static function rescore(array $row, array $extra = []): void
    {
        $l = array_merge($row, self::aiExtras($row['ai_summary'] ?? null), $extra);
        $l['price'] = $row['price'] ? (int)$row['price'] : null;
        $l['price_unit'] = null;
        $l['title'] = (string)$row['title'];
        $l['text'] = (string)$row['text'];
        $v = Classify::evaluate($l);
        Db::run('UPDATE listings SET profile = ?, bucket = ?, score = ?, reasons = ?, flags = ?, seller = ?, beach = ? WHERE id = ?', [
            $v['profile'], $row['dup_of'] ? 'none' : $v['bucket'], $v['score'],
            json_encode($v['reasons'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($v['flags'] ?? [], JSON_UNESCAPED_UNICODE),
            $v['seller'], $v['beach'] ?? $row['beach'], $row['id'],
        ]);
    }

    /** Ce que l'analyse IA a apporté, réutilisé à chaque recalcul. */
    private static function aiExtras(?string $json): array
    {
        $a = $json ? json_decode($json, true) : null;
        if (!is_array($a)) return [];
        $x = [];
        if (isset($a['avis'])) $x['ai_delta'] = max(-10, min(10, (int)$a['avis']));
        if (!empty($a['plage']) && is_string($a['plage']) && !preg_match('~^(non|aucun|inconnu|null|pas )~i', $a['plage'])) $x['ai_beach'] = $a['plage'];
        if (in_array($a['vendeur'] ?? null, ['particulier', 'agence'], true)) $x['seller_hint'] = $a['vendeur'];
        return $x;
    }

    public static function rescoreAll(): int
    {
        $n = 0;
        foreach (Db::all('SELECT * FROM listings') as $row) { self::rescore($row); $n++; }
        return $n;
    }

    private static function fingerprint(array $l): string
    {
        if ($l['url'] !== '') return sha1('u|' . preg_replace('~^https?://(www\.|m\.|web\.)?~', '', rtrim($l['url'], '/')));
        return sha1('t|' . Text::fold(mb_substr($l['title'] . $l['text'], 0, 300)));
    }

    /** Même bien déjà vu ailleurs : même nature, même secteur, même prix, et un indice de plus. */
    private static function findDuplicate(array $l): ?int
    {
        if (!$l['price'] || !$l['kind']) return null;
        $rows = Db::all('SELECT id, title, phone, land_m2, built_m2, beds, zone FROM listings
                         WHERE dup_of IS NULL AND kind = ? AND price BETWEEN ? AND ? AND first_seen > ? LIMIT 40',
            [$l['kind'], (int)($l['price'] * 0.985), (int)($l['price'] * 1.015), now() - 200 * 86400]);
        foreach ($rows as $r) {
            if ($l['zone'] && $r['zone'] && $l['zone'] !== $r['zone']) continue;
            if ($l['phone'] && $r['phone'] && $l['phone'] === $r['phone']) return (int)$r['id'];
            $sameLand = $l['land_m2'] && $r['land_m2'] && abs($l['land_m2'] - $r['land_m2']) <= max(3, $r['land_m2'] * 0.02);
            $sameBuilt = $l['built_m2'] && $r['built_m2'] && abs($l['built_m2'] - $r['built_m2']) <= max(2, $r['built_m2'] * 0.02);
            if ($sameLand || $sameBuilt) return (int)$r['id'];
            if (Text::similar($l['title'], (string)$r['title']) >= 82) return (int)$r['id'];
        }
        return null;
    }
}
