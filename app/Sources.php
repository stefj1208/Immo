<?php
declare(strict_types=1);

/** Sources de départ. Tout se modifie ensuite depuis l'application. */
final class Sources
{
    /** Sites : pages de liste à parcourir et motif des liens de fiche. */
    public static function sites(): array
    {
        return require __DIR__ . '/sources.sites.php';
    }

    public static function facebook(): array
    {
        return require __DIR__ . '/sources.facebook.php';
    }

    /** Ajoute les sources manquantes sans toucher à celles déjà réglées. */
    public static function seed(): int
    {
        $n = 0;
        foreach (self::sites() as $s) {
            $cfg = ['lists' => $s['lists'], 'link' => $s['link'], 'exclude' => $s['exclude'] ?? '', 'seller' => $s['seller'] ?? null, 'pages' => $s['pages'] ?? 1, 'page_param' => $s['page_param'] ?? null, 'page_offset' => $s['page_offset'] ?? 0];
            $n += self::upsert('site', $s['name'], $s['lists'][0], $cfg, $s['every'] ?? 60, $s['enabled'] ?? 1, true);
        }
        // Sites retirés ou désactivés dans la liste de départ (ceux que tu as ajoutés toi-même ne bougent pas).
        $names = array_column(self::sites(), 'name');
        foreach (Db::all("SELECT id, name FROM sources WHERE kind = 'site'") as $row) {
            if (setting('src_lock_' . $row['id']) === '1') continue;
            if (!in_array($row['name'], $names, true)) Db::run('DELETE FROM sources WHERE id = ?', [$row['id']]);
        }
        foreach (self::sites() as $s) {
            if (($s['enabled'] ?? 1) === 0) Db::run("UPDATE sources SET enabled = 0 WHERE kind = 'site' AND name = ? AND last_ok_at IS NULL", [$s['name']]);
        }
        foreach (self::facebook() as $f) {
            $n += self::upsert($f['kind'], $f['name'], $f['url'], ['tier' => $f['tier'] ?? 2], 480, 1, false);
        }
        return $n;
    }

    public static function upsert(string $kind, string $name, string $url, array $cfg, int $every, int $enabled, bool $refreshCfg): int
    {
        $row = Db::row('SELECT id FROM sources WHERE kind = ? AND name = ?', [$kind, $name]);
        if ($row) {
            if ($refreshCfg && setting('src_lock_' . $row['id']) !== '1') {
                Db::run('UPDATE sources SET url = ?, cfg = ? WHERE id = ?', [$url, json_encode($cfg, JSON_UNESCAPED_SLASHES), $row['id']]);
            }
            return 0;
        }
        Db::run('INSERT INTO sources(kind, name, url, cfg, enabled, every_min, created_at) VALUES(?,?,?,?,?,?,?)',
            [$kind, $name, $url, json_encode($cfg, JSON_UNESCAPED_SLASHES), $enabled, $every, now()]);
        return 1;
    }

    public static function decode(array $row): array
    {
        $row['cfg_arr'] = json_decode((string)$row['cfg'], true) ?: [];
        return $row;
    }
}
