<?php

declare(strict_types=1);

class JwtHelper
{
    public static function generate(array $payload): string
    {
        $header = self::base64UrlEncode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + JWT_EXPIRY;
        $encodedPayload = self::base64UrlEncode((string) json_encode($payload));
        $signature = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$encodedPayload", JWT_SECRET, true)
        );

        return "$header.$encodedPayload.$signature";
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $encodedPayload, $signature] = $parts;

        $expectedSig = self::base64UrlEncode(
            hash_hmac('sha256', "$header.$encodedPayload", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSig, $signature)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($encodedPayload), true);

        if (!is_array($data)) {
            return null;
        }

        if (isset($data['exp']) && $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
