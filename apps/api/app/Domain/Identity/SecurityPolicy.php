<?php

namespace App\Domain\Identity;

use App\Models\Organisation;
use App\Models\User;

/**
 * An organisation's sign-in and account rules, stored under
 * organisations.settings.security and enforced by the API (EnforceSecurityPolicy,
 * account creation, password changes and resets).
 *
 * @phpstan-type PolicyArray array{password_min_length: int, require_mfa: 'none'|'admins'|'all', allowed_email_domains: list<string>}
 */
final class SecurityPolicy
{
    public const MFA_MODES = ['none', 'admins', 'all'];

    public const MIN_PASSWORD = 12;

    public const MAX_PASSWORD = 64;

    /** @param  list<string>  $allowedEmailDomains  empty = any domain */
    public function __construct(
        public readonly int $passwordMinLength = self::MIN_PASSWORD,
        public readonly string $requireMfa = 'none',
        public readonly array $allowedEmailDomains = [],
    ) {}

    public static function for(Organisation $organisation): self
    {
        $s = (array) (((array) ($organisation->settings ?? []))['security'] ?? []);

        return new self(
            passwordMinLength: max(self::MIN_PASSWORD, min(self::MAX_PASSWORD, (int) ($s['password_min_length'] ?? self::MIN_PASSWORD))),
            requireMfa: in_array($s['require_mfa'] ?? null, self::MFA_MODES, true) ? $s['require_mfa'] : 'none',
            allowedEmailDomains: array_values(array_map('strtolower', array_filter((array) ($s['allowed_email_domains'] ?? []), 'is_string'))),
        );
    }

    /** Laravel validation rules for a new password under this policy. */
    public function passwordRules(): string
    {
        return 'required|string|min:'.$this->passwordMinLength.'|max:200';
    }

    public function mfaRequiredFor(User $user): bool
    {
        return match ($this->requireMfa) {
            'all' => true,
            'admins' => collect($user->permissionKeys())->contains(fn ($p) => $p === '*' || str_starts_with($p, 'admin.')),
            default => false,
        };
    }

    public function allowsEmail(string $email): bool
    {
        if ($this->allowedEmailDomains === []) {
            return true;
        }
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        return in_array($domain, $this->allowedEmailDomains, true);
    }

    /** @return PolicyArray */
    public function toArray(): array
    {
        return [
            'password_min_length' => $this->passwordMinLength,
            'require_mfa' => $this->requireMfa === 'admins' || $this->requireMfa === 'all' ? $this->requireMfa : 'none',
            'allowed_email_domains' => $this->allowedEmailDomains,
        ];
    }

    /** A strong one-time password for admin resets: shown once, then the user must change it. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 20; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($out, 5));
    }
}
