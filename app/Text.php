<?php
declare(strict_types=1);

/**
 * Lecture du texte des annonces mauriciennes : prix en roupies, surfaces en
 * toises / perches / arpents, numéros de téléphone, nombre de chambres.
 */
final class Text
{
    public const M2_TOISE = 3.7987;
    public const M2_PERCHE = 42.21;
    public const M2_ARPENT = 4221.0;

    /** Minuscules sans accents, espaces normalisés. */
    public static function fold(string $s): string
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2019}", "\u{2018}", "\u{02BC}"], [' ', ' ', ' ', "'", "'", "'"], $s);
        $s = mb_strtolower($s);
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ñ' => 'n',
        ]);
        return trim((string)preg_replace('/[ \t]+/', ' ', $s));
    }

    public static function clean(string $s): string
    {
        $s = html_entity_decode(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(["\u{00A0}", "\u{202F}", "\r"], [' ', ' ', ''], $s);
        $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
        $s = (string)preg_replace('/\n\s*\n\s*\n+/u', "\n\n", $s);
        return trim($s);
    }

    public static function cut(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
    }

    // ------------------------------------------------------------------ prix

    private static function num(string $raw, bool $withMultiplier): ?float
    {
        $raw = trim($raw, " .,'");
        if ($raw === '') return null;
        if ($withMultiplier) {
            $x = str_replace([' ', "'"], '', $raw);
            // "2,8" ou "2.8" -> décimal ; "2,800" n'a pas de sens avec un multiplicateur
            $x = str_replace(',', '.', $x);
            if (substr_count($x, '.') > 1) return null;
            return is_numeric($x) ? (float)$x : null;
        }
        // Sans multiplicateur : les séparateurs regroupent des milliers.
        if (preg_match('/^\d{1,3}([ .,\'])\d{3}(\1\d{3})*([.,]\d{1,2})?$/', $raw)) {
            $raw = (string)preg_replace('/[.,]\d{1,2}$/', '', $raw);
            return (float)preg_replace('/\D/', '', $raw);
        }
        if (preg_match('/^\d+$/', $raw)) return (float)$raw;
        if (preg_match('/^\d+[.,]\d{1,2}$/', $raw)) return (float)str_replace(',', '.', $raw);
        $digits = preg_replace('/\D/', '', $raw);
        return $digits === '' ? null : (float)$digits;
    }

    private static function mult(?string $m): float
    {
        $m = strtolower((string)$m);
        if ($m === '') return 1.0;
        if ($m[0] === 'k') return 1e3;
        return 1e6; // m, mio, mn, million(s)
    }

    /**
     * Cherche un prix dans un texte libre.
     * Retourne ['amount' => roupies, 'unit' => null|'toise'|'perche'|'arpent'|'m2', 'raw' => extrait].
     */
    public static function price(string $text): ?array
    {
        if ($text === '') return null;
        $orig = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
        $t = self::fold($text);

        $eur = (float)(setting('rate_eur') ?? 53);
        $usd = (float)(setting('rate_usd') ?? 46);
        // Milliers groupés par 3 (« 2 500 000 », « 8,200,000 ») ou nombre simple (« 2.8 ») : jamais le chiffre qui suit.
        $N = '(\d{1,3}(?:[ .,\']\d{3})+(?:[.,]\d{1,2})?(?!\d)|\d+(?:[.,]\d+)?)';
        $M = '(millions?|mio|mn|m|k)?';
        $cands = [];

        $add = function (int $pos, ?float $n, string $mult, float $rate, string $raw, int $prio) use (&$cands, $t) {
            if ($n === null) return;
            $amount = $n * self::mult($mult) * $rate;
            if ($amount < 1000 || $amount > 9e9) return;
            $after = substr($t, $pos + strlen($raw), 28);
            $unit = null;
            if (preg_match('~^\s*(?:rs|mur)?\s*(?:/|par|per|la|le|a la|the)\s*(toise|perche|arpent|m2|m²|sqm|metre)~', $after, $u)) {
                $unit = $u[1] === 'metre' || $u[1] === 'sqm' || $u[1] === 'm²' ? 'm2' : $u[1];
            }
            if (preg_match('~^\s*(?:rs|mur)?\s*(?:/|par|per)\s*(mois|month|nuit|night|jour|day|an\b|year|semaine|week)~', $after)) $unit = 'loyer';
            $cands[] = ['pos' => $pos, 'amount' => (int)round($amount), 'unit' => $unit, 'raw' => trim($raw), 'prio' => $prio];
        };

        // 1. Devise avant le nombre : "Rs 8,200,000", "MUR 2.8M", "₨ 3 500 000"
        if (preg_match_all('~(?<![a-z])(rs|mur|₨|rupees?|roupies?)\.?\s*:?\s*' . $N . '\s*' . $M . '(?![a-z0-9])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[2][0], ($m[3][0] ?? '') !== ''), $m[3][0] ?? '', 1, $m[0][0], 3);
        }
        // 2. Devise après le nombre : "8.2 millions de roupies", "2 500 000 Rs"
        if (preg_match_all('~' . $N . '\s*' . $M . '\s*(?:de\s+)?(rs|mur|₨|rupees?|roupies?)(?![a-z])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[1][0], ($m[2][0] ?? '') !== ''), $m[2][0] ?? '', 1, $m[0][0], 3);
        }
        // 3. Euros et dollars
        if (preg_match_all('~(€|eur(?:os?)?|usd|us\$|\$)\s*' . $N . '\s*' . $M . '(?![a-z0-9])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[2][0], ($m[3][0] ?? '') !== ''), $m[3][0] ?? '', str_starts_with($m[1][0], '€') || str_starts_with($m[1][0], 'eur') ? $eur : $usd, $m[0][0], 2);
        }
        if (preg_match_all('~' . $N . '\s*' . $M . '\s*(€|euros?|eur|usd|dollars?)(?![a-z])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[1][0], ($m[2][0] ?? '') !== ''), $m[2][0] ?? '', str_starts_with($m[3][0], 'us') || str_starts_with($m[3][0], 'do') ? $usd : $eur, $m[0][0], 2);
        }
        // 4. Mot-clé prix : "Prix : 2.8M", "price 3,500,000"
        if (preg_match_all('~(?:prix|price|asking|pri)\s*(?:de vente|demande)?\s*[:=\-]?\s*' . $N . '\s*' . $M . '(?![a-z0-9])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = self::num($m[1][0], ($m[2][0] ?? '') !== '');
                if ($n !== null && ($m[2][0] ?? '') === '' && $n < 100) $add($m[0][1], $n, 'm', 1, $m[0][0], 2); // "prix 2.8" = 2,8 M
                else $add($m[0][1], $n, $m[2][0] ?? '', 1, $m[0][0], 2);
            }
        }
        // 5. "2.8M", "3,5 millions" sans devise (jamais "100 m de la plage")
        if (preg_match_all('~(?<![\d.,a-z])(\d{1,2}(?:[.,]\d{1,3})?)\s?(millions?|mio)(?![a-z])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[1][0], true), 'm', 1, $m[0][0], 2);
        }
        if (preg_match_all('~(?<![\d.,A-Za-z])(\d{1,2}[.,]\d{1,3})\s?[mM](?![a-zA-Z0-9²])~u', $orig, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add(min($m[0][1], max(0, strlen($t) - 1)), self::num($m[1][0], true), 'm', 1, strtolower($m[0][0]), 1);
        }
        if (preg_match_all('~(?<![\d.,A-Za-z])(\d{1,2})\s?M(?![a-zA-Z0-9²])~u', $orig, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add(min($m[0][1], max(0, strlen($t) - 1)), (float)$m[1][0], 'm', 1, strtolower($m[0][0]), 1);
        }
        // 6. Grand nombre nu : "2,500,000" ou "2500000"
        if (preg_match_all('~(?<![\d.,+])(\d{1,3}(?:[ .,\']\d{3}){2,})(?![\d])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], self::num($m[1][0], false), '', 1, $m[0][0], 1);
        }
        if (preg_match_all('~(?<![\d.,+])([1-9]\d{3,4}000)(?![\d])~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) $add($m[0][1], (float)$m[1][0], '', 1, $m[0][0], 0);
        }

        // On écarte les loyers et les montants trop faibles pour être un prix de vente.
        $cands = array_values(array_filter($cands, function ($c) {
            if ($c['unit'] === 'loyer') return false;
            if ($c['unit'] === null && $c['amount'] < 150000) return false;
            return true;
        }));
        if (!$cands) return null;
        // Le plus fiable d'abord, puis le premier dans le texte.
        usort($cands, fn($a, $b) => [$b['prio'], $a['pos']] <=> [$a['prio'], $b['pos']]);
        $c = $cands[0];
        return ['amount' => $c['amount'], 'unit' => $c['unit'], 'raw' => $c['raw']];
    }

    /** Vrai si le texte ne parle que d'un loyer. */
    public static function looksLikeRentPrice(string $text): bool
    {
        $t = self::fold($text);
        return (bool)preg_match('~(\d[\d .,]*)\s*(rs|mur)?\s*(/|par|per)\s*(mois|month|nuit|night|jour)~', $t)
            || (bool)preg_match('~(rs|mur)\s*\d[\d .,]*\s*(/|par|per)\s*(mois|month|nuit|night|jour)~', $t);
    }

    // -------------------------------------------------------------- surfaces

    /** Retourne ['land' => m² de terrain|null, 'built' => m² habitables|null]. */
    public static function areas(string $text, string $kind = ''): array
    {
        $t = self::fold($text);
        $land = null;
        $built = null;
        $num = fn(string $s): float => (float)str_replace([' ', ','], ['', '.'], preg_match('/^\d{1,3}(?:[ ,]\d{3})+$/', trim($s)) ? preg_replace('/[ ,]/', '', trim($s)) : trim($s));

        if (preg_match('~(\d+(?:[.,]\d+)?)\s*(?:arpents?|arp\b|acres?)~', $t, $m)) {
            $land = $num($m[1]) * (str_contains($m[0], 'acre') ? 4046.86 : self::M2_ARPENT);
        } elseif (preg_match('~(\d+(?:[.,]\d+)?)\s*(?:perches?|pch|perch\b)~', $t, $m)) {
            $land = $num($m[1]) * self::M2_PERCHE;
        } elseif (preg_match('~(\d[\d ,]*(?:[.,]\d+)?)\s*(?:toises?|tse|tss|toise)~', $t, $m)) {
            $land = $num($m[1]) * self::M2_TOISE;
        }

        if (preg_match_all('~(\d[\d ,]*(?:[.,]\d+)?)\s*(?:m2|m²|sqm|sq\.? ?m\b|m\.?c\b|metres? carres?|square met)~u', $t, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $v = $num($m[1][0]);
                if ($v < 8 || $v > 2000000) continue;
                $before = substr($t, max(0, $m[0][1] - 45), min(45, $m[0][1]));
                $isLand = (bool)preg_match('~(terrain|land|plot|lot|parcelle|superficie totale|jardin|garden|yard)[^.\n]{0,40}$~', $before);
                $isBuilt = (bool)preg_match('~(habitable|living|bati|built|interieur|interior|construit|covered|surface de|appartement|apartment|villa de|maison de|house of)[^.\n]{0,40}$~', $before);
                if ($kind === 'terrain' || str_starts_with($kind, 'terrain')) { $land ??= $v; continue; }
                if ($isLand && !$isBuilt) { $land ??= $v; continue; }
                if ($isBuilt || $kind === 'appartement') { $built ??= $v; continue; }
                if ($built === null) $built = $v; elseif ($land === null && $v > $built * 1.3) $land = $v;
            }
        }
        if ($land !== null && $built !== null && $built > $land && $kind === 'maison') [$land, $built] = [$built, $land];

        return [
            'land' => $land !== null ? (int)round($land) : null,
            'built' => $built !== null ? (int)round($built) : null,
        ];
    }

    public static function beds(string $text): ?int
    {
        $t = self::fold($text);
        if (preg_match('~(\d{1,2})\s*(?:chambres?|chbrs?|ch\b|bedrooms?|bed\b|beds\b|br\b|bdr)~', $t, $m)) {
            $n = (int)$m[1];
            return $n > 0 && $n <= 15 ? $n : null;
        }
        if (preg_match('~\b[tf](\d)\b~', $t, $m)) return max(1, (int)$m[1] - 1);
        if (preg_match('~\bstudio\b~', $t)) return 0;
        return null;
    }

    // ------------------------------------------------------------- téléphone

    /** Numéro au format international sans le + (ex. 23057251234), ou null. */
    public static function phone(string $text, string $html = ''): ?string
    {
        if ($html !== '') {
            if (preg_match('~(?:wa\.me/|api\.whatsapp\.com/send\?phone=|whatsapp://send\?phone=)\+?(\d{8,15})~', $html, $m)) return self::normPhone($m[1]);
            if (preg_match('~href=["\']tel:\+?([\d\s().-]{7,20})["\']~', $html, $m)) {
                $p = self::normPhone($m[1]);
                if ($p) return $p;
            }
        }
        $t = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
        if (preg_match('~(?:\+|00)\s?230[\s.-]?(\d[\d\s.-]{5,10}\d)~', $t, $m)) {
            $p = self::normPhone('230' . $m[1]);
            if ($p) return $p;
        }
        if (preg_match('~(?<!\d)(5\d{3}[\s.-]?\d{4})(?!\d)~', $t, $m)) return self::normPhone($m[1]);
        if (preg_match('~(?<!\d)(5\d(?:[\s.-]?\d{2}){3})(?!\d)~', $t, $m)) return self::normPhone($m[1]);
        if (preg_match('~(?:tel|phone|call|appel|contact|whatsapp|wa|mob)[^\d+]{0,18}([2-8]\d{2}[\s.-]?\d{4})(?!\d)~i', $t, $m)) return self::normPhone($m[1]);
        if (preg_match('~\+(\d{2,3})[\s.-]?(\d[\d\s.-]{7,12}\d)~', $t, $m)) return self::normPhone($m[1] . $m[2]);
        return null;
    }

    public static function normPhone(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if ($d === null || $d === '') return null;
        $d = ltrim($d, '0') === '' ? $d : (str_starts_with($d, '00') ? substr($d, 2) : $d);
        if (str_starts_with($d, '230') && (strlen($d) === 11 || strlen($d) === 10)) return $d;
        if (strlen($d) === 8 && $d[0] === '5') return '230' . $d;
        if (strlen($d) === 7 && in_array($d[0], ['2', '4', '6', '8'], true)) return '230' . $d;
        if (strlen($d) >= 10 && strlen($d) <= 14) return $d; // numéro étranger déjà international
        return null;
    }

    public static function similar(string $a, string $b): float
    {
        $a = self::fold($a);
        $b = self::fold($b);
        if ($a === '' || $b === '') return 0.0;
        similar_text(mb_substr($a, 0, 140), mb_substr($b, 0, 140), $pct);
        return $pct;
    }
}
