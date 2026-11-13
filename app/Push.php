<?php
declare(strict_types=1);

/**
 * Notifications du téléphone (Web Push), sans service tiers.
 * On envoie un simple « ping » signé ; l'application va chercher elle-même quoi afficher.
 */
final class Push
{
    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Crée la paire de clés au premier appel. @return array{pem:string, pub:string} */
    public static function keys(): array
    {
        $pem = setting('vapid_pem');
        $pub = setting('vapid_pub');
        if ($pem && $pub) return ['pem' => $pem, 'pub' => $pub];
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) throw new RuntimeException('Impossible de créer les clés de notification.');
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $pub = self::b64("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
        set_setting('vapid_pem', $pem);
        set_setting('vapid_pub', $pub);
        return ['pem' => $pem, 'pub' => $pub];
    }

    private static function jwt(string $audience, string $pem): string
    {
        $head = self::b64('{"typ":"JWT","alg":"ES256"}');
        $claims = self::b64(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:veille@' . (parse_url((string)setting('base_url', 'https://immo.ile-maurice.site'), PHP_URL_HOST) ?: 'example.org')]));
        openssl_sign("$head.$claims", $der, $pem, OPENSSL_ALGO_SHA256);
        // Signature DER -> R || S sur 64 octets
        $pos = 2;
        if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
        $read = function () use ($der, &$pos): string {
            $pos++; // 0x02
            $len = ord($der[$pos++]);
            $v = substr($der, $pos, $len);
            $pos += $len;
            return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
        };
        $sig = $read() . $read();
        return "$head.$claims." . self::b64($sig);
    }

    /** @return int nombre d'appareils joints */
    public static function ping(): int
    {
        $subs = Db::all('SELECT * FROM push_subs');
        if (!$subs) return 0;
        $k = self::keys();
        $ok = 0;
        foreach ($subs as $s) {
            $p = parse_url($s['endpoint']);
            if (!$p || empty($p['host'])) continue;
            $jwt = self::jwt($p['scheme'] . '://' . $p['host'], $k['pem']);
            $r = Http::get($s['endpoint'], [
                'method' => 'POST', 'post' => '', 'timeout' => 15,
                'headers' => ['TTL: 43200', 'Urgency: high', 'Content-Length: 0', "Authorization: vapid t=$jwt, k={$k['pub']}"],
            ]);
            if ($r['code'] >= 200 && $r['code'] < 300) {
                $ok++;
                Db::run('UPDATE push_subs SET last_ok = ?, fails = 0 WHERE id = ?', [now(), $s['id']]);
            } elseif (in_array($r['code'], [404, 410], true)) {
                Db::run('DELETE FROM push_subs WHERE id = ?', [$s['id']]);
            } else {
                Db::run('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [$s['id']]);
                set_setting('push_error', 'HTTP ' . $r['code'] . ' ' . Text::cut($r['body'] ?: $r['error'], 120));
            }
        }
        return $ok;
    }

    private static function quietNow(): bool
    {
        $q = (string)setting('quiet_hours', '22-7'); // heure de Paris
        if (!preg_match('~^(\d{1,2})-(\d{1,2})$~', $q, $m)) return false;
        $h = (int)(new DateTime('now', new DateTimeZone('Europe/Paris')))->format('G');
        [$a, $b] = [(int)$m[1], (int)$m[2]];
        return $a === $b ? false : ($a < $b ? ($h >= $a && $h < $b) : ($h >= $a || $h < $b));
    }

    /** Signale les nouveaux bons plans. Rien la nuit : ils attendent le matin. */
    public static function notifyNew(): array
    {
        $min = (int)setting('notify_min', '62');
        $n = (int)Db::val("SELECT COUNT(*) FROM listings WHERE notified = 0 AND bucket = 'deal' AND dup_of IS NULL AND status = 'new' AND seen = 0 AND score >= ? AND first_seen > ?", [$min, now() - 3 * 86400]);
        if ($n === 0) return ['pending' => 0];
        if (self::quietNow()) return ['pending' => $n, 'held' => 'heures calmes'];
        if (!Db::val('SELECT COUNT(*) FROM push_subs')) return ['pending' => $n, 'held' => 'aucun téléphone abonné'];
        Db::run("UPDATE listings SET notified = 1 WHERE notified = 0 AND bucket = 'deal' AND dup_of IS NULL AND status = 'new' AND seen = 0 AND score >= ? AND first_seen > ?", [$min, now() - 3 * 86400]);
        return ['pending' => $n, 'sent_to' => self::ping()];
    }

    /** Ce que le téléphone doit afficher à la réception d'un ping. */
    public static function pending(): array
    {
        $rows = Db::all("SELECT id, title, price, zone, kind, score, reasons FROM listings WHERE notified = 1 ORDER BY score DESC LIMIT 12");
        Db::run('UPDATE listings SET notified = 2 WHERE notified = 1');
        if (!$rows) return [];
        $price = fn($p) => $p ? rtrim(rtrim(number_format($p / 1e6, 2, ',', ' '), '0'), ',') . ' M Rs' : 'prix à demander';
        if (count($rows) === 1) {
            $r = $rows[0];
            $why = json_decode((string)$r['reasons'], true) ?: [];
            return [[
                'title' => $price($r['price']) . ' · ' . ($r['zone'] ?: 'Nord'),
                'body' => Text::cut((string)$r['title'], 90) . ($why ? "\n" . implode(' · ', array_slice($why, 0, 2)) : ''),
                'tag' => 'l' . $r['id'], 'url' => '/#/a/' . $r['id'],
            ]];
        }
        $lines = [];
        foreach (array_slice($rows, 0, 4) as $r) $lines[] = $price($r['price']) . ' · ' . ($r['zone'] ?: 'Nord') . ' · ' . $r['score'] . '/100';
        return [[
            'title' => count($rows) . ' nouveaux bons plans',
            'body' => implode("\n", $lines),
            'tag' => 'lot' . time(), 'url' => '/#/',
        ]];
    }
}
