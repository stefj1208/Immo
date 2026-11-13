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

    public const KEYWORDS = [
        'terrain à vendre Grand Baie', 'maison à vendre Grand Baie', 'appartement à vendre Grand Baie',
        'terrain à vendre Pereybère', 'maison à vendre Pereybère', 'appartement à vendre Pereybère',
        'terrain à vendre Trou aux Biches', 'maison à vendre Trou aux Biches', 'appartement à vendre Trou aux Biches',
        'terrain à vendre Cap Malheureux', 'maison à vendre Cap Malheureux', 'terrain à vendre Mont Choisy',
        'terrain à vendre Pointe aux Canonniers', 'maison à vendre Pointe aux Canonniers', 'terrain à vendre Bain Boeuf',
        'terrain à vendre Anse la Raie', 'terrain agricole à vendre nord', 'terrain agricole Pamplemousses',
        'terrain agricole Solitude', 'terrain agricole Cottage', 'terrain agricole Amaury', 'terrain agricole Triolet',
        'terrain agricole Goodlands', 'terrain agricole Mapou', 'land for sale Grand Baie', 'land for sale Pereybere',
        'house for sale Grand Baie', 'apartment for sale Grand Baie', 'agricultural land for sale north Mauritius',
        'terrain agricole Petit Raffray', 'terrain agricole Fond du Sac', 'terrain agricole Plaine des Papayes',
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

    public static function monthKey(): string { return 'apify_used_' . date('Ym'); }
    public static function cap(): int { return (int)setting('apify_month_cap', '1500'); }
    public static function used(): int { return (int)setting(self::monthKey(), '0'); }
    public static function remaining(): int { return max(0, self::cap() - self::used()); }

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

    private static function startDue(bool $force): array
    {
        $hours = array_map('intval', explode(',', (string)setting('fb_hours', '8,13,20')));
        sort($hours);
        $h = (int)date('G');
        $slot = null;
        foreach ($hours as $i => $hh) if ($h >= $hh) $slot = $i;
        if ($slot === null && !$force) return [];
        $slotKey = date('Ymd') . '-' . ($slot ?? 'x');
        if (!$force && setting('fb_last_slot') === $slotKey) return [];
        if (Db::val("SELECT COUNT(*) FROM apify_runs WHERE status IN ('READY', 'RUNNING')") > 0) return [];

        $remaining = self::remaining();
        if ($remaining < 8) {
            set_setting('fb_last_slot', $slotKey);
            return ['plafond mensuel atteint'];
        }
        $daysLeft = max(1, (int)date('t') - (int)date('j') + 1);
        $slotBudget = max(8, (int)floor($remaining / ($daysLeft * count($hours))));
        $actors = self::actors();
        $started = [];

        // 1. Recherche par mots-clés dans tous les groupes publics : le meilleur rapport signal / coût.
        $searchBudget = min($slotBudget, 36);
        $nKw = max(2, min(6, intdiv($searchBudget, 6)));
        $kws = self::rotate('fb_kw_cursor', self::keywords(), $nKw);
        $started[] = self::start('search', $actors['search'], [
            'keywords' => $kws,
            'afterDate' => '3 days',
            'maxPosts' => max(3, intdiv($searchBudget, $nKw)),
            'countryCode' => 'mu',
        ], $searchBudget, ['keywords' => $kws]);
        $rest = $slotBudget - $searchBudget;

        // 2. Pages d'agents : une fois par jour, quand le budget le permet.
        if ($rest >= 12 && ($slot === 0 || $force)) {
            $pages = self::rotate('fb_page_cursor', self::sourceUrls('fb_page'), max(3, min(10, intdiv($rest, 4))));
            if ($pages) {
                $n = min($rest, count($pages) * 3);
                $started[] = self::start('pages', $actors['pages'], [
                    'startUrls' => array_map(fn($u) => ['url' => $u], $pages),
                    'resultsLimit' => 3,
                    'onlyPostsNewerThan' => '3 days',
                ], $n, ['urls' => $pages]);
                $rest -= $n;
            }
        }
        // 3. Fil chronologique des groupes prioritaires, avec le budget restant.
        if ($rest >= 10) {
            $groups = self::rotate('fb_group_cursor', self::sourceUrls('fb_group'), max(1, min(6, intdiv($rest, 10))));
            if ($groups) {
                $started[] = self::start('groups', $actors['groups'], [
                    'startUrls' => array_map(fn($u) => ['url' => $u], $groups),
                    'resultsLimit' => max(5, intdiv($rest, count($groups))),
                    'viewOption' => 'CHRONOLOGICAL',
                    'onlyPostsNewerThan' => '2 days',
                ], $rest, ['urls' => $groups]);
            }
        }
        set_setting('fb_last_slot', $slotKey);
        return array_values(array_filter($started));
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

    private static function start(string $recipe, string $actor, array $input, int $maxItems, array $meta): ?string
    {
        $r = self::api('/acts/' . rawurlencode($actor) . '/runs?maxItems=' . max(1, $maxItems) . '&timeout=600&memory=1024', ['body' => $input]);
        if ($r['code'] >= 300 || empty($r['data']['data']['id'])) {
            $msg = "$recipe : " . ($r['data']['error']['message'] ?? ('HTTP ' . $r['code'] . ' ' . $r['error']));
            set_setting('apify_error', Text::cut($msg, 220) . ' (' . date('d/m H:i') . ')');
            return null;
        }
        $d = $r['data']['data'];
        Db::run('INSERT OR REPLACE INTO apify_runs(run_id, recipe, dataset_id, meta, started_at, status) VALUES(?,?,?,?,?,?)',
            [$d['id'], $recipe, $d['defaultDatasetId'] ?? '', json_encode($meta + ['max' => $maxItems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), $d['status'] ?? 'RUNNING']);
        return $recipe;
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
            set_setting(self::monthKey(), (string)(self::used() + count($items)));
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
            'used' => self::used(),
            'cap' => self::cap(),
            'error' => setting('apify_error'),
            'last' => Db::all('SELECT recipe, status, items, started_at FROM apify_runs ORDER BY started_at DESC LIMIT 6'),
            'hours' => setting('fb_hours', '8,13,20'),
        ];
    }
}
