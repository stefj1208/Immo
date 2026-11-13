<?php
declare(strict_types=1);

/** Accès SQLite : un seul fichier, pas de serveur ni de mot de passe à gérer. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $pdo = new PDO('sqlite:' . data_dir() . '/immo.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::$pdo = $pdo;
        self::migrate();
        return $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::run($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [])
    {
        $r = self::run($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)');
        $version = (int)($pdo->query("SELECT v FROM settings WHERE k = 'schema'")->fetchColumn() ?: 0);

        if ($version < 1) {
            $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sources (
                id INTEGER PRIMARY KEY,
                kind TEXT NOT NULL,              -- site | fb_group | fb_page | manual | fb_search
                name TEXT NOT NULL,
                url TEXT NOT NULL DEFAULT '',
                cfg TEXT NOT NULL DEFAULT '{}',
                enabled INTEGER NOT NULL DEFAULT 1,
                every_min INTEGER NOT NULL DEFAULT 60,
                last_run_at INTEGER, last_ok_at INTEGER,
                last_count INTEGER DEFAULT 0, last_new INTEGER DEFAULT 0,
                total INTEGER DEFAULT 0, matched INTEGER DEFAULT 0,
                last_error TEXT, fail_streak INTEGER DEFAULT 0,
                created_at INTEGER
            );
            CREATE UNIQUE INDEX IF NOT EXISTS ux_sources ON sources(kind, url, name);

            CREATE TABLE IF NOT EXISTS listings (
                id INTEGER PRIMARY KEY,
                fp TEXT NOT NULL UNIQUE,
                source_id INTEGER, source_name TEXT, source_kind TEXT,
                url TEXT, title TEXT, text TEXT,
                price INTEGER, price_note TEXT, price_prev INTEGER,
                kind TEXT, zone TEXT, zone_group TEXT,
                beds INTEGER, land_m2 INTEGER, built_m2 INTEGER,
                phone TEXT, author TEXT, photos TEXT,
                seller TEXT, beach TEXT,
                profile TEXT, bucket TEXT,         -- bucket: deal | check | none
                score INTEGER DEFAULT 0, reasons TEXT, flags TEXT,
                ai_summary TEXT, ai_done INTEGER DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'new', -- new | fav | contacted | visited | rejected
                seen INTEGER NOT NULL DEFAULT 0,
                note TEXT,
                dup_of INTEGER,
                notified INTEGER NOT NULL DEFAULT 0,
                posted_at INTEGER, first_seen INTEGER, last_seen INTEGER
            );
            CREATE INDEX IF NOT EXISTS ix_l_bucket ON listings(bucket, status, score);
            CREATE INDEX IF NOT EXISTS ix_l_first ON listings(first_seen);
            CREATE INDEX IF NOT EXISTS ix_l_match ON listings(kind, zone, price);
            CREATE INDEX IF NOT EXISTS ix_l_phone ON listings(phone);

            CREATE TABLE IF NOT EXISTS seen_urls (
                url TEXT PRIMARY KEY, source_id INTEGER, seen_at INTEGER, ok INTEGER
            );
            CREATE TABLE IF NOT EXISTS runs (
                id INTEGER PRIMARY KEY, started_at INTEGER, ended_at INTEGER, summary TEXT
            );
            CREATE TABLE IF NOT EXISTS push_subs (
                id INTEGER PRIMARY KEY, endpoint TEXT UNIQUE, created_at INTEGER, last_ok INTEGER, fails INTEGER DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS sessions (
                token_hash TEXT PRIMARY KEY, created_at INTEGER, last_seen INTEGER, label TEXT
            );
            CREATE TABLE IF NOT EXISTS apify_runs (
                run_id TEXT PRIMARY KEY, recipe TEXT, dataset_id TEXT, meta TEXT,
                started_at INTEGER, status TEXT, items INTEGER DEFAULT 0, finished_at INTEGER
            );
            SQL);
            $pdo->exec("INSERT INTO settings(k, v) VALUES('schema', '1') ON CONFLICT(k) DO UPDATE SET v = '1'");
        }
    }
}
