<?php

namespace App\Support;

class DomainNormalizer
{
    /**
     * Devuelve base URL con https:// y slash final:
     * - "example.com" -> "https://example.com/"
     * - "https://www.example.com/test" -> "https://example.com/"
     */
    public static function toBaseUrl(string $urlOrHost): string
    {
        $host = self::toHost($urlOrHost);
        return 'https://' . $host . '/';
    }

    /**
     * Devuelve SOLO el host sin protocolo ni path:
     * - "https://www.example.com/test" -> "example.com"
     * - "example.com" -> "example.com"
     * - "http://example.com/" -> "example.com"
     */
    public static function toHost(string $urlOrHost): string
    {
        $s = trim($urlOrHost);

        // si no tiene protocolo, se lo agregamos para parse_url
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }

        $host = parse_url($s, PHP_URL_HOST);

        if (!$host) {
            // fallback manual
            $host = preg_replace('#^https?://#i', '', $urlOrHost);
            $host = preg_replace('#/.*$#', '', $host);
        }

        $host = strtolower($host);
        $host = preg_replace('#^www\.#', '', $host);

        return $host;
    }

    /**
     * Devuelve root domain simple: últimos 2 labels.
     * - "a.b.example.es" -> "example.es"
     * Útil para .es y la mayoría, no perfecto para .co.uk (si lo necesitas luego lo mejoramos).
     */
    public static function rootDomainSimple(string $hostOrUrl): string
    {
        $host = self::toHost($hostOrUrl);
        $parts = explode('.', $host);
        if (count($parts) <= 2) return $host;
        return implode('.', array_slice($parts, -2));
    }

    /**
     * Normaliza una URL (por si lo necesitas en otros lugares)
     */
    public static function normalizeUrl(string $urlOrHost): string
    {
        return self::toBaseUrl($urlOrHost);
    }
}
