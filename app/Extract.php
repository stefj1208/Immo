<?php
declare(strict_types=1);

/**
 * Transforme une page d'annonce en données brutes, quel que soit le site :
 * données structurées (JSON-LD) d'abord, balises de partage ensuite, texte en dernier.
 */
final class Extract
{
    /** Liens d'une page de liste correspondant au motif des fiches d'annonce. */
    public static function links(string $html, string $base, string $regex, string $exclude = ''): array
    {
        $out = [];
        if (!preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m)) return $out;
        foreach ($m[2] as $href) {
            $abs = Http::absolute($href, $base);
            if (!$abs) continue;
            if (!@preg_match('~' . $regex . '~i', $abs)) continue;
            if ($exclude !== '' && @preg_match('~' . $exclude . '~i', $abs)) continue;
            $out[$abs] = true;
        }
        return array_keys($out);
    }

    public static function meta(string $html): array
    {
        $meta = [];
        if (preg_match_all('~<meta\b[^>]*>~is', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('~\b(?:property|name|itemprop)\s*=\s*(["\'])(.*?)\1~is', $tag, $k)) continue;
                if (!preg_match('~\bcontent\s*=\s*(["\'])(.*?)\1~is', $tag, $v)) continue;
                $key = strtolower(trim($k[2]));
                $val = Text::clean($v[2]);
                if ($key === 'og:image' || $key === 'twitter:image') $meta['images'][] = $val;
                elseif (!isset($meta[$key])) $meta[$key] = $val;
            }
        }
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $t)) $meta['title'] = Text::clean(strip_tags($t[1]));
        if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $html, $h)) $meta['h1'] = Text::clean(strip_tags($h[1]));
        return $meta;
    }

    /** Aplati tous les blocs JSON-LD de la page. */
    public static function jsonLd(string $html): array
    {
        $nodes = [];
        if (!preg_match_all('~<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m)) return $nodes;
        foreach ($m[1] as $raw) {
            $data = json_decode(trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5)), true);
            if ($data === null) $data = json_decode(trim($raw), true);
            if (!is_array($data)) continue;
            $stack = [$data];
            while ($stack) {
                $n = array_pop($stack);
                if (!is_array($n)) continue;
                if (isset($n['@graph']) && is_array($n['@graph'])) foreach ($n['@graph'] as $g) $stack[] = $g;
                if (array_is_list($n)) { foreach ($n as $g) $stack[] = $g; continue; }
                if (isset($n['@type'])) $nodes[] = $n;
                foreach (['mainEntity', 'about', 'itemOffered', 'mainEntityOfPage'] as $k) {
                    if (isset($n[$k]) && is_array($n[$k])) $stack[] = $n[$k];
                }
            }
        }
        return $nodes;
    }

    private static function pick(array $node, string ...$keys)
    {
        foreach ($keys as $k) {
            if (!isset($node[$k])) continue;
            $v = $node[$k];
            if (is_array($v) && isset($v['value'])) $v = $v['value'];
            if (is_array($v) && isset($v['name'])) $v = $v['name'];
            if (is_scalar($v) && $v !== '') return $v;
        }
        return null;
    }

    /** Texte lisible de la page, sans menus ni scripts. */
    public static function mainText(string $html): string
    {
        $h = (string)preg_replace('~<(script|style|noscript|svg|nav|footer|header|form|select|iframe|template)\b.*?</\1>~is', ' ', $html);
        $h = (string)preg_replace('~<!--.*?-->~s', ' ', $h);
        $h = (string)preg_replace('~<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article)\b[^>]*>~i', "\n", $h);
        $t = Text::clean(strip_tags($h));
        $t = (string)preg_replace('/[ \t]*\n[ \t]*/', "\n", $t);
        return (string)preg_replace('/\n{3,}/', "\n\n", $t);
    }

    /** @return array données brutes d'une annonce */
    public static function fromHtml(string $html, string $url): array
    {
        $meta = self::meta($html);
        $nodes = self::jsonLd($html);
        $text = self::mainText($html);

        $title = $meta['h1'] ?? $meta['og:title'] ?? $meta['title'] ?? '';
        $desc = $meta['og:description'] ?? $meta['description'] ?? '';
        $price = null;
        $location = '';
        $images = $meta['images'] ?? [];
        $beds = null;
        $built = null;
        $land = null;
        $posted = null;
        $ldDesc = '';

        foreach ($nodes as $n) {
            $type = is_array($n['@type']) ? implode(',', $n['@type']) : (string)$n['@type'];
            if (preg_match('~BreadcrumbList|Organization|WebSite|WebPage|SearchAction|ImageObject|Person|FAQPage|ItemList~i', $type)
                && !isset($n['offers']) && !isset($n['price'])) {
                continue;
            }
            $offers = $n['offers'] ?? null;
            if (is_array($offers) && array_is_list($offers)) $offers = $offers[0] ?? null;
            $p = is_array($offers) ? ($offers['price'] ?? $offers['lowPrice'] ?? ($offers['priceSpecification']['price'] ?? null)) : ($n['price'] ?? null);
            $cur = is_array($offers) ? ($offers['priceCurrency'] ?? ($offers['priceSpecification']['priceCurrency'] ?? '')) : ($n['priceCurrency'] ?? '');
            if ($price === null && $p !== null && is_numeric(str_replace([',', ' '], '', (string)$p))) {
                $amount = (float)str_replace([',', ' '], '', (string)$p);
                $cur = strtoupper((string)$cur);
                if ($cur === 'EUR') $amount *= (float)(setting('rate_eur') ?? 53);
                elseif ($cur === 'USD') $amount *= (float)(setting('rate_usd') ?? 46);
                if ($amount >= 150000) $price = ['amount' => (int)round($amount), 'unit' => null, 'raw' => $p . ' ' . $cur];
            }
            if ($ldDesc === '' && isset($n['description']) && is_string($n['description'])) $ldDesc = Text::clean(strip_tags($n['description']));
            if ($title === '' && isset($n['name']) && is_string($n['name'])) $title = Text::clean($n['name']);
            $addr = $n['address'] ?? null;
            if ($location === '' && is_array($addr)) {
                $location = trim(implode(' ', array_filter([
                    is_string($addr['streetAddress'] ?? null) ? $addr['streetAddress'] : '',
                    is_string($addr['addressLocality'] ?? null) ? $addr['addressLocality'] : '',
                    is_string($addr['addressRegion'] ?? null) ? $addr['addressRegion'] : '',
                ])));
            } elseif ($location === '' && is_string($addr)) {
                $location = $addr;
            }
            $img = $n['image'] ?? null;
            if (is_string($img)) $images[] = $img;
            elseif (is_array($img)) {
                foreach (array_is_list($img) ? $img : [$img] as $im) {
                    if (is_string($im)) $images[] = $im;
                    elseif (is_array($im) && isset($im['url']) && is_string($im['url'])) $images[] = $im['url'];
                }
            }
            $beds ??= ($b = self::pick($n, 'numberOfBedrooms', 'numberOfRooms')) !== null && is_numeric($b) ? (int)$b : null;
            $built ??= ($f = self::pick($n, 'floorSize')) !== null && is_numeric($f) ? (int)$f : null;
            $posted ??= ($d = self::pick($n, 'datePosted', 'datePublished', 'dateCreated')) ? (strtotime((string)$d) ?: null) : null;
        }

        if ($price === null && isset($meta['product:price:amount']) && is_numeric(str_replace(',', '', $meta['product:price:amount']))) {
            $amount = (float)str_replace(',', '', $meta['product:price:amount']);
            $cur = strtoupper($meta['product:price:currency'] ?? 'MUR');
            if ($cur === 'EUR') $amount *= (float)(setting('rate_eur') ?? 53);
            elseif ($cur === 'USD') $amount *= (float)(setting('rate_usd') ?? 46);
            if ($amount >= 150000) $price = ['amount' => (int)round($amount), 'unit' => null, 'raw' => $meta['product:price:amount']];
        }

        // Le texte utile commence au titre : on évite les prix des annonces « similaires » plus bas.
        $body = $text;
        if ($title !== '' && ($pos = mb_stripos($text, mb_substr($title, 0, 40))) !== false) {
            $body = mb_substr($text, $pos, 3500);
        } else {
            $body = mb_substr($text, 0, 3500);
        }
        $description = $ldDesc !== '' && mb_strlen($ldDesc) > mb_strlen($desc) ? $ldDesc : $desc;

        $price ??= Text::price($title) ?? Text::price($description) ?? Text::price(mb_substr($body, 0, 1500));

        if (preg_match_all('~<img\b[^>]*?\b(?:data-src|data-lazy-src|data-original|src)\s*=\s*(["\'])(.*?)\1~is', $html, $im)) {
            foreach ($im[2] as $src) {
                if (count($images) >= 14) break;
                if (!preg_match('~\.(jpe?g|webp|png)(\?|$)~i', $src)) continue;
                if (preg_match('~(logo|icon|avatar|sprite|placeholder|banner|flag|badge|loader|blank|agent|profile|favicon|pixel)~i', $src)) continue;
                if (!preg_match('~(upload|photo|propert|listing|media|image|img|annonce|bien|cdn|storage|gallery|pictures?)~i', $src)) continue;
                if ($abs = Http::absolute($src, $url)) $images[] = $abs;
            }
        }
        $images = array_slice(array_values(array_unique(array_filter(array_map(
            fn($i) => is_string($i) ? Http::absolute($i, $url) : null, $images)))), 0, 8);

        if (!$posted && isset($meta['article:published_time'])) $posted = strtotime($meta['article:published_time']) ?: null;

        return [
            'url' => $url,
            'title' => Text::cut($title, 200),
            'location' => $location,
            'description' => $description,
            'text' => trim($description . "\n\n" . $body),
            'price' => $price,
            'beds' => $beds,
            'built_m2' => $built,
            'land_m2' => $land,
            'photos' => $images,
            'phone' => Text::phone($description . "\n" . mb_substr($body, 0, 3000), $html),
            'posted_at' => $posted,
        ];
    }

    /** Résumé de structure d'une page, pour mettre au point une nouvelle source. */
    public static function probe(string $html, string $base): array
    {
        $hrefs = [];
        if (preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $m)) {
            foreach ($m[2] as $h) if ($a = Http::absolute($h, $base)) $hrefs[$a] = true;
        }
        $host = parse_url($base, PHP_URL_HOST);
        $shapes = [];
        foreach (array_keys($hrefs) as $h) {
            if (parse_url($h, PHP_URL_HOST) !== $host) continue;
            $path = (string)parse_url($h, PHP_URL_PATH);
            $shape = preg_replace(['~\d+~', '~[a-z0-9]+(-[a-z0-9]+){2,}~i'], ['N', 'SLUG'], $path);
            $shapes[$shape][] = $h;
        }
        uasort($shapes, fn($a, $b) => count($b) <=> count($a));
        $top = [];
        foreach (array_slice($shapes, 0, 14, true) as $shape => $list) {
            $top[] = sprintf('%3d × %s   ex. %s', count($list), $shape, $list[0]);
        }
        $nodes = self::jsonLd($html);
        $types = array_map(fn($n) => is_array($n['@type']) ? implode('+', $n['@type']) : $n['@type'], $nodes);
        return [
            'bytes' => strlen($html),
            'title' => self::meta($html)['title'] ?? '',
            'links_total' => count($hrefs),
            'jsonld_types' => array_count_values($types),
            'shapes' => $top,
        ];
    }
}
