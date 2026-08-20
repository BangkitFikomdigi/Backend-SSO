<?php

namespace App\Support;

/**
 * Helper JWT (HS256) minimal, tanpa dependency composer tambahan.
 *
 * Dipakai untuk access_token & refresh_token yang stateless: payload
 * (termasuk waktu kedaluwarsa) tertanam & terverifikasi via signature,
 * jadi tidak butuh lookup DB untuk tahu token itu valid/kadaluarsa -
 * beda dengan session_id yang memang sengaja stateful (disimpan di DB).
 */
class JwtToken
{
    /**
     * Buat JWT baru.
     *
     * @param  array  $claims  Data tambahan di payload (mis. sub, session_id, type)
     * @param  int  $ttlSeconds  Masa berlaku token, dalam detik
     */
    public static function encode(array $claims, int $ttlSeconds, ?string $secret = null): string
    {
        $secret ??= self::secret();
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Verifikasi & decode JWT. Return null kalau signature tidak cocok,
     * format rusak, atau token sudah kedaluwarsa (exp).
     */
    public static function decode(?string $token, ?string $secret = null): ?array
    {
        if (! $token) {
            return null;
        }

        $secret ??= self::secret();
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expectedSignature = hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true);
        $actualSignature = self::base64UrlDecode($signatureB64);

        if ($actualSignature === false || ! hash_equals($expectedSignature, $actualSignature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return null;
        }

        if (! isset($payload['exp']) || time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        return (string) config('sso.jwt_secret', config('app.key'));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + (4 - strlen($data) % 4), '=');

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}