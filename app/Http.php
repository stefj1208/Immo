<?php
declare(strict_types=1);

/** Téléchargements : navigateur crédible, délais courts, plusieurs pages en parallèle. */
final class Http
{
    private const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
    private const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private static function handle(string $url, array $opt = [])
    {
        $ch = curl_init($url);
        $headers = array_merge([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
        ], $opt['headers'] ?? []);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => $opt['timeout'] ?? 25,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => ($opt['desktop'] ?? true) ? self::UA_DESKTOP : self::UA,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if (isset($opt['post'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['post']);
        }
        if (isset($opt['method'])) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $opt['method']);
        return $ch;
    }

    /** @return array{code:int, body:string, error:string, url:string} */
    public static function get(string $url, array $opt = []): array
    {
        $ch = self::handle($url, $opt);
        $body = curl_exec($ch);
        $res = [
            'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => is_string($body) ? $body : '',
            'error' => curl_error($ch),
            'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];
        curl_close($ch);
        return $res;
    }

    public static function json(string $url, array $opt = []): array
    {
        $opt['headers'] = array_merge(['Accept: application/json'], $opt['headers'] ?? []);
        if (isset($opt['body'])) {
            $opt['post'] = json_encode($opt['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $opt['headers'][] = 'Content-Type: application/json';
        }
        $r = self::get($url, $opt);
        $r['data'] = json_decode($r['body'], true);
        return $r;
    }

    /**
     * Plusieurs pages en parallèle.
     * @param string[] $urls
     * @return array<string, array{code:int, body:string, error:string}>
     */
    public static function many(array $urls, int $parallel = 4, array $opt = []): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($urls)), $parallel) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $u) {
                $h = self::handle($u, $opt);
                curl_multi_add_handle($mh, $h);
                $handles[$u] = $h;
            }
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $u => $h) {
                $out[$u] = [
                    'code' => (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE),
                    'body' => (string)curl_multi_getcontent($h),
                    'error' => curl_error($h),
                ];
                curl_multi_remove_handle($mh, $h);
                curl_close($h);
            }
            curl_multi_close($mh);
            usleep(250000); // on reste poli avec les petits sites
        }
        return $out;
    }

    public static function absolute(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href));
        if ($href === '' || $href[0] === '#' || preg_match('~^(javascript|mailto|tel|whatsapp|data):~i', $href)) return null;
        if (preg_match('~^https?://~i', $href)) return self::tidy($href);
        $b = parse_url($base);
        if (!$b || empty($b['host'])) return null;
        $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) return self::tidy(($b['scheme'] ?? 'https') . ':' . $href);
        if ($href[0] === '/') return self::tidy($origin . $href);
        if ($href[0] === '?') return self::tidy($origin . ($b['path'] ?? '/') . $href);
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
        return self::tidy($origin . $dir . $href);
    }

    /** Retire les ancres et les paramètres de suivi pour reconnaître une même annonce. */
    public static function tidy(string $url): string
    {
        $url = (string)preg_replace('~#.*$~', '', $url);
        $url = (string)preg_replace('~([?&])(utm_[a-z]+|fbclid|gclid|ref|source|mibextid|rdid|share_url|__cft__\[\d\]|__tn__)=[^&]*~i', '$1', $url);
        $url = (string)preg_replace('~[?&]+$~', '', (string)preg_replace('~([?&])&+~', '$1', $url));
        return $url;
    }
}
