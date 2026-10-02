<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $first = explode(' ', (string) $this->name)[0];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $first,
            'email' => $this->email,
            'title' => $this->title,
            'status' => $this->status,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => ['key' => $r->key, 'name' => $r->name])),
            'permissions' => $this->whenLoaded('roles', fn () => $this->permissionKeys()),
            'experience' => $this->whenLoaded('roles', fn () => $this->experience()),
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'team' => $this->whenLoaded('team', fn () => $this->team?->name),
            'organisation' => $this->whenLoaded('organisation', fn () => [
                'id' => $this->organisation->id, 'name' => $this->organisation->name, 'slug' => $this->organisation->slug,
                'currency' => $this->organisation->currency, 'timezone' => $this->organisation->timezone, 'branding' => $this->organisation->branding,
            ]),
            'data_scope' => $this->getAttribute('attributes') ?: null,
            'preferences' => $this->preferences ?? [],
            'mfa_enabled' => $this->mfa_enabled,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
