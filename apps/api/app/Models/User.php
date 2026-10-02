<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use BelongsToOrganisation, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'mfa_secret'];

    /** @var array<string>|null memoised permission keys */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'attributes' => 'array',
            'preferences' => 'array',
            'mfa_secret' => 'encrypted',
            'mfa_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array<string> */
    public function permissionKeys(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = Permission::query()
                ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
                ->where('user_roles.user_id', $this->id)
                ->distinct()
                ->pluck('permissions.key')
                ->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $key): bool
    {
        $keys = $this->permissionKeys();

        return in_array('*', $keys, true) || in_array($key, $keys, true);
    }

    /** @return array<string> */
    public function roleKeys(): array
    {
        return $this->roles->pluck('key')->all();
    }

    /** The UI experience tier: executive users never see engineering controls. */
    public function experience(): string
    {
        $order = ['admin' => 4, 'engineer' => 3, 'analyst' => 2, 'executive' => 1];
        $best = $this->roles->sortByDesc(fn ($r) => $order[$r->experience] ?? 0)->first();

        return $best->experience ?? 'executive';
    }

    /** ABAC attribute lookup, e.g. attribute('country_codes'). */
    public function attribute(string $key): mixed
    {
        return ($this->getAttribute('attributes') ?? [])[$key] ?? null;
    }
}
