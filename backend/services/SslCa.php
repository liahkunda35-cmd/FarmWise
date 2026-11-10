<?php

/**
 * Resolve a CA bundle for outbound HTTPS without disabling verification.
 * Prefer project Mozilla bundle; allow local inspection CA via IFMS_SSL_CAINFO.
 */
class SslCa
{
    public static function caInfoPath(): ?string
    {
        foreach (['IFMS_SSL_CAINFO', 'CURL_CA_BUNDLE', 'SSL_CERT_FILE'] as $env) {
            $p = getenv($env) ?: ($_ENV[$env] ?? '');
            $p = is_string($p) ? trim($p) : '';
            if ($p !== '' && is_file($p)) {
                return $p;
            }
        }
        $root = dirname(__DIR__, 2);
        $local = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert-with-local.pem';
        if (is_file($local) && filesize($local) > 1000) {
            return $local;
        }
        $mozilla = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($mozilla)) {
            return $mozilla;
        }
        $ini = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($ini) && $ini !== '' && is_file($ini)) {
            return $ini;
        }
        return null;
    }

    /** True when local Sophos/firewall MITM requires temporary verification bypass. */
    public static function insecureAllowed(): bool
    {
        $raw = strtolower(trim((string) (getenv('IFMS_SSL_INSECURE') ?: ($_ENV['IFMS_SSL_INSECURE'] ?? ''))));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /** Apply CA + peer verification (or explicit local insecure override). */
    public static function applyCurl($ch): void
    {
        if (self::insecureAllowed()) {
            // Local-only escape hatch when SSL inspection MITM CA is not installed.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $ca = self::caInfoPath();
        if ($ca) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
    }

    public static function diagnoseHint(string $curlError): ?string
    {
        if (!str_contains(strtolower($curlError), 'ssl') && !str_contains(strtolower($curlError), 'certificate')) {
            return null;
        }
        if (self::insecureAllowed()) {
            return null;
        }
        return 'HTTPS certificate verification failed. If a corporate firewall (e.g. Sophos) intercepts TLS, '
            . 'export its SSL inspection CA as PEM, append it to storage/certs/cacert-with-local.pem '
            . '(start from storage/certs/cacert.pem), set IFMS_SSL_CAINFO to that file, '
            . 'or for local dev only set IFMS_SSL_INSECURE=1. Production hosts without TLS inspection should use the Mozilla CA bundle only.';
    }
}
