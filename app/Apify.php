<?php
declare(strict_types=1);

/**
 * Facebook via Apify : uniquement des groupes et pages publics, sans jamais
 * utiliser de compte Facebook. Facturé au résultat, donc sous plafond mensuel.
 */
final class Apify
{
    public const DEFAULT_ACTORS = [
        'search' => 'lofomachines~facebook-groups-posts-search-scraper',
        'groups' => 'apify~facebook-groups-scraper',
        'pages' => 'apify~facebook-posts-scraper',
    ];

    // Larges à dessein : une recherche couvre tous les groupes publics de Maurice d'un coup.
    public const KEYWORDS = [
        'à vendre Grand Baie', 'à vendre Pereybère', 'à vendre Trou aux Biches', 'à vendre Cap Malheureux',
        'à vendre Pointe aux Canonniers', 'à vendre Mont Choisy', 'à vendre Bain Boeuf', 'à vendre Anse la Raie',
        'terrain agricole à vendre', 'terrain à vendre nord', 'for sale Grand Baie', 'land for sale Pereybere',
    ];

    public static function ready(): bool
    {
        return (string)setting('apify_token', '') !== '';
    }

    private static function api(string $path, array $opt = []): array
    {
        $opt['headers'] = array_merge(['Authorization: Bearer ' . setting('apify_token', '')], $opt['headers'] ?? []);
        $opt['timeout'] ??= 45;
        return Http::json('https://api.apify.com/v2' . $path, $opt);
    }

    public static function actors(): array
    {
        return array_merge(self::DEFAULT_ACTORS, array_filter(setting_json('apify_actors')));
    }

    /**
     * Tarifs de l'offre gratuite Apify (novembre 2026), utilisés pour dimensionner chaque lancement.
     * start : frais fixes par lancement (à 1 Go de mémoire) ; item : prix d'une publication.
     */
    public const COSTS = [
        'search' => ['start' => 0.03, 'item' => 0.005],
        'groups' => ['start' => 0.001, 'item' => 0.005],
        'pages' => ['start' => 0.03, 'item' => 0.006],   // tarif non publié : estimation prudente
    ];

    public static function capUsd(): float { return max(0.0, (float)setting('apify_month_usd', '4.5')); }

    /** Dépense réelle du cycle en cours, lue sur le compte Apify (mise en cache 15 min). */
    public static function account(bool $refresh = false): array
    {
        $cached = setting_json('apify_account');
        if (!$refresh && $cached && ($cached['at'] ?? 0) > now() - 900) return $cached;
        $r = self::api('/users/me/limits', ['timeout' => 20]);
        $d = $r['data']['data'] ?? null;
        if (!is_array($d)) return $cached ?: ['spent' => self::estimatedSpend(), 'end' => strtotime('first day of next month 00:00'), 'at' => 0];
        $acc = [
            'spent' => round((float)($d['current']['monthlyUsageUsd'] ?? 0), 3),
            'limit' => isset($d['limits']['maxMonthlyUsageUsd']) ? (float)$d['limits']['maxMonthlyUsageUsd'] : null,
            'end' => strtotime((string)($d['monthlyUsageCycle']['endAt'] ?? '')) ?: strtotime('first day of next month 00:00'),
            'at' => now(),
        ];
        set_setting('apify_account', json_encode($acc));
        return $acc;
    }

    /** Estimation locale, si le compte Apify ne répond pas. */
    private static function estimatedSpend(): float
    {
        $sum = 0.0;
        foreach (Db::all('SELECT recipe, items FROM apify_runs WHERE started_at >= ?', [strtotime('first day of this month 00:00')]) as $r) {
            $c = self::COSTS[$r['recipe']] ?? self::COSTS['pages'];
            $sum += $c['start'] + $c['item'] * (int)$r['items'];
        }
        return $sum;
    }

    public static function remainingUsd(): float
    {
        return max(0.0, self::capUsd() - (float)(self::account()['spent'] ?? 0));
    }

    public static function tick(bool $force = false): array
    {
        if (!self::ready()) return ['skipped' => 'pas de jeton Apify'];
        $out = ['new' => 0, 'deals' => 0, 'started' => [], 'fetched' => 0];
        try {
            $out = array_merge($out, self::collectFinished());
            $out['started'] = self::startDue($force);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            set_setting('apify_error', Text::cut($e->getMessage(), 200) . ' (' . date('d/m H:i') . ')');
        }
        return $out;
    }

    // ---------------------------------------------------------------- lancement

    /**
     * Lancements regroupés, deux par jour au plus :
     *  - 8 h (Maurice) : UNE recherche avec tous les mots-clés du jour, dans tous les groupes publics ;
     *  - 19 h : un jour sur deux le fil des groupes suivis (lancement quasi gratuit), l'autre jour les pages d'agents.
     * Le budget restant du mois est réparti sur les jours restants ; 70 % pour la recherche du matin.
     * Un lancement trop petit pour être utile est sauté : son budget reste disponible les jours suivants.
     */
    /** Lancement à la demande depuis l'application : une recherche, dans la limite du budget du jour. */
    public static function runNow(): array
    {
        if (!self::ready()) return ['error' => 'Colle d\'abord ton jeton Apify.'];
        if (Db::val("SELECT COUNT(*) FROM apify_runs WHERE status IN ('READY', 'RUNNING')") > 0) return ['error' => 'Une lecture Facebook est déjà en cours. Les résultats arrivent dans quelques minutes.'];
        set_setting('apify_error', null);
        $started = self::startDue(true, 0);
        $err = setting('apify_error');
        return $err ? ['error' => $err] : ['started' => $started];
    }

    private static function startDue(bool $force, ?int $forceSlot = null): array
    {
        $hours = array_map('intval', explode(',', (string)setting('fb_hours', '8,19')));
        sort($hours);
        $h = (int)date('G');
        $slot = null;
        foreach ($hours as $i => $hh) if ($h >= $hh) $slot = $i;
        if ($slot === null && !$force) return [];
        $slot = $forceSlot ?? $slot ?? 0;
        $slotKey = date('Ymd') . '-' . $slot;
        if (!$force && setting('fb_last_slot') === $slotKey) return [];
        if (Db::val("SELECT COUNT(*) FROM apify_runs WHERE status IN ('READY', 'RUNNING')") > 0) return [];
        set_setting('fb_last_slot', $slotKey);

        $acc = self::account(true);
        $remaining = max(0.0, self::capUsd() - (float)$acc['spent']);
        if ($acc['limit'] !== null) $remaining = min($remaining, max(0.0, $acc['limit'] - (float)$acc['spent']));
        $daysLeft = max(1, (int)ceil(((int)$acc['end'] - now()) / 86400));
        $daily = $remaining / $daysLeft;
        $actors = self::actors();

        if ($slot === 0) {
            $budget = $daily * (count($hours) > 1 ? 0.7 : 1.0);
            $n = self::itemsFor('search', $budget);
            if ($n < 6) { set_setting('apify_error', 'budget du jour insuffisant (' . number_format($budget, 2) . ' $)'); return []; }
            $kws = self::keywords();
            $nKw = min(count($kws), max(1, intdiv($n, 3)));     // au moins 3 publications par mot-clé
            $kws = self::rotate('fb_kw_cursor', $kws, $nKw);
            return array_values(array_filter([self::start('search', $actors['search'], [
                'keywords' => $kws,
                'afterDate' => 'last_week',   // seules valeurs admises : anytime, last_week, last_month, this_year
                'maxPosts' => max(3, intdiv($n, $nKw)),
                'countryCode' => 'mu',
            ], $n, $budget, ['keywords' => $kws])]));
        }

        $budget = $daily * 0.3 * 2;   // un lancement tous les deux jours pour chaque type
        if ((int)date('z') % 2 === 0) {
            $n = self::itemsFor('pages', $budget);
            $pages = $n >= 6 ? self::rotate('fb_page_cursor', self::sourceUrls('fb_page'), max(2, min(12, intdiv($n, 2)))) : [];
            if ($pages) return array_values(array_filter([self::start('pages', $actors['pages'], [
                'startUrls' => array_map(fn($u) => ['url' => $u], $pages),
                'resultsLimit' => max(1, intdiv($n, count($pages))),
            ], $n, $budget, ['urls' => $pages])]));
        }
        $n = self::itemsFor('groups', $budget);
        if ($n < 6) return ['budget insuffisant aujourd\'hui'];
        $groups = self::rotate('fb_group_cursor', self::sourceUrls('fb_group'), max(1, min(6, intdiv($n, 5))));
        if (!$groups) return [];
        // Pas de filtre par date côté Apify : il est facturé en plus. Le fil chronologique suffit,
        // et les publications déjà connues sont ignorées à l'arrivée.
        return array_values(array_filter([self::start('groups', $actors['groups'], [
            'startUrls' => array_map(fn($u) => ['url' => $u], $groups),
            'resultsLimit' => max(4, intdiv($n, count($groups))),
            'viewOption' => 'CHRONOLOGICAL',
        ], $n, $budget, ['urls' => $groups])]));
    }

    /** Nombre de publications que l'on peut se payer avec ce budget, frais de lancement déduits. */
    private static function itemsFor(string $recipe, float $usd): int
    {
        $c = self::COSTS[$recipe];
        return max(0, (int)floor(($usd - $c['start']) / $c['item']));
    }

    public static function keywords(): array
    {
        $custom = array_values(array_filter(array_map('trim', explode("\n", (string)setting('fb_keywords', '')))));
        return $custom ?: self::KEYWORDS;
    }

    private static function sourceUrls(string $kind): array
    {
        $rows = Db::all('SELECT url, cfg FROM sources WHERE kind = ? AND enabled = 1 ORDER BY id', [$kind]);
        usort($rows, fn($a, $b) => ((json_decode($a['cfg'], true)['tier'] ?? 2) <=> (json_decode($b['cfg'], true)['tier'] ?? 2)));
        return array_column($rows, 'url');
    }

    /** Prend n éléments à la suite du curseur, pour couvrir toute la liste au fil des passages. */
    private static function rotate(string $cursorKey, array $list, int $n): array
    {
        if (!$list) return [];
        $c = (int)setting($cursorKey, '0') % count($list);
        $out = [];
        for ($i = 0; $i < min($n, count($list)); $i++) $out[] = $list[($c + $i) % count($list)];
        set_setting($cursorKey, (string)(($c + $n) % count($list)));
        return $out;
    }

    private static function start(string $recipe, string $actor, array $input, int $maxItems, float $usd, array $meta): ?string
    {
        $cap = number_format(max(0.02, $usd * 1.1), 3, '.', '');
        // 1 Go de mémoire : les frais de lancement sont comptés par Go.
        $base = '/acts/' . rawurlencode($actor) . '/runs?maxItems=' . max(1, $maxItems) . '&maxTotalChargeUsd=' . $cap . '&timeout=600';
        $r = self::api($base . '&memory=1024', ['body' => $input]);
        if ($r['code'] === 400 && str_contains(strtolower((string)($r['data']['error']['message'] ?? '')), 'memory')) {
            $r = self::api($base, ['body' => $input]);   // l'acteur exige plus de mémoire
        }
        if ($r['code'] >= 300 || empty($r['data']['data']['id'])) {
            $msg = "$recipe : " . ($r['data']['error']['message'] ?? ('HTTP ' . $r['code'] . ' ' . $r['error']));
            set_setting('apify_error', Text::cut($msg, 220) . ' (' . date('d/m H:i') . ')');
            return null;
        }
        $d = $r['data']['data'];
        Db::run('INSERT OR REPLACE INTO apify_runs(run_id, recipe, dataset_id, meta, started_at, status) VALUES(?,?,?,?,?,?)',
            [$d['id'], $recipe, $d['defaultDatasetId'] ?? '', json_encode($meta + ['max' => $maxItems, 'usd' => round($usd, 3)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), $d['status'] ?? 'RUNNING']);
        return $recipe . ' (' . $maxItems . ' publications max, ' . number_format($usd, 2) . ' $)';
    }

    // --------------------------------------------------------------- récolte

    private static function collectFinished(): array
    {
        $out = ['new' => 0, 'deals' => 0, 'fetched' => 0];
        foreach (Db::all("SELECT * FROM apify_runs WHERE status IN ('READY', 'RUNNING') ORDER BY started_at") as $run) {
            $r = self::api('/actor-runs/' . $run['run_id']);
            $status = $r['data']['data']['status'] ?? null;
            if ($status === null) {
                if ($run['started_at'] < now() - 7200) Db::run("UPDATE apify_runs SET status = 'PERDU', finished_at = ? WHERE run_id = ?", [now(), $run['run_id']]);
                continue;
            }
            if (in_array($status, ['READY', 'RUNNING'], true)) {
                if ($run['started_at'] < now() - 3600) self::api('/actor-runs/' . $run['run_id'] . '/abort', ['method' => 'POST', 'body' => new stdClass()]);
                continue;
            }
            $dataset = $r['data']['data']['defaultDatasetId'] ?? $run['dataset_id'];
            $items = [];
            if ($dataset) {
                $ir = self::api('/datasets/' . $dataset . '/items?clean=true&format=json&limit=1000', ['timeout' => 60]);
                if (is_array($ir['data']) && array_is_list($ir['data'])) $items = $ir['data'];
            }
            if ($items) set_setting('apify_sample_' . $run['recipe'], Text::cut(json_encode(array_keys((array)$items[0])), 600));
            $kind = $run['recipe'] === 'pages' ? 'fb_page' : 'fb_group';
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $raw = self::normalize($item);
                if (!$raw) continue;
                $src = self::sourceFor($raw, $kind, $run['recipe']);
                $in = Pipeline::ingest($raw, $src);
                if (!$in) continue;
                if ($in['new']) $out['new']++;
                if ($in['new'] && $in['bucket'] === 'deal') $out['deals']++;
            }
            $out['fetched'] += count($items);
            Db::run('UPDATE apify_runs SET status = ?, items = ?, finished_at = ? WHERE run_id = ?', [$status, count($items), now(), $run['run_id']]);
            if ($status !== 'SUCCEEDED') set_setting('apify_error', "passage {$run['recipe']} terminé en état $status (" . date('d/m H:i') . ')');
            elseif ($items) set_setting('apify_error', null);
        }
        Db::run('DELETE FROM apify_runs WHERE started_at < ?', [now() - 45 * 86400]);
        return $out;
    }

    private static function sourceFor(array $raw, string $kind, string $recipe): array
    {
        $name = $raw['group'] ?? null;
        $row = null;
        if (!empty($raw['origin_url'])) {
            $id = self::fbId($raw['origin_url']);
            if ($id) $row = Db::row('SELECT * FROM sources WHERE kind = ? AND url LIKE ?', [$kind, '%' . $id . '%']);
        }
        if ($row) {
            Db::run('UPDATE sources SET last_run_at = ?, last_ok_at = ?, total = total + 1 WHERE id = ?', [now(), now(), $row['id']]);
            return Sources::decode($row);
        }
        return ['id' => null, 'name' => $name ? "Facebook · $name" : ($recipe === 'pages' ? 'Facebook · page' : 'Facebook · groupe public'), 'kind' => $kind, 'cfg_arr' => []];
    }

    private static function fbId(string $url): ?string
    {
        if (preg_match('~facebook\.com/groups/([^/?#]+)~', $url, $m)) return $m[1];
        if (preg_match('~facebook\.com/(profile\.php\?id=\d+|p/[^/?#]+|[^/?#]+)~', $url, $m)) return $m[1];
        return null;
    }

    /** Les acteurs Apify n'ont pas tous les mêmes noms de champs : on cherche large. */
    public static function normalize(array $it): ?array
    {
        $first = function (array $keys) use ($it) {
            foreach ($keys as $k) {
                $v = $it;
                foreach (explode('.', $k) as $part) {
                    if (!is_array($v) || !array_key_exists($part, $v)) { $v = null; break; }
                    $v = $v[$part];
                }
                if (is_string($v) && trim($v) !== '') return trim($v);
                if (is_int($v) || is_float($v)) return $v;
            }
            return null;
        };
        if (($it['isRelevant'] ?? null) === false) return null;
        $text = (string)($first(['text', 'message', 'postText', 'post_text', 'content', 'body', 'description', 'caption']) ?? '');
        if (mb_strlen($text) < 25) return null;
        // Dans les groupes généralistes, on ne garde que ce qui parle d'immobilier.
        if (!preg_match('~terrain|maison|villa|appart|apartment|house|land\b|plot\b|arpent|perche|toise|studio|duplex|bungalow|lakaz|later\b|propert|immobili~i', Text::fold($text))) return null;

        $url = (string)($first(['url', 'post_url', 'postUrl', 'facebookUrl', 'permalink', 'link', 'topLevelUrl']) ?? '');
        $date = $first(['time', 'date', 'timestamp', 'created_time', 'publishedAt', 'createdAt', 'date_posted']);
        $ts = is_numeric($date) ? (int)($date > 2e10 ? $date / 1000 : $date) : ($date ? (strtotime((string)$date) ?: null) : null);

        $photos = [];
        array_walk_recursive($it, function ($v, $k) use (&$photos) {
            if (!is_string($v) || count($photos) >= 8) return;
            if (preg_match('~^https?://[^\s]+~', $v) && (preg_match('~(scontent|fbcdn)~', $v) || preg_match('~\.(jpe?g|png|webp)(\?|$)~i', $v))
                && !preg_match('~(profile|avatar|emoji|static\.xx|rsrc\.php)~i', $v) && !preg_match('~(profile|avatar|user|author)~i', (string)$k)) {
                $photos[$v] = true;
            }
        });
        $firstLine = trim((string)strtok($text, "\n"));

        return [
            'url' => $url ? Http::tidy($url) : '',
            'title' => Text::cut($firstLine !== '' ? $firstLine : $text, 110),
            'text' => $text,
            'photos' => array_keys($photos),
            'author' => $first(['user.name', 'author_name', 'authorName', 'author.name', 'profileName', 'userName', 'pageName']),
            'group' => $first(['groupTitle', 'group_name', 'groupName', 'pageName', 'group.name']),
            'origin_url' => $first(['inputUrl', 'group_url', 'groupUrl', 'pageUrl', 'facebookUrl']),
            'posted_at' => $ts,
        ];
    }

    public static function status(): array
    {
        return [
            'ready' => self::ready(),
            'spent' => self::ready() ? (float)(self::account()['spent'] ?? 0) : 0.0,
            'cap_usd' => self::capUsd(),
            'cycle_end' => self::ready() ? (int)(self::account()['end'] ?? 0) : null,
            'posts_month' => (int)Db::val('SELECT COALESCE(SUM(items), 0) FROM apify_runs WHERE started_at >= ?', [strtotime('first day of this month 00:00')]),
            'error' => setting('apify_error'),
            'last' => Db::all('SELECT recipe, status, items, started_at FROM apify_runs ORDER BY started_at DESC LIMIT 6'),
            'hours' => setting('fb_hours', '8,19'),
        ];
    }
}
