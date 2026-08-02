<?php

namespace App\Services\SocialPlatforms\Support;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Encrypts a short-lived payload (who's connecting + a nonce) into the
 * OAuth "state" param, and verifies it back on callback. Same approach
 * PinterestService::generateState()/parseState() already used — extracted
 * here so every provider's connect/callback flow shares one implementation
 * instead of five copies.
 */
class OAuthStateSigner
{
    public const TTL_MINUTES = 10;

    public static function generate(int $userId, array $extra = []): string
    {
        $payload = array_merge($extra, [
            'user_id' => $userId,
            'nonce' => Str::random(32),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]);

        $encrypted = encrypt(json_encode($payload));

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    public static function parse(string $state): array
    {
        $padded = str_pad(
            strtr($state, '-_', '+/'),
            strlen($state) % 4 === 0 ? strlen($state) : strlen($state) + (4 - strlen($state) % 4),
            '='
        );

        try {
            $encrypted = base64_decode($padded, true);
            if ($encrypted === false) {
                throw new RuntimeException('Malformed state encoding.');
            }
            $payload = json_decode(decrypt($encrypted), true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid or tampered OAuth state.');
        }

        if (!is_array($payload) || !isset($payload['user_id'], $payload['expires_at'])) {
            throw new RuntimeException('Malformed OAuth state payload.');
        }

        if ($payload['expires_at'] < now()->timestamp) {
            throw new RuntimeException('OAuth state expired — please try connecting again.');
        }

        return $payload;
    }
}
