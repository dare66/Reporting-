<?php

namespace App\Domain\Identity;

use App\Models\RefreshToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Issues short-lived access JWTs and rotating, revocable refresh tokens.
 *
 * Access tokens carry tenant + role claims so stateless services (the AI
 * service) can authorise without a database round-trip, but the API always
 * re-loads the user so revoked/disabled accounts are rejected immediately.
 */
class JwtService
{
    private const ALGO = 'HS256';

    public function __construct(
        private readonly string $secret,
        private readonly string $issuer,
        private readonly int $accessTtl,
        private readonly int $refreshTtl,
    ) {
        if (strlen($secret) < 32) {
            throw new RuntimeException('JWT_SECRET must be at least 32 characters.');
        }
    }

    public static function fromConfig(): self
    {
        $c = config('aixbi.jwt');

        return new self((string) $c['secret'], $c['issuer'], $c['access_ttl'], $c['refresh_ttl']);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
    public function issue(User $user, ?string $device = null, ?string $ip = null): array
    {
        $refresh = Str::random(64);
        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $refresh),
            'device' => $device ? Str::limit($device, 250) : null,
            'ip_address' => $ip,
            'expires_at' => now()->addSeconds($this->refreshTtl),
        ]);

        return [
            'access_token' => $this->accessToken($user),
            'refresh_token' => $refresh,
            'expires_in' => $this->accessTtl,
            'token_type' => 'Bearer',
        ];
    }

    public function accessToken(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer,
            'sub' => $user->id,
            'org' => $user->organisation_id,
            'roles' => $user->roleKeys(),
            'typ' => 'access',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->accessTtl,
            'jti' => (string) Str::uuid(),
        ], $this->secret, self::ALGO);
    }

    /** Short-lived token proving password success while MFA is pending. */
    public function mfaChallengeToken(User $user): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->issuer, 'sub' => $user->id, 'typ' => 'mfa',
            'iat' => $now, 'exp' => $now + 300,
        ], $this->secret, self::ALGO);
    }

    /** @return array<string, mixed> */
    public function decode(string $token, string $expectedType = 'access'): array
    {
        $claims = (array) JWT::decode($token, new Key($this->secret, self::ALGO));
        if (($claims['iss'] ?? null) !== $this->issuer || ($claims['typ'] ?? null) !== $expectedType) {
            throw new RuntimeException('Invalid token type or issuer.');
        }

        return $claims;
    }

    /**
     * Rotates a refresh token: the presented token is revoked and a new pair issued.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}|null
     */
    public function refresh(string $refreshToken, ?string $device, ?string $ip): ?array
    {
        $record = RefreshToken::where('token_hash', hash('sha256', $refreshToken))->first();
        if (! $record || $record->revoked_at || $record->expires_at->isPast()) {
            // Reuse of a revoked token signals theft: revoke the whole family.
            if ($record?->revoked_at) {
                RefreshToken::where('user_id', $record->user_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }

            return null;
        }

        $user = $record->user()->withoutGlobalScopes()->first();
        if (! $user || $user->status !== 'active') {
            return null;
        }

        $record->update(['revoked_at' => now(), 'last_used_at' => now()]);

        return $this->issue($user, $device, $ip);
    }

    public function revoke(string $refreshToken): void
    {
        RefreshToken::where('token_hash', hash('sha256', $refreshToken))->update(['revoked_at' => now()]);
    }
}
