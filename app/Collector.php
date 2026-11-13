<?php
declare(strict_types=1);

/** Un passage de collecte : sites, Facebook (via Apify), IA, notifications. */
final class Collector
{
    private const MAX_DETAILS_PER_SOURCE = 80;

    public static function tick(bool $force = false, int $budget = 240): array
    {
        $lock = fopen(data_dir() . '/tick.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['skipped' => 'un passage est déjà en cours'];

        $start = microtime(true);
        self::selfUpdate();
        // Après une mise à jour, la liste des sources de départ est resynchronisée toute seule.
        $sig = md5_file(__DIR__ . '/sources.sites.php') . md5_file(__DIR__ . '/sources.facebook.php');
        if (setting('seed_sig') !== $sig) { Sources::seed(); set_setting('seed_sig', $sig); }
        $force = $force || setting('run_now') === '1';
        if (setting('run_now') === '1') set_setting('run_now', null);
        Db::run('INSERT INTO runs(started_at) VALUES(?)', [now()]);
        $runId = Db::lastId();
        $summary = ['sites' => [], 'new' => 0, 'deals' => 0, 'check' => 0];

        try {
            $due = Db::all("SELECT * FROM sources WHERE kind = 'site' AND enabled = 1 ORDER BY COALESCE(last_run_at, 0) ASC");
            foreach ($due as $src) {
                if (microtime(true) - $start > $budget) break;
                if (!$force && $src['last_run_at'] && $src['last_run_at'] > now() - $src['every_min'] * 60 + 120) continue;
                $r = self::runSite(Sources::decode($src), $start + $budget);
                $summary['sites'][$src['name']] = $r;
                $summary['new'] += $r['new'];
                $summary['deals'] += $r['deals'];
                $summary['check'] += $r['check'];
            }
            if (microtime(true) - $start < $budget) {
                $summary['facebook'] = Apify::tick($force);
                $summary['new'] += $summary['facebook']['new'] ?? 0;
                $summary['deals'] += $summary['facebook']['deals'] ?? 0;
            }
            if (microtime(true) - $start < $budget + 30) $summary['ai'] = Gemini::enrichPending(max(5, (int)($budget + 40 - (microtime(true) - $start))));
            $summary['push'] = Push::notifyNew();
        } catch (Throwable $e) {
            $summary['error'] = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }

        $summary['seconds'] = round(microtime(true) - $start, 1);
        Db::run('UPDATE runs SET ended_at = ?, summary = ? WHERE id = ?', [now(), json_encode($summary, JSON_UNESCAPED_UNICODE), $runId]);
        Db::run('DELETE FROM runs WHERE id < ?', [$runId - 400]);
        Db::run('DELETE FROM seen_urls WHERE seen_at < ?', [now() - 240 * 86400]);
        flock($lock, LOCK_UN);
        return $summary;
    }

    /**
     * Récupère la dernière version depuis GitHub (au plus une fois par heure), sans intervention.
     * Sans effet si l'hébergement n'autorise pas les commandes système.
     */
    private static function selfUpdate(): void
    {
        if (!is_dir(ROOT . '/.git') || (int)setting('self_update_at', '0') > now() - 3600) return;
        set_setting('self_update_at', (string)now());
        if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            set_setting('self_update', 'commandes système désactivées');
            return;
        }
        $before = trim((string)@file_get_contents(ROOT . '/.git/refs/heads/main'));
        $out = (string)shell_exec('cd ' . escapeshellarg(ROOT) . ' && git pull --ff-only -q origin main 2>&1');
        $after = trim((string)@file_get_contents(ROOT . '/.git/refs/heads/main'));
        set_setting('self_update', date('d/m H:i') . ' ' . substr($after, 0, 7) . ($before !== $after ? ' (mis à jour)' : '') . ($out !== '' ? ' · ' . Text::cut(trim($out), 160) : ''));
    }

    /** Lit les pages de liste d'un site, puis les fiches jamais vues. */
    public static function runSite(array $src, float $deadline): array
    {
        $cfg = $src['cfg_arr'];
        $res = ['links' => 0, 'fetched' => 0, 'new' => 0, 'deals' => 0, 'check' => 0, 'error' => null];
        $listUrls = [];
        foreach ($cfg['lists'] ?? [$src['url']] as $u) {
            $listUrls[] = $u;
            for ($p = 2; $p <= (int)($cfg['pages'] ?? 1); $p++) {
                if (!empty($cfg['page_param'])) {
                    $pp = $cfg['page_param'];
                    $v = $p + (int)($cfg['page_offset'] ?? 0);
                    $base = (string)preg_replace('~([?&])' . preg_quote($pp, '~') . '=\d+&?~', '$1', $u);
                    $base = rtrim($base, '?&');
                    $listUrls[] = str_contains($pp, '%d') ? rtrim($u, '/') . sprintf($pp, $v) : $base . (str_contains($base, '?') ? '&' : '?') . $pp . '=' . $v;
                }
            }
        }

        $links = [];
        $errors = [];
        foreach (Http::many($listUrls, 3) as $u => $r) {
            if ($r['code'] !== 200 || $r['body'] === '') {
                $errors[] = 'HTTP ' . $r['code'] . ($r['error'] ? ' ' . $r['error'] : '');
                continue;
            }
            foreach (Extract::links($r['body'], $u, $cfg['link'] ?? '.', $cfg['exclude'] ?? '') as $l) $links[$l] = true;
        }
        $links = array_keys($links);
        $res['links'] = count($links);

        if (!$links) {
            $res['error'] = $errors ? 'liste inaccessible (' . implode(', ', array_unique($errors)) . ')' : 'aucune annonce trouvée dans la liste (le site a peut-être changé)';
            Db::run('UPDATE sources SET last_run_at = ?, last_count = 0, last_new = 0, last_error = ?, fail_streak = fail_streak + 1 WHERE id = ?', [now(), $res['error'], $src['id']]);
            return $res;
        }

        $todo = [];
        foreach ($links as $l) {
            if (count($todo) >= self::MAX_DETAILS_PER_SOURCE) break;
            if (Db::val('SELECT 1 FROM seen_urls WHERE url = ?', [$l])) continue;
            // L'adresse nomme une ville hors du Nord : inutile d'ouvrir la fiche.
            if (Geo::detect(str_replace(['-', '_', '/', '.'], ' ', urldecode((string)parse_url($l, PHP_URL_PATH))))['group'] === 'hors') {
                Db::run('INSERT OR REPLACE INTO seen_urls(url, source_id, seen_at, ok) VALUES(?,?,?,0)', [$l, $src['id'], now()]);
                $res['skipped'] = ($res['skipped'] ?? 0) + 1;
                continue;
            }
            $todo[] = $l;
        }

        foreach (array_chunk($todo, 4) as $chunk) {
            if (microtime(true) > $deadline) break;
            foreach (Http::many($chunk, 4) as $u => $r) {
                $ok = $r['code'] === 200 && strlen($r['body']) > 500;
                // Une erreur passagère sera retentée au prochain passage ; une page disparue, non.
                if ($ok || in_array($r['code'], [404, 410], true)) {
                    Db::run('INSERT OR REPLACE INTO seen_urls(url, source_id, seen_at, ok) VALUES(?,?,?,?)', [$u, $src['id'], now(), $ok ? 1 : 0]);
                }
                if (!$ok) continue;
                $res['fetched']++;
                try {
                    $raw = Extract::fromHtml($r['body'], $u);
                    $in = Pipeline::ingest($raw, $src);
                } catch (Throwable $e) {
                    $res['error'] = 'lecture impossible : ' . $e->getMessage();
                    continue;
                }
                if (!$in) continue;
                if ($in['new']) $res['new']++;
                if ($in['new'] && $in['bucket'] === 'deal') $res['deals']++;
                if ($in['new'] && $in['bucket'] === 'check') $res['check']++;
            }
        }

        Db::run('UPDATE sources SET last_run_at = ?, last_ok_at = ?, last_count = ?, last_new = ?, total = total + ?, matched = matched + ?, last_error = ?, fail_streak = 0 WHERE id = ?',
            [now(), now(), $res['links'], $res['new'], $res['new'], $res['deals'] + $res['check'], $res['error'], $src['id']]);
        return $res;
    }

    /** Ajout à la main (partage depuis Facebook, lien collé, texte copié). */
    public static function addManual(string $url, string $text): ?array
    {
        $url = trim($url);
        $text = trim($text);
        if ($url === '' && preg_match('~https?://\S+~', $text, $m)) {
            $url = rtrim($m[0], '.,)');
            $text = trim(str_replace($m[0], '', $text));
        }
        $src = ['id' => null, 'name' => 'Ajout manuel', 'kind' => 'manual', 'cfg_arr' => []];
        $isFb = (bool)preg_match('~(facebook\.com|fb\.watch|fb\.me|fb\.com|instagram\.com)~i', $url);
        if ($isFb) $src['name'] = 'Facebook (partagé)';
        $raw = ['url' => $url ? Http::tidy($url) : '', 'title' => '', 'text' => $text];
        if ($url !== '' && !$isFb) {
            $r = Http::get($url);
            if ($r['code'] === 200 && strlen($r['body']) > 500) {
                $raw = Extract::fromHtml($r['body'], Http::tidy($url));
                if ($text !== '') $raw['text'] = $text . "\n\n" . $raw['text'];
                $src['name'] = (string)preg_replace('~^www\.~', '', (string)parse_url($url, PHP_URL_HOST));
            }
        }
        if (($raw['title'] ?? '') === '' && ($raw['text'] ?? '') === '') {
            $raw['title'] = $isFb ? 'Annonce Facebook à compléter' : 'Annonce à compléter';
            $raw['text'] = 'Lien enregistré sans description. Ouvre l\'annonce puis colle son texte ici pour qu\'elle soit analysée.';
        }
        $in = Pipeline::ingest($raw, $src);
        if ($in) {
            // Ce que tu ajoutes toi-même va au moins dans « À vérifier », et en favori.
            Db::run("UPDATE listings SET status = 'fav', seen = 1, bucket = CASE WHEN bucket = 'none' THEN 'check' ELSE bucket END WHERE id = ?", [$in['id']]);
        }
        return $in;
    }
}
