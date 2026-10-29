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

    /** Apply CA + keep peer verification enabled. */
    public static function applyCurl($ch): void
    {
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
        return 'HTTPS certificate verification failed. If a corporate firewall (e.g. Sophos) intercepts TLS, '
            . 'export its SSL inspection CA as PEM, append it to storage/certs/cacert-with-local.pem '
            . '(start from storage/certs/cacert.pem), or set IFMS_SSL_CAINFO to that file. '
            . 'Do not disable SSL verification. Production hosts without TLS inspection should use the Mozilla CA bundle only.';
    }
}
