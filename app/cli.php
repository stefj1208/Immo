<?php
declare(strict_types=1);

// Ligne de commande : php app/cli.php <commande>
//   install            prépare la base, les sources et la clé d'accès
//   tick [--force]     un passage de collecte (lancé par la tâche planifiée)
//   status             état des sources et des derniers passages
//   probe <url> [motif]  structure d'une page, pour régler une source
//   try <url>          lit une fiche d'annonce et montre ce qui en est compris
//   key                crée une nouvelle clé d'accès
//   rescore            recalcule toutes les notes

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';

$cmd = $argv[1] ?? 'help';
$arg = array_slice($argv, 2);

function new_access_key(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $key = '';
    for ($i = 0; $i < 20; $i++) $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    $key = implode('-', str_split($key, 5));
    set_setting('access_hash', password_hash($key, PASSWORD_DEFAULT));
    Db::run('DELETE FROM sessions');
    return $key;
}

switch ($cmd) {
    case 'install':
        Db::pdo();
        $n = Sources::seed();
        Push::keys();
        echo "Données : " . data_dir() . "\n";
        echo "Sources ajoutées : $n (total " . Db::val('SELECT COUNT(*) FROM sources') . ")\n";
        if (!setting('access_hash') || in_array('--new-key', $arg, true)) echo "CLE D'ACCES : " . new_access_key() . "\n";
        else echo "Clé d'accès : déjà définie (php app/cli.php key pour en créer une autre)\n";
        break;

    case 'key':
        if (!empty($arg[0]) && strlen($arg[0]) >= 16) {   // clé fournie : enregistrée telle quelle
            set_setting('access_hash', password_hash($arg[0], PASSWORD_DEFAULT));
            Db::run('DELETE FROM sessions');
            echo "Clé d'accès enregistrée.\n";
        } else {
            echo "CLE D'ACCES : " . new_access_key() . "\n";
        }
        break;

    case 'probe-file':
        $lines = array_filter(array_map('trim', file($arg[0] ?? (__DIR__ . '/probe.txt')) ?: []), fn($l) => $l !== '' && $l[0] !== '#');
        $urls = [];
        $rx = [];
        foreach ($lines as $l) { [$u, $r] = array_pad(preg_split('/\s+/', $l, 2), 2, null); $urls[] = $u; $rx[$u] = $r; }
        foreach (Http::many($urls, 6, ['timeout' => 20]) as $u => $r) {
            echo "\n== $u -> HTTP {$r['code']} {$r['error']} (" . strlen($r['body']) . " o)\n";
            if ($r['code'] !== 200 || $r['body'] === '') { echo '  ' . Text::cut(trim(preg_replace('/\s+/', ' ', strip_tags($r['body']))), 160) . "\n"; continue; }
            if (($rx[$u] ?? '') === 'try') {
                $raw = Extract::fromHtml($r['body'], $u);
                $k = Classify::kind($raw['title'], $raw['text'], $u);
                $g = Geo::detect($raw['title'] . ' ' . $raw['location'] . ' ' . str_replace(['-', '/'], ' ', (string)parse_url($u, PHP_URL_PATH)), mb_substr($raw['text'], 0, 1500));
                $ar = Text::areas($raw['title'] . "\n" . mb_substr($raw['text'], 0, 2500), $k);
                echo "  titre: {$raw['title']}\n  nature: $k · zone: " . json_encode($g, JSON_UNESCAPED_UNICODE) . ' · prix: ' . json_encode($raw['price']) . ' · surfaces: ' . json_encode($ar) . " · ch: {$raw['beds']} · tel: {$raw['phone']} · photos: " . count($raw['photos']) . "\n  texte: " . Text::cut(preg_replace('/\s+/', ' ', $raw['text']), 260) . "\n";
                continue;
            }
            $p = Extract::probe($r['body'], $u);
            echo 'titre « ' . Text::cut($p['title'], 70) . ' » · liens ' . $p['links_total'] . ' · JSON-LD ' . json_encode($p['jsonld_types']) . "\n";
            echo implode("\n", array_slice($p['shapes'], 0, 7)) . "\n";
            $nav = [];
            if (preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1~is', $r['body'], $m)) {
                foreach ($m[2] as $h) {
                    $a = Http::absolute($h, $u);
                    if ($a && preg_match('~(vente|vendre|sale|acheter|achat|buy|terrain|land|annonce|propert|bien)~i', $a)) $nav[preg_replace('~\d+~', 'N', $a)] = $a;
                }
            }
            echo '  nav: ' . implode("\n  nav: ", array_slice(array_values($nav), 0, 9)) . "\n";
            if (!empty($rx[$u])) {
                $links = Extract::links($r['body'], $u, $rx[$u]);
                echo '  motif « ' . $rx[$u] . ' » : ' . count($links) . ' liens · ' . implode(' · ', array_slice($links, 0, 2)) . "\n";
            }
        }
        break;

    case 'tick':
        $s = Collector::tick(in_array('--force', $arg, true), (int)(getenv('IMMO_BUDGET') ?: 240));
        echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        break;

    case 'status':
        echo 'PHP ' . PHP_VERSION . ' · données ' . data_dir() . ' · ' . date('d/m H:i') . " (Maurice)\n";
        foreach (Db::all("SELECT name, kind, enabled, last_count, last_new, total, matched, last_error, last_ok_at FROM sources WHERE kind = 'site' ORDER BY name") as $s) {
            printf("%-34s liens %-3d nouv. %-3d total %-4d retenues %-3d %s %s\n", mb_substr($s['name'], 0, 34), $s['last_count'], $s['last_new'], $s['total'], $s['matched'],
                $s['last_ok_at'] ? date('d/m H:i', (int)$s['last_ok_at']) : 'jamais', $s['last_error'] ? '⚠ ' . $s['last_error'] : '');
        }
        echo 'Annonces : ' . Db::val('SELECT COUNT(*) FROM listings') . ' · bons plans ' . Db::val("SELECT COUNT(*) FROM listings WHERE bucket = 'deal' AND dup_of IS NULL")
            . ' · à vérifier ' . Db::val("SELECT COUNT(*) FROM listings WHERE bucket = 'check' AND dup_of IS NULL") . "\n";
        foreach (Db::all('SELECT kind, zone_group, bucket, COUNT(*) n FROM listings GROUP BY 1, 2, 3 ORDER BY n DESC LIMIT 25') as $r) {
            echo "  {$r['kind']} / " . ($r['zone_group'] ?? '?') . " / {$r['bucket']} : {$r['n']}\n";
        }
        $last = Db::row('SELECT * FROM runs ORDER BY id DESC LIMIT 1');
        if ($last) echo 'Dernier passage ' . date('d/m H:i', (int)$last['started_at']) . ' : ' . Text::cut((string)$last['summary'], 900) . "\n";
        break;

    case 'probe':
        foreach (array_filter($arg, fn($a) => str_starts_with($a, 'http')) as $url) {
            $r = Http::get($url);
            echo "== $url\nHTTP {$r['code']} {$r['error']} -> {$r['url']}\n";
            if ($r['body'] === '') continue;
            $p = Extract::probe($r['body'], $r['url'] ?: $url);
            echo "octets {$p['bytes']} · liens {$p['links_total']} · titre « {$p['title']} »\n";
            echo 'JSON-LD : ' . json_encode($p['jsonld_types']) . "\n";
            echo implode("\n", $p['shapes']) . "\n";
            $rx = array_values(array_filter($arg, fn($a) => !str_starts_with($a, 'http')))[0] ?? null;
            if ($rx) {
                $links = Extract::links($r['body'], $r['url'] ?: $url, $rx);
                echo 'motif « ' . $rx . ' » : ' . count($links) . " liens\n" . implode("\n", array_slice($links, 0, 4)) . "\n";
            }
        }
        break;

    case 'try':
        foreach ($arg as $url) {
            $r = Http::get($url);
            echo "== $url HTTP {$r['code']}\n";
            if ($r['code'] !== 200) continue;
            $raw = Extract::fromHtml($r['body'], $url);
            $kind = Classify::kind($raw['title'], $raw['text'], $url);
            $geo = Geo::detect($raw['title'] . ' ' . $raw['location'] . ' ' . str_replace(['-', '/'], ' ', (string)parse_url($url, PHP_URL_PATH)), mb_substr($raw['text'], 0, 1500));
            $raw['text'] = Text::cut($raw['text'], 500);
            echo json_encode(['kind' => $kind, 'geo' => $geo, 'areas' => Text::areas($raw['title'] . "\n" . $raw['text'], $kind)] + $raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        }
        break;

    case 'rescore':
        echo Pipeline::rescoreAll() . " annonces recalculées\n";
        break;

    case 'sample':
        $where = $arg[0] ?? "bucket IN ('deal','check')";
        foreach (Db::all("SELECT id, bucket, score, kind, zone, price, land_m2, built_m2, beach, seller, source_name, title, reasons, flags, url FROM listings WHERE dup_of IS NULL AND $where ORDER BY score DESC LIMIT 40") as $r) {
            echo "#{$r['id']} [{$r['bucket']} {$r['score']}] {$r['kind']} · " . ($r['zone'] ?? '?') . ' · ' . ($r['price'] ? number_format($r['price'] / 1e6, 2) . 'M' : 'prix ?')
                . " · T{$r['land_m2']} H{$r['built_m2']} · {$r['seller']} · {$r['source_name']}\n   " . Text::cut((string)$r['title'], 90) . "\n   + {$r['reasons']} / ! {$r['flags']}\n   {$r['url']}\n";
        }
        break;

    default:
        echo "Commandes : install · tick [--force] · status · probe <url> [motif] · try <url> · key · rescore · sample\n";
}
