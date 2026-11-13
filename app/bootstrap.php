<?php
declare(strict_types=1);

/**
 * Immo Maurice — amorçage commun (API web et ligne de commande).
 * Les secrets (clé d'accès, clés d'API) vivent dans la base, jamais dans le dépôt.
 */

if (!defined('IMMO')) define('IMMO', 1);

date_default_timezone_set('Indian/Mauritius');
mb_internal_encoding('UTF-8');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

define('ROOT', dirname(__DIR__));
define('APP_VERSION', '2.0.0');

/** Dossier des données : hors de la racine web quand c'est possible. */
function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $candidates = [];
    if ($env = getenv('IMMO_DATA')) $candidates[] = $env;
    for ($p = ROOT; $p !== '/' && $p !== '.' && $p !== ''; $p = dirname($p)) {
        if (basename($p) === 'public_html') {
            $candidates[] = dirname($p) . '/immo-data';
            break;
        }
    }
    $candidates[] = ROOT . '/data';

    foreach ($candidates as $c) {
        if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) return $dir = $c;
    }
    throw new RuntimeException('Aucun dossier de données inscriptible.');
}

foreach (['Db', 'Text', 'Geo', 'Http', 'Extract', 'Classify', 'Sources', 'Pipeline',
          'Collector', 'Gemini', 'Apify', 'Push'] as $class) {
    require_once __DIR__ . "/$class.php";
}

function now(): int { return time(); }

/** Lecture / écriture d'un réglage. */
function setting(string $key, ?string $default = null): ?string
{
    $v = Db::val('SELECT v FROM settings WHERE k = ?', [$key]);
    return $v === null || $v === false ? $default : (string)$v;
}

function set_setting(string $key, ?string $value): void
{
    if ($value === null) {
        Db::run('DELETE FROM settings WHERE k = ?', [$key]);
        return;
    }
    Db::run('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$key, $value]);
}

function setting_json(string $key, array $default = []): array
{
    $v = setting($key);
    if ($v === null) return $default;
    $d = json_decode($v, true);
    return is_array($d) ? $d : $default;
}

function logline(string $msg): void
{
    if (PHP_SAPI === 'cli') echo $msg, "\n";
}
