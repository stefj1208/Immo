<?php
declare(strict_types=1);

/**
 * Le tri : nature du bien, correspondance avec les recherches, note « bon plan ».
 * Tout est fait par règles, sans service extérieur ; l'IA ne fait qu'affiner ensuite.
 */
final class Classify
{
    public const DEFAULT_PROFILES = [
        'appart' => ['label' => 'Appartement', 'kinds' => ['appartement'], 'group' => 'cote', 'max' => 6000000, 'min' => 1500000],
        'maison' => ['label' => 'Maison', 'kinds' => ['maison'], 'group' => 'cote', 'max' => 10000000, 'min' => 3000000],
        'terrain_res' => ['label' => 'Terrain résidentiel', 'kinds' => ['terrain_res', 'terrain'], 'group' => 'cote', 'max' => 3000000, 'min' => 150000],
        'terrain_agri' => ['label' => 'Terrain agricole', 'kinds' => ['terrain_agri', 'terrain'], 'group' => 'nord', 'max' => 2500000, 'min' => 100000],
    ];

    public static function profiles(): array
    {
        $saved = setting_json('profiles');
        $out = self::DEFAULT_PROFILES;
        foreach ($saved as $id => $p) {
            if (isset($out[$id]) && isset($p['max'])) $out[$id]['max'] = max(100000, (int)$p['max']);
        }
        return $out;
    }

    // ---------------------------------------------------------------- nature

    private const RX_APPART = 'appartements?|apparts?\b|apartments?|apt\b|studios?\b|penthouse|duplex|triplex|flat\b|condo|loft\b|rez de jardin';
    private const RX_MAISON = 'maisons?\b|villas?\b|houses?\b|bungalows?|campements?|townhouses?|maisonnette|chalet|pavillon|lakaz|residence principale';
    private const RX_TERRAIN = 'terrains?\b|land\b|plots?\b|lotissement|morcellement|parcelle|arpents?\b|perches?\b|toises?\b|portion de terre|later\b';
    private const RX_COMMERCE = 'bureaux?\b|office|local commercial|commerce\b|commercial (space|building|property)|boutique|shop\b|showroom|entrepot|warehouse|restaurant|snack|fonds de commerce|business for sale|hotel\b|immeuble|industriel|industrial|hangar|garage a vendre|emplacement commercial';

    public static function kind(string $title, string $text = '', string $url = ''): string
    {
        $slug = str_replace(['-', '_', '/'], ' ', (string)parse_url($url, PHP_URL_PATH));
        foreach ([Text::fold($title), Text::fold($slug), mb_substr(Text::fold($text), 0, 400)] as $i => $t) {
            if ($t === '') continue;
            $pa = preg_match('~\b(' . self::RX_APPART . ')~', $t, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
            $pm = preg_match('~\b(' . self::RX_MAISON . ')~', $t, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
            $pt = preg_match('~\b(' . self::RX_TERRAIN . ')~', $t, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
            $pc = preg_match('~\b(' . self::RX_COMMERCE . ')~', $t, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;

            if ($pa === null && $pm === null && $pt === null) {
                if ($pc !== null && $i === 0) return 'autre';
                continue;
            }
            if ($pc !== null && $i === 0 && $pa === null && $pm === null) return 'autre';
            if ($pa !== null || $pm !== null) {
                // « Terrain de 10 perches avec maison » reste une maison.
                if ($pa !== null && ($pm === null || $pa < $pm)) return 'appartement';
                return 'maison';
            }
            return self::landKind($title, $text);
        }
        return 'inconnu';
    }

    private static function landKind(string $title, string $text): string
    {
        $agri = 'agricoles?|agricultural|agriculture|\bagri\b|non[ -]?residentiel|non[ -]?constructible|sous cannes?|champs? de cannes?|cane ?fields?|plantation|verger|planteur|farm ?land|farming|bail agricole|elevage|maraich|cultivable|terre arable|land under cane';
        $res = 'residentiels?|residential|constructible|a batir|permis de (construire|morcellement)|viabilise|lotissement|building permit|pour construction|for construction|gated|converti|conversion (obtenue|done|obtained)|morcellement';
        $t = Text::fold($title);
        $a = (bool)preg_match("~($agri)~", $t);
        $r = (bool)preg_match("~($res)~", $t);
        if ($a && !$r) return 'terrain_agri';
        if ($r && !$a) return 'terrain_res';
        $x = Text::fold($title . ' ' . mb_substr($text, 0, 1200));
        $na = preg_match_all("~($agri)~", $x);
        $nr = preg_match_all("~($res)~", $x);
        if ($na > $nr) return 'terrain_agri';
        if ($nr > $na) return 'terrain_res';
        return 'terrain';
    }

    /** Motif d'exclusion (location, recherche d'un acheteur…) ou null. */
    public static function excluded(string $title, string $text, string $url = ''): ?string
    {
        $rxSale = '~\b(a vendre|for sale|en vente|vente\b|vends?\b|vendre|on sale|selling|to sell|sale\b|pou vande|a vend)~';
        $rxRent = '~\b(a louer|for rent|to let|to rent|en location|location\b|loyer|long[ -]term rental|short[ -]term|holiday rental|per month|par mois|/ ?mois|pou louer|rental|rent\b|bail commercial)~';
        // Le titre et l'adresse de la page tranchent d'abord : les menus des sites mentionnent souvent « vente ».
        $tt = Text::fold($title);
        if (preg_match($rxRent, $tt) && !preg_match($rxSale, $tt)) return 'location';
        $path = Text::fold(str_replace(['-', '_', '/'], ' ', (string)parse_url($url, PHP_URL_PATH)));
        if (preg_match('~\b(location|for rent|a louer|to let|rentals?|louer)\b~', $path) && !preg_match($rxSale, $tt . ' ' . $path)) return 'location';
        $head = Text::fold($title . ' ' . mb_substr($text, 0, 350));
        $sale = (bool)preg_match($rxSale, $head);
        $rent = (bool)preg_match($rxRent, $head);
        if ($rent && !$sale) return 'location';
        $start = mb_substr($head, 0, 160);
        if (preg_match('~(^|[.!\n] ?)(bonjour[ ,!]*|bonsoir[ ,!]*|hello[ ,!]*|hi[ ,!]*|urgent[ ,!:]*)?(je |nous |on |mo pe |mo |client |famille |couple )?(suis a la |sommes a la )?(re)?cherch(e|ons|ent|er)?\b~', $start)
            || preg_match('~\b(looking for|wanted|want to buy|wtb|in search of|besoin d\'un|je souhaite acheter|souhaite acheter|desire acheter|mo pe rod|pe rod|ki ena|qui a un|anyone selling|any land|any house)\b~', $start)) {
            if (!preg_match('~\b(ne cherchez plus|cherche acquereur|cherche acheteur|cherche preneur)\b~', $start)) return 'recherche';
        }
        return null;
    }

    // ------------------------------------------------------- plage / vendeur

    /** @return array{0:?string,1:int} libellé et bonus */
    public static function beach(string $text): array
    {
        $t = Text::fold($text);
        if (preg_match('~pieds? dans l\'eau|front de mer|beach ?front|sea ?front|bord de (la )?mer|sur la plage|on the beach|acces direct (a|a la|sur la) (plage|mer)|direct (beach|sea) access|water ?front|en bord de plage|premiere ligne|private beach|plage privee|lagoon ?front|facing the sea~', $t)) {
            return ['bord de mer', 16];
        }
        $rx = '~(\d{1,4}(?:[.,]\d)?)\s*(m|metres?|meters?|mtrs?|min|minutes?|mins|mn|km)\b\s*(a pied|en voiture|walk|by car|drive)?\s*(seulement |only )?(de|from|to|du|des|a|away from|d\')?\s*(la |the |une |a )?(belle |magnifique |public |publique )?(plage|beach|mer\b|sea\b|lagon|lagoon)~';
        $rx2 = '~(plage|beach|mer|sea|lagon|lagoon)[^.\n\d]{0,25}?(?:a|at|:|-|en|in)?\s*(\d{1,4}(?:[.,]\d)?)\s*(m|metres?|meters?|min|minutes?|mins|mn|km)\b\s*(a pied|en voiture|walk|by car|drive)?~';
        $v = null; $u = null; $car = false;
        if (preg_match($rx, $t, $m)) { $v = (float)str_replace(',', '.', $m[1]); $u = $m[2]; $car = isset($m[3]) && preg_match('~voiture|car|drive~', $m[3]); }
        elseif (preg_match($rx2, $t, $m)) { $v = (float)str_replace(',', '.', $m[2]); $u = $m[3]; $car = isset($m[4]) && preg_match('~voiture|car|drive~', $m[4]); }
        if ($v !== null) {
            $u = $u[0] === 'k' ? 'km' : (str_starts_with($u, 'mi') || $u === 'mn' ? 'min' : 'm');
            if ($u === 'km') { $meters = $v * 1000; $label = '≈ ' . rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . ' km de la plage'; }
            elseif ($u === 'min') { $meters = $v * ($car ? 600 : 80); $label = '≈ ' . (int)$v . ' min ' . ($car ? 'en voiture' : 'à pied') . ' de la plage'; }
            else { $meters = $v; $label = '≈ ' . (int)$v . ' m de la plage'; }
            $bonus = $meters <= 300 ? 14 : ($meters <= 800 ? 10 : ($meters <= 1500 ? 5 : 0));
            return [$label, $bonus];
        }
        if (preg_match('~a pied de la plage|a deux pas de la (plage|mer)|a quelques (pas|metres|minutes) de la (plage|mer)|walking distance|proche (de la |de |)(plage|mer)|pres de la (plage|mer)|near (the |to the |to )?(beach|sea)|close to (the )?(beach|sea)|steps (from|to) the beach|a proximite de la (plage|mer)|plage a pied|beach nearby|next to the beach~', $t)) {
            return ['proche de la plage', 9];
        }
        if (preg_match('~vue (sur la |sur |)mer|sea ?view|vue lagon|ocean view|vue ocean~', $t)) return ['vue mer', 4];
        return [null, 0];
    }

    public static function seller(string $text, string $sourceKind, ?string $hint = null): string
    {
        $t = Text::fold(mb_substr($text, 0, 1800));
        if (preg_match('~(vend(s|u|ue)? )?(par|du|de) (le |son )?proprietaire\b|proprietaire vend|direct(ement)? (du |de |aupres du )?proprietaire|by owner|direct owner|owner direct|sans agence|sans intermediaire|no agents?\b|agents? s\'abstenir|agences? s\'abstenir|de particulier|particulier a particulier|pas d\'agence|je vends|je vend\b|vends (ma|mon)|selling my|my (house|land|apartment|villa|plot)|mo pe vande|direct proprio~', $t)) {
            return 'particulier';
        }
        if ($hint) return $hint;
        if (preg_match('~agence|agency|real estate|realty|immobilier (ltd|ltee)|estate agent|agent immobilier|mandat|properties ltd|\bref ?[:#n]~', $t)) return 'agence';
        return $sourceKind === 'site' ? 'agence' : 'inconnu';
    }

    // ------------------------------------------------------------- marché

    /** Médianes du prix au m² observées dans nos propres annonces. */
    public static function market(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $rows = Db::all("SELECT kind, zone_group, price, land_m2, built_m2 FROM listings
                         WHERE price > 0 AND dup_of IS NULL AND first_seen > ? AND zone_group IN ('cote', 'nord')", [now() - 365 * 86400]);
        $buckets = [];
        foreach ($rows as $r) {
            $base = str_starts_with((string)$r['kind'], 'terrain') ? (int)$r['land_m2'] : (int)$r['built_m2'];
            if ($base < 15) continue;
            $ppm = $r['price'] / $base;
            if ($ppm < 50 || $ppm > 2000000) continue;
            $buckets[$r['kind'] . '|' . $r['zone_group']][] = $ppm;
        }
        $cache = [];
        foreach ($buckets as $k => $list) {
            if (count($list) < 6) continue;
            sort($list);
            $cache[$k] = ['median' => $list[intdiv(count($list), 2)], 'n' => count($list)];
        }
        return $cache;
    }

    // -------------------------------------------------------------- verdict

    /**
     * Complète l'annonce : profil, classement (deal / check / none), note et raisons.
     * $l contient au moins kind, zone, zone_group, price, price_unit, title, text, land_m2, built_m2.
     */
    public static function evaluate(array $l): array
    {
        $full = $l['title'] . "\n" . $l['text'];
        $reasons = [];
        $flags = [];
        $out = ['profile' => null, 'bucket' => 'none', 'score' => 0];

        [$beachLabel, $beachBonus] = self::beach($full);
        if (!$beachLabel && !empty($l['ai_beach'])) { $beachLabel = (string)$l['ai_beach']; $beachBonus = 7; }
        $out['beach'] = $beachLabel;
        $out['seller'] = self::seller($full, $l['source_kind'] ?? 'site', $l['seller_hint'] ?? null);

        if ($ex = self::excluded($l['title'], $l['text'], (string)($l['url'] ?? ''))) {
            $out['flags'] = ["écartée : $ex"];
            $out['reasons'] = [];
            return $out;
        }

        $kind = $l['kind'];
        $group = $l['zone_group'];
        $price = $l['price'] ?: null;
        $unit = $l['price_unit'] ?? null;

        if (!in_array($kind, ['appartement', 'maison', 'terrain', 'terrain_res', 'terrain_agri'], true) || $group === 'hors') {
            $out['flags'] = [];
            $out['reasons'] = [];
            return $out;
        }

        // Prix à l'unité (« Rs 30 000 la toise ») : on calcule le total si la surface est connue.
        if ($price && $unit && $unit !== 'loyer') {
            $perM2 = match ($unit) { 'toise' => Text::M2_TOISE, 'perche' => Text::M2_PERCHE, 'arpent' => Text::M2_ARPENT, default => 1.0 };
            if (!empty($l['land_m2'])) {
                $price = (int)(round($price * $l['land_m2'] / $perM2 / 10000) * 10000);
                $out['price'] = $price;
                $out['price_note'] = 'total estimé à partir du prix ' . ($unit === 'm2' ? 'au m²' : "par $unit");
            } else {
                $out['price_note'] = 'prix ' . ($unit === 'm2' ? 'au m²' : "par $unit") . ', surface inconnue';
                $price = null;
                $flags[] = 'prix à l\'unité, total à calculer';
            }
        }

        // Quel profil de recherche ?
        $profiles = self::profiles();
        $candidates = [];
        foreach ($profiles as $id => $p) {
            if (!in_array($kind, $p['kinds'], true)) continue;
            $zoneOk = $group === null || $group === 'nord?' || $group === 'cote' || ($group === 'nord' && $p['group'] === 'nord');
            if (!$zoneOk) continue;
            $candidates[$id] = $p;
        }
        if (!$candidates) {
            $out['flags'] = [];
            $out['reasons'] = [];
            return $out;
        }
        $chosen = null;
        foreach ($candidates as $id => $p) {
            if ($price === null || $price <= $p['max'] * 1.15) { $chosen = $id; break; }
        }
        if ($chosen === null) {
            $out['flags'] = ['au-dessus du budget'];
            $out['reasons'] = [];
            return $out;
        }
        $p = $candidates[$chosen];
        $out['profile'] = $chosen;

        $score = 50;
        $check = false;

        if ($kind === 'terrain') $flags[] = 'nature du terrain à vérifier (résidentiel ou agricole)';

        if ($group === null && !empty($l['zone'])) { $check = true; $flags[] = 'lieu « ' . $l['zone'] . ' » à situer : hors de tes secteurs ?'; }
        elseif ($group === null) { $check = true; $flags[] = 'localité non précisée'; }
        elseif ($group === 'nord?') { $check = true; $flags[] = 'dans le Nord, localité à préciser'; }

        if ($price === null) {
            $check = true;
            if (!in_array('prix à l\'unité, total à calculer', $flags, true)) $flags[] = 'prix non indiqué';
        } elseif ($price < $p['min']) {
            $check = true;
            $flags[] = 'prix anormalement bas, à vérifier';
        } else {
            $ratio = $price / $p['max'];
            if ($ratio > 1) {
                $score -= 14;
                $flags[] = 'au-dessus du budget de ' . round(($ratio - 1) * 100) . ' %, à négocier';
            } else {
                $bonus = (int)round(min(14, max(0, (1 - $ratio) * 28)));
                $score += $bonus;
                if ($ratio <= 0.75) $reasons[] = round((1 - $ratio) * 100) . ' % sous ton budget';
            }
            // Comparaison avec le marché observé
            $base = str_starts_with($kind, 'terrain') ? (int)($l['land_m2'] ?? 0) : (int)($l['built_m2'] ?? 0);
            $mk = self::market()[$kind . '|' . $group] ?? null;
            if ($base >= 15 && $mk) {
                $delta = ($price / $base) / $mk['median'] - 1;
                if ($delta <= -0.55) {
                    $flags[] = 'prix au m² très inférieur aux comparables : vérifier la surface';
                } elseif ($delta <= -0.12) {
                    $score += (int)round(min(20, abs($delta) * 45));
                    $reasons[] = round(abs($delta) * 100) . ' % moins cher au m² que les biens comparables';
                } elseif ($delta >= 0.25) {
                    $score -= (int)round(min(10, $delta * 15));
                    $flags[] = 'plus cher au m² que les biens comparables';
                }
            }
        }

        if ($p['group'] === 'cote') {
            $score += $beachBonus;
            if ($beachLabel) $reasons[] = $beachLabel;
        } elseif ($beachLabel && $beachBonus >= 9) {
            $score += 4;
            $reasons[] = $beachLabel;
        }

        if ($out['seller'] === 'particulier') { $score += 7; $reasons[] = 'vente par le propriétaire'; }

        $t = Text::fold($full);
        $headT = Text::fold($l['title'] . ' ' . mb_substr((string)$l['text'], 0, 600));
        if (preg_match('~urgent|vente rapide|quick sale|a saisir|prix (a debattre|casse|sacrifie)|(?<!non )(?<!not )(negociable|negotiable)|must sell|cause (de )?depart|due to (departure|relocation)|leaving (the country|mauritius)|below market|en dessous du (prix|marche)|prix reduit|price reduced|baisse de prix~', $headT)) {
            $score += 6;
            $reasons[] = 'vendeur pressé ou prix négociable';
        }
        if (!empty($l['price_prev']) && $price && $price < $l['price_prev']) {
            $score += 10;
            $reasons[] = 'prix baissé de ' . round((1 - $price / $l['price_prev']) * 100) . ' %';
        }
        if (preg_match('~a renover|a rafraichir|renovation|travaux|needs? (renovation|work|tlc)|fixer|to renovate|vetuste|ancienne maison|vieille maison~', $t)) {
            $score += 2;
            $flags[] = 'travaux à prévoir';
        }
        if (preg_match('~\bbail\b|leasehold|lease ?hold|terrain a bail|state land|pas geometriques|campement site~', $t)) { $score -= 10; $flags[] = 'terrain à bail, pas en pleine propriété'; }
        if (preg_match('~indivision|heritiers?|succession|undivided|part indivise~', $t)) { $score -= 6; $flags[] = 'indivision ou succession'; }
        if (preg_match('~sur plan|vefa|off[ -]plan|en cours de construction|under construction|livraison (prevue|en 20|20\d\d)|delivery (in|expected) 20~', $t)) { $score -= 8; $flags[] = 'sur plan ou en construction'; }
        if (preg_match('~\b(pds|irs|res|r\.e\.s|smart city|g\+2)\b~', $t)) { $score -= 4; $flags[] = 'programme pour acheteurs étrangers (PDS / IRS / RES)'; }
        if ($kind === 'terrain_agri' && preg_match('~conversion|convertible|land conversion~', $t)) $reasons[] = 'conversion évoquée';
        if (str_starts_with($kind, 'terrain') && preg_match('~inondable|flood|marecage|wetland|zone humide~', $t)) { $score -= 8; $flags[] = 'zone humide ou inondable évoquée'; }

        if (($l['first_seen'] ?? now()) > now() - 86400) $score += 3;
        if (isset($l['ai_delta'])) $score += (int)$l['ai_delta'];

        if ($check) $score = min($score, 55) - 8;

        $out['bucket'] = $check ? 'check' : 'deal';
        $out['score'] = max(1, min(99, $score));
        $out['reasons'] = array_values(array_unique($reasons));
        $out['flags'] = array_values(array_unique($flags));
        return $out;
    }
}
