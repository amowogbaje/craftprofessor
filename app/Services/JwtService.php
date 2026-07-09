<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * Stateless JWT issuance/verification via firebase/php-jwt — the actively
 * maintained, framework-agnostic JWT library (not tymon/jwt-auth, which has
 * had long stretches without releases and compatibility lag on new Laravel
 * versions). No token table to write to on every request; the only DB
 * lookups are the cheap ones below (blacklist check, token_version check).
 *
 * Claims on every token:
 *   sub  - user id
 *   tv   - the user's token_version at issue time (mismatch = revoked via "log out everywhere")
 *   jti  - unique token id (used for single-session logout via jwt_blacklist)
 *   purp - 'access' for normal tokens, 'password-reset' for the short-lived
 *          token issued mid password-recovery flow
 *   iat / exp - standard issued-at / expiry
 */
class JwtService
{
    /**
     * @throws RuntimeException if JWT_SECRET is not configured
     */
    protected function secret(): string
    {
        $secret = config('jwt.secret');

        if (empty($secret)) {
            throw new RuntimeException('JWT_SECRET is not set. Generate one with `php artisan jwt:secret`.');
        }

        return $secret;
    }

    public function issue(User $user, string $purpose = 'access', ?int $ttlMinutes = null): string
    {
        $ttlMinutes ??= $purpose === 'password-reset' ? config('jwt.reset_ttl') : config('jwt.ttl');
        $now = Carbon::now();

        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'tv' => $user->token_version,
            'jti' => (string) Str::uuid(),
            'purp' => $purpose,
            'iat' => $now->timestamp,
            'exp' => $now->copy()->addMinutes($ttlMinutes)->timestamp,
        ];

        return JWT::encode($payload, $this->secret(), config('jwt.algo'));
    }

    /**
     * Decodes and fully validates a token: signature, expiry, blacklist,
     * and token_version. Returns the decoded claims object.
     *
     * @throws UnexpectedValueException|ExpiredException|SignatureInvalidException|RuntimeException on any failure
     */
    public function decode(string $token): stdClass
    {
        $claims = JWT::decode($token, new Key($this->secret(), config('jwt.algo')));

        if ($this->isBlacklisted($claims->jti)) {
            throw new RuntimeException('Token has been revoked.');
        }

        $user = User::find($claims->sub);
        if (!$user || $user->token_version !== $claims->tv) {
            throw new RuntimeException('Token is no longer valid.');
        }

        return $claims;
    }

    /** Resolves the User for a valid token, or null if invalid/expired/revoked. */
    public function userFromToken(string $token): ?User
    {
        try {
            $claims = $this->decode($token);
        } catch (\Throwable) {
            return null;
        }

        return User::find($claims->sub);
    }

    public function blacklist(string $jti, int $expiresAtTimestamp): void
    {
        DB::table('jwt_blacklist')->updateOrInsert(
            ['jti' => $jti],
            ['expires_at' => Carbon::createFromTimestamp($expiresAtTimestamp), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    protected function isBlacklisted(string $jti): bool
    {
        return DB::table('jwt_blacklist')->where('jti', $jti)->where('expires_at', '>', now())->exists();
    }
}
