<?php

class Jwt
{
    public static function encode(array $payload, int $ttl = 86400): string
    {
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;
        $body = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', $header . '.' . $body, self::secret(), true));
        return $header . '.' . $body . '.' . $sig;
    }

    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $header . '.' . $body, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload) || empty($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function secret(): string
    {
        $path = dirname(__DIR__) . '/storage/jwt.secret';
        if (!is_file($path)) {
            file_put_contents($path, bin2hex(random_bytes(32)));
        }
        return (string) file_get_contents($path);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
