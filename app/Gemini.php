<?php
declare(strict_types=1);

/**
 * Affinage par Gemini (clé gratuite Google AI Studio), facultatif.
 * Sans clé, l'application fonctionne entièrement avec ses règles.
 */
final class Gemini
{
    private const FALLBACK_MODELS = ['gemini-flash-lite-latest', 'gemini-flash-latest', 'gemini-2.5-flash-lite', 'gemini-2.5-flash'];

    public static function ready(): bool
    {
        return (string)setting('gemini_key', '') !== '';
    }

    /** @return array|null réponse JSON décodée */
    public static function ask(string $prompt): ?array
    {
        $key = (string)setting('gemini_key', '');
        if ($key === '') return null;
        $models = array_values(array_unique(array_filter(array_merge([setting('gemini_model')], self::FALLBACK_MODELS))));
        foreach ($models as $model) {
            $r = Http::json("https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent", [
                'headers' => ['x-goog-api-key: ' . $key],
                'timeout' => 40,
                'body' => [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 700],
                ],
            ]);
            if ($r['code'] === 404 || ($r['code'] === 400 && str_contains($r['body'], 'not found'))) continue; // modèle retiré : on essaie le suivant
            if ($r['code'] !== 200) {
                set_setting('gemini_error', 'HTTP ' . $r['code'] . ' ' . Text::cut((string)($r['data']['error']['message'] ?? $r['error'] ?? ''), 160) . ' (' . date('d/m H:i') . ')');
                return null;
            }
            if (setting('gemini_model') !== $model) set_setting('gemini_model', $model);
            set_setting('gemini_error', null);
            $txt = '';
            foreach ($r['data']['candidates'][0]['content']['parts'] ?? [] as $p) $txt .= $p['text'] ?? '';
            $txt = trim((string)preg_replace('~^```(?:json)?|```$~m', '', trim($txt)));
            $d = json_decode($txt, true);
            return is_array($d) ? $d : null;
        }
        set_setting('gemini_error', 'aucun modèle disponible avec cette clé');
        return null;
    }

    public static function enrichPending(int $seconds): array
    {
        if (!self::ready()) return ['skipped' => 'pas de clé Gemini'];
        $start = microtime(true);
        $rows = Db::all("SELECT * FROM listings WHERE ai_done = 0 AND dup_of IS NULL AND status <> 'rejected'
                         AND (bucket IN ('deal', 'check') OR (source_kind <> 'site' AND kind IN ('inconnu', 'terrain') AND first_seen > ?))
                         ORDER BY (bucket = 'deal') DESC, first_seen DESC LIMIT 30", [now() - 3 * 86400]);
        $done = 0;
        foreach ($rows as $row) {
            if (microtime(true) - $start > $seconds) break;
            $ans = self::ask(self::prompt($row));
            if ($ans === null) {
                if (setting('gemini_error')) break; // quota ou clé : on réessaiera au prochain passage
                Db::run('UPDATE listings SET ai_done = 2 WHERE id = ?', [$row['id']]);
                continue;
            }
            self::apply($row, $ans);
            $done++;
            usleep(4200000); // on reste sous la limite gratuite par minute
        }
        return ['done' => $done, 'left' => max(0, count($rows) - $done)];
    }

    private static function prompt(array $l): string
    {
        $profiles = [];
        foreach (Classify::profiles() as $p) {
            $profiles[] = '- ' . $p['label'] . ' : ' . number_format($p['max'] / 1e6, 1, ',', ' ') . ' M Rs maximum, '
                . ($p['group'] === 'cote' ? 'à pied de la plage (Trou aux Biches, Mont Choisy, Pointe aux Canonniers, Grand Baie, Pereybère, Bain Boeuf, Cap Malheureux, Anse la Raie)' : 'tout le Nord (mêmes villages plus Cottage, Solitude, Pamplemousses, Amaury, Triolet, Goodlands, Mapou…)');
        }
        $ad = json_encode([
            'titre' => $l['title'], 'source' => $l['source_name'], 'texte' => Text::cut((string)$l['text'], 2600),
            'prix_lu' => $l['price'], 'localite_lue' => $l['zone'], 'nature_lue' => $l['kind'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return "Tu aides un acheteur mauricien à repérer les bonnes affaires immobilières dans le Nord de l'île Maurice.\n"
            . "Il cherche :\n" . implode("\n", $profiles) . "\n\n"
            . "Analyse l'annonce ci-dessous (français, anglais ou créole mauricien). Ne devine rien : mets null si l'information n'y est pas.\n"
            . "Rappels : 1 toise = 3,8 m² ; 1 perche = 42,2 m² ; 1 arpent = 4 221 m². « 2.8M » = 2 800 000 Rs.\n\n"
            . "Réponds uniquement avec ce JSON :\n"
            . "{\n"
            . '  "nature": "appartement" | "maison" | "terrain_res" | "terrain_agri" | "terrain" | "autre" | "pas_une_vente",' . "\n"
            . '  "localite": "village ou quartier, sinon null",' . "\n"
            . '  "prix_rs": nombre entier (prix total en roupies) ou null,' . "\n"
            . '  "terrain_m2": nombre ou null, "habitable_m2": nombre ou null, "chambres": nombre ou null,' . "\n"
            . '  "plage": "accès ou distance à la plage en quelques mots, sinon null",' . "\n"
            . '  "vendeur": "particulier" | "agence" | "inconnu",' . "\n"
            . '  "resume": "deux phrases factuelles en français : ce que l\'acheteur doit savoir avant d\'appeler",' . "\n"
            . '  "vigilance": ["au plus trois points courts à vérifier"],' . "\n"
            . '  "avis": entier de -10 (mauvaise affaire) à 10 (vraie bonne affaire) au vu du prix, de l\'emplacement et de l\'état' . "\n"
            . "}\n"
            . "« pas_une_vente » pour une location, une personne qui cherche à acheter, une publicité sans bien précis ou un message hors sujet.\n\n"
            . "ANNONCE :\n" . $ad;
    }

    private static function apply(array $row, array $a): void
    {
        $upd = [];
        $nature = (string)($a['nature'] ?? '');
        if ($nature === 'pas_une_vente') {
            Db::run("UPDATE listings SET ai_done = 1, bucket = 'none', profile = NULL, ai_summary = ? WHERE id = ?",
                [json_encode(['resume' => $a['resume'] ?? 'Écartée par l\'analyse : ce n\'est pas une vente.'], JSON_UNESCAPED_UNICODE), $row['id']]);
            return;
        }
        if (in_array($nature, ['appartement', 'maison', 'terrain_res', 'terrain_agri', 'autre'], true)
            && (in_array($row['kind'], ['inconnu', 'terrain', null, ''], true) || ($row['source_kind'] !== 'site' && $nature !== $row['kind']))) {
            $upd['kind'] = $nature;
        }
        if (!$row['zone'] || $row['zone_group'] === 'nord?') {
            $g = Geo::detect((string)($a['localite'] ?? ''));
            if ($g['zone']) { $upd['zone'] = $g['zone']; $upd['zone_group'] = $g['group']; }
            elseif (!$row['zone'] && !empty($a['localite']) && is_string($a['localite'])) { $upd['zone'] = Text::cut($a['localite'], 40); $upd['zone_group'] = 'hors'; }
        }
        if (!$row['price'] && is_numeric($a['prix_rs'] ?? null) && $a['prix_rs'] >= 150000) $upd['price'] = (int)$a['prix_rs'];
        if (!$row['land_m2'] && is_numeric($a['terrain_m2'] ?? null) && $a['terrain_m2'] > 10) $upd['land_m2'] = (int)$a['terrain_m2'];
        if (!$row['built_m2'] && is_numeric($a['habitable_m2'] ?? null) && $a['habitable_m2'] > 8) $upd['built_m2'] = (int)$a['habitable_m2'];
        if ($row['beds'] === null && is_numeric($a['chambres'] ?? null)) $upd['beds'] = (int)$a['chambres'];

        $summary = [
            'resume' => Text::cut((string)($a['resume'] ?? ''), 400),
            'vigilance' => array_slice(array_values(array_filter(array_map('strval', (array)($a['vigilance'] ?? [])))), 0, 3),
            'plage' => is_string($a['plage'] ?? null) ? Text::cut($a['plage'], 80) : null,
            'vendeur' => $a['vendeur'] ?? null,
            'avis' => max(-10, min(10, (int)($a['avis'] ?? 0))),
        ];
        $upd['ai_summary'] = json_encode($summary, JSON_UNESCAPED_UNICODE);
        $upd['ai_done'] = 1;
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($upd)));
        Db::run("UPDATE listings SET $set WHERE id = ?", [...array_values($upd), $row['id']]);

        $fresh = Db::row('SELECT * FROM listings WHERE id = ?', [$row['id']]);
        if ($fresh) Pipeline::rescore($fresh);
    }
}
