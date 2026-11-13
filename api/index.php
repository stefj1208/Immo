<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }

try {
    Db::pdo();
} catch (Throwable $e) {
    fail('Base de données indisponible : ' . $e->getMessage(), 500);
}

$a = (string)($_GET['a'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    if (($_SERVER['HTTP_X_IMMO'] ?? '') !== '1') fail('Requête refusée.', 403);
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
}

// ------------------------------------------------------------------ accès

function session_ok(): bool
{
    $t = $_COOKIE['immo_s'] ?? '';
    if (strlen($t) < 32) return false;
    $h = hash('sha256', $t);
    $row = Db::row('SELECT last_seen FROM sessions WHERE token_hash = ?', [$h]);
    if (!$row) return false;
    if ($row['last_seen'] < now() - 3600) Db::run('UPDATE sessions SET last_seen = ? WHERE token_hash = ?', [now(), $h]);
    return true;
}

if ($a === 'login') {
    $win = setting_json('login_guard', ['n' => 0, 't' => 0]);
    if ($win['t'] < now() - 900) $win = ['n' => 0, 't' => now()];
    if ($win['n'] >= 8) fail('Trop d\'essais. Réessaie dans un quart d\'heure.', 429);
    $key = strtolower(trim((string)($in['key'] ?? '')));
    $hash = (string)setting('access_hash', '');
    if ($hash === '' || !password_verify($key, $hash)) {
        $win['n']++;
        set_setting('login_guard', json_encode($win));
        usleep(400000);
        fail('Clé d\'accès incorrecte.', 401);
    }
    $token = bin2hex(random_bytes(32));
    Db::run('INSERT INTO sessions(token_hash, created_at, last_seen, label) VALUES(?,?,?,?)',
        [hash('sha256', $token), now(), now(), Text::cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 120)]);
    setcookie('immo_s', $token, ['expires' => now() + 400 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    out(['ok' => true]);
}

if (!session_ok()) fail('Connexion requise.', 401);

if ($a === 'logout') {
    Db::run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', (string)$_COOKIE['immo_s'])]);
    setcookie('immo_s', '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    out(['ok' => true]);
}

// ---------------------------------------------------------------- annonces

function present(array $r, bool $full = false): array
{
    $o = [
        'id' => (int)$r['id'], 'title' => $r['title'], 'price' => $r['price'] ? (int)$r['price'] : null,
        'price_note' => $r['price_note'], 'price_prev' => $r['price_prev'] ? (int)$r['price_prev'] : null,
        'kind' => $r['kind'], 'zone' => $r['zone'], 'beds' => $r['beds'] !== null ? (int)$r['beds'] : null,
        'land_m2' => $r['land_m2'] ? (int)$r['land_m2'] : null, 'built_m2' => $r['built_m2'] ? (int)$r['built_m2'] : null,
        'beach' => $r['beach'], 'seller' => $r['seller'], 'profile' => $r['profile'], 'bucket' => $r['bucket'],
        'score' => (int)$r['score'], 'reasons' => json_decode((string)$r['reasons'], true) ?: [],
        'flags' => json_decode((string)$r['flags'], true) ?: [],
        'status' => $r['status'], 'seen' => (int)$r['seen'], 'source' => $r['source_name'], 'source_kind' => $r['source_kind'],
        'photo' => (json_decode((string)$r['photos'], true) ?: [])[0] ?? null,
        'first_seen' => (int)$r['first_seen'], 'posted_at' => $r['posted_at'] ? (int)$r['posted_at'] : null,
        'has_phone' => (bool)$r['phone'], 'note' => $r['note'],
    ];
    if ($full) {
        $o['text'] = $r['text'];
        $o['url'] = $r['url'];
        $o['phone'] = $r['phone'];
        $o['author'] = $r['author'];
        $o['photos'] = json_decode((string)$r['photos'], true) ?: [];
        $o['ai'] = $r['ai_summary'] ? json_decode((string)$r['ai_summary'], true) : null;
        $o['others'] = Db::all('SELECT source_name AS source, url, price FROM listings WHERE dup_of = ? ORDER BY first_seen', [$r['id']]);
    }
    return $o;
}

switch ($a) {
    case 'list': {
        $tab = (string)($_GET['tab'] ?? 'deal');
        $profile = (string)($_GET['profile'] ?? '');
        $q = trim((string)($_GET['q'] ?? ''));
        $where = ['dup_of IS NULL'];
        $args = [];
        switch ($tab) {
            case 'deal': $where[] = "bucket = 'deal' AND status = 'new'"; break;
            case 'check': $where[] = "bucket = 'check' AND status = 'new'"; break;
            case 'follow': $where[] = "status IN ('fav', 'contacted', 'visited')"; break;
            case 'rejected': $where[] = "status = 'rejected'"; break;
            case 'other': $where[] = "bucket = 'none' AND status = 'new' AND kind IN ('appartement', 'maison', 'terrain', 'terrain_res', 'terrain_agri') AND zone_group IN ('cote', 'nord', 'nord?')"; break;
            default: $where[] = "bucket IN ('deal', 'check')";
        }
        if ($profile !== '' && isset(Classify::DEFAULT_PROFILES[$profile])) { $where[] = 'profile = ?'; $args[] = $profile; }
        if ($q !== '') {
            $where[] = '(title LIKE ? OR text LIKE ? OR zone LIKE ?)';
            array_push($args, "%$q%", "%$q%", "%$q%");
        }
        $order = $tab === 'follow' ? 'last_seen DESC' : (($_GET['sort'] ?? '') === 'recent' ? 'first_seen DESC' : 'seen ASC, score DESC, first_seen DESC');
        $rows = Db::all('SELECT * FROM listings WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 150", $args);

        $counts = ['deal' => 0, 'check' => 0, 'follow' => 0, 'deal_new' => 0];
        foreach (Db::all("SELECT bucket, status, seen, COUNT(*) n FROM listings WHERE dup_of IS NULL GROUP BY 1, 2, 3") as $c) {
            if (in_array($c['status'], ['fav', 'contacted', 'visited'], true)) $counts['follow'] += $c['n'];
            elseif ($c['status'] === 'new' && isset($counts[$c['bucket']])) {
                $counts[$c['bucket']] += $c['n'];
                if ($c['bucket'] === 'deal' && !$c['seen']) $counts['deal_new'] += $c['n'];
            }
        }
        $byProfile = [];
        if (in_array($tab, ['deal', 'check'], true)) {
            foreach (Db::all("SELECT profile, COUNT(*) n FROM listings WHERE dup_of IS NULL AND bucket = ? AND status = 'new' GROUP BY 1", [$tab]) as $c) $byProfile[$c['profile']] = (int)$c['n'];
        }
        $last = Db::row('SELECT started_at, ended_at FROM runs WHERE ended_at IS NOT NULL ORDER BY id DESC LIMIT 1');
        out([
            'items' => array_map('present', $rows), 'counts' => $counts, 'by_profile' => $byProfile,
            'profiles' => Classify::profiles(), 'last_run' => $last ? (int)$last['ended_at'] : null,
            'facebook' => Apify::ready(), 'total' => (int)Db::val('SELECT COUNT(*) FROM listings'),
        ]);
    }

    case 'get': {
        $r = Db::row('SELECT * FROM listings WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
        if (!$r) fail('Annonce introuvable.', 404);
        if (!$r['seen']) Db::run('UPDATE listings SET seen = 1 WHERE id = ?', [$r['id']]);
        out(present($r, true));
    }

    case 'mark': {
        $id = (int)($in['id'] ?? 0);
        $set = [];
        $args = [];
        if (isset($in['status']) && in_array($in['status'], ['new', 'fav', 'contacted', 'visited', 'rejected'], true)) { $set[] = 'status = ?'; $args[] = $in['status']; $set[] = 'seen = 1'; }
        if (array_key_exists('note', $in)) { $set[] = 'note = ?'; $args[] = Text::cut(trim((string)$in['note']), 1500) ?: null; }
        if (!$set) fail('Rien à modifier.');
        $args[] = $id;
        Db::run('UPDATE listings SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        out(['ok' => true]);
    }

    case 'seen': {
        $ids = array_slice(array_map('intval', (array)($in['ids'] ?? [])), 0, 300);
        if ($ids) Db::run('UPDATE listings SET seen = 1 WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        out(['ok' => true]);
    }

    case 'add': {
        $res = Collector::addManual((string)($in['url'] ?? ''), (string)($in['text'] ?? ''));
        if (!$res) fail('Rien à enregistrer : colle un lien ou le texte de l\'annonce.');
        out(['ok' => true, 'id' => $res['id'], 'new' => $res['new']]);
    }

    case 'complete': { // ajouter le texte d'une annonce enregistrée par simple lien
        $r = Db::row('SELECT * FROM listings WHERE id = ?', [(int)($in['id'] ?? 0)]);
        $text = trim((string)($in['text'] ?? ''));
        if (!$r || mb_strlen($text) < 15) fail('Colle le texte de l\'annonce.');
        Db::run('DELETE FROM listings WHERE id = ?', [$r['id']]);
        $res = Collector::addManual((string)$r['url'], $text);
        out(['ok' => true, 'id' => $res['id'] ?? null]);
    }

    // ------------------------------------------------------------- sources
    case 'sources': {
        $rows = Db::all('SELECT id, kind, name, url, enabled, last_ok_at, last_run_at, last_count, last_new, total, matched, last_error, fail_streak FROM sources ORDER BY kind, name');
        out(['items' => $rows, 'apify' => Apify::status(), 'gemini' => ['ready' => Gemini::ready(), 'error' => setting('gemini_error'), 'model' => setting('gemini_model')]]);
    }

    case 'source_save': {
        if (!empty($in['id'])) {
            $id = (int)$in['id'];
            if (!empty($in['delete'])) { Db::run('DELETE FROM sources WHERE id = ?', [$id]); out(['ok' => true]); }
            if (isset($in['enabled'])) Db::run('UPDATE sources SET enabled = ? WHERE id = ?', [(int)(bool)$in['enabled'], $id]);
            out(['ok' => true]);
        }
        $url = trim((string)($in['url'] ?? ''));
        if (!preg_match('~^https?://[^\s]+$~i', $url)) fail('Adresse invalide.');
        $name = Text::cut(trim((string)($in['name'] ?? '')), 80);
        if (preg_match('~facebook\.com/groups/([^/?#]+)~i', $url, $m)) {
            $kind = 'fb_group';
            $url = 'https://www.facebook.com/groups/' . $m[1];
            $cfg = ['tier' => 1];
        } elseif (preg_match('~facebook\.com/~i', $url)) {
            $kind = 'fb_page';
            $url = Http::tidy($url);
            $cfg = ['tier' => 1];
        } else {
            $kind = 'site';
            $r = Http::get($url);
            if ($r['code'] !== 200) fail('Cette page ne répond pas (HTTP ' . $r['code'] . ').');
            $guess = guess_link_pattern($r['body'], $url);
            if (!$guess) fail('Je ne trouve pas de liste d\'annonces sur cette page. Donne l\'adresse de la page qui liste les biens à vendre.');
            $cfg = ['lists' => [$url], 'link' => $guess['regex'], 'exclude' => '', 'pages' => 1];
            if ($name === '') $name = (string)preg_replace('~^www\.~', '', (string)parse_url($url, PHP_URL_HOST));
        }
        if ($name === '') $name = $kind === 'fb_group' ? 'Groupe ' . basename($url) : 'Page ' . basename($url);
        if (Db::val('SELECT 1 FROM sources WHERE kind = ? AND url = ?', [$kind, $url])) fail('Cette source est déjà suivie.');
        Db::run('INSERT INTO sources(kind, name, url, cfg, enabled, every_min, created_at) VALUES(?,?,?,?,1,?,?)',
            [$kind, $name, $url, json_encode($cfg, JSON_UNESCAPED_SLASHES), $kind === 'site' ? 45 : 480, now()]);
        $id = Db::lastId();
        setting('src_lock_' . $id) ?? set_setting('src_lock_' . $id, '1');
        out(['ok' => true, 'id' => $id, 'kind' => $kind, 'found' => $guess['count'] ?? null]);
    }

    // ------------------------------------------------------------ réglages
    case 'settings': {
        if ($method === 'POST') {
            foreach (['gemini_key', 'apify_token'] as $k) {
                if (array_key_exists($k, $in)) set_setting($k, trim((string)$in[$k]) ?: null);
            }
            if (isset($in['profiles']) && is_array($in['profiles'])) {
                $p = [];
                foreach (Classify::DEFAULT_PROFILES as $id => $_) {
                    if (isset($in['profiles'][$id])) $p[$id] = ['max' => max(100000, min(100000000, (int)$in['profiles'][$id]))];
                }
                set_setting('profiles', json_encode($p));
                Pipeline::rescoreAll();
            }
            if (isset($in['notify_min'])) set_setting('notify_min', (string)max(30, min(95, (int)$in['notify_min'])));
            if (isset($in['quiet_hours']) && preg_match('~^\d{1,2}-\d{1,2}$~', (string)$in['quiet_hours'])) set_setting('quiet_hours', (string)$in['quiet_hours']);
            if (isset($in['apify_month_cap'])) set_setting('apify_month_cap', (string)max(0, min(100000, (int)$in['apify_month_cap'])));
            if (isset($in['fb_keywords'])) set_setting('fb_keywords', Text::cut(trim((string)$in['fb_keywords']), 4000) ?: null);
            if (!empty($in['gemini_key'])) { set_setting('gemini_error', null); Db::run('UPDATE listings SET ai_done = 0 WHERE ai_done = 2'); }
        }
        out([
            'profiles' => Classify::profiles(),
            'gemini' => ['set' => Gemini::ready(), 'error' => setting('gemini_error'), 'model' => setting('gemini_model')],
            'apify' => Apify::status() + ['set' => Apify::ready()],
            'notify_min' => (int)setting('notify_min', '62'),
            'quiet_hours' => setting('quiet_hours', '22-7'),
            'fb_keywords' => implode("\n", Apify::keywords()),
            'push_devices' => (int)Db::val('SELECT COUNT(*) FROM push_subs'),
            'push_error' => setting('push_error'),
            'version' => APP_VERSION,
        ]);
    }

    case 'status': {
        $runs = [];
        foreach (Db::all('SELECT started_at, ended_at, summary FROM runs ORDER BY id DESC LIMIT 8') as $r) {
            $s = json_decode((string)$r['summary'], true) ?: [];
            $runs[] = ['at' => (int)$r['started_at'], 'seconds' => $s['seconds'] ?? null, 'new' => $s['new'] ?? 0, 'deals' => $s['deals'] ?? 0, 'error' => $s['error'] ?? null];
        }
        out(['runs' => $runs, 'broken' => Db::all("SELECT name, last_error FROM sources WHERE enabled = 1 AND kind = 'site' AND fail_streak >= 3")]);
    }

    case 'run': {
        set_setting('run_now', '1');
        out(['ok' => true]);
    }

    // -------------------------------------------------------- notifications
    case 'push_key': out(['key' => Push::keys()['pub']]);

    case 'push_sub': {
        $ep = (string)($in['endpoint'] ?? '');
        if (!preg_match('~^https://[a-z0-9.-]+\.(googleapis\.com|mozilla\.com|windows\.com|apple\.com|push\.services\.mozilla\.com)/~i', $ep)) fail('Abonnement refusé.');
        Db::run('INSERT OR IGNORE INTO push_subs(endpoint, created_at) VALUES(?, ?)', [$ep, now()]);
        out(['ok' => true]);
    }

    case 'push_unsub': {
        Db::run('DELETE FROM push_subs WHERE endpoint = ?', [(string)($in['endpoint'] ?? '')]);
        out(['ok' => true]);
    }

    case 'push_test': {
        set_setting('push_test', '1');
        out(['sent_to' => Push::ping(), 'error' => setting('push_error')]);
    }

    case 'push_pending': {
        if (setting('push_test') === '1') {
            set_setting('push_test', null);
            out([['title' => 'Les notifications fonctionnent', 'body' => 'Tu seras prévenu dès qu\'un bon plan apparaît.', 'tag' => 'test', 'url' => '/#/']]);
        }
        out(Push::pending());
    }

    default:
        fail('Action inconnue.', 404);
}

/** Devine le motif des fiches d'annonce sur une page de liste : la forme d'adresse la plus répétée. */
function guess_link_pattern(string $html, string $base): ?array
{
    $host = parse_url($base, PHP_URL_HOST);
    $shapes = [];
    if (!preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m)) return null;
    foreach (array_unique($m[2]) as $h) {
        $abs = Http::absolute($h, $base);
        if (!$abs || parse_url($abs, PHP_URL_HOST) !== $host) continue;
        $path = (string)parse_url($abs, PHP_URL_PATH);
        if (substr_count(trim($path, '/'), '/') < 1 && !preg_match('~\d{3,}~', $path)) continue;
        if (!preg_match('~\d{3,}|[a-z0-9]+(-[a-z0-9]+){3,}~i', $path)) continue;
        $parts = explode('/', trim($path, '/'));
        $last = array_pop($parts);
        $shape = implode('/', $parts);
        $shapes[$shape][] = $abs;
    }
    if (!$shapes) return null;
    uasort($shapes, fn($x, $y) => count($y) <=> count($x));
    $shape = array_key_first($shapes);
    if (count($shapes[$shape]) < 4) return null;
    $prefix = preg_quote((string)$host, '~') . '/' . ($shape !== '' ? preg_quote($shape, '~') . '/' : '');
    return ['regex' => $prefix . '[^/?#]+/?$', 'count' => count($shapes[$shape])];
}
