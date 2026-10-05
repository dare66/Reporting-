<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Support\Facades\DB;

/** Who can open which project, and each organisation's default project. */
class Projects
{
    /** Every organisation has one default project, open to everyone; it is created on first need. */
    public static function defaultFor(string $organisationId): Project
    {
        return TenantScopeBypass::run(fn () => Project::where('organisation_id', $organisationId)->where('is_default', true)->first()
            ?? Project::create(['organisation_id' => $organisationId, 'key' => 'general', 'name' => 'General', 'visibility' => 'organisation', 'is_default' => true]));
    }

    /** @return list<string> project ids the person may open */
    public static function accessibleIds(User $user): array
    {
        return TenantScopeBypass::run(function () use ($user) {
            $q = Project::where('organisation_id', $user->organisation_id);
            if (! self::seesAll($user)) {
                $q->where(fn ($q) => $q->where('visibility', 'organisation')
                    ->orWhereIn('id', DB::table('project_members')->where('user_id', $user->id)->select('project_id')));
            }

            return $q->pluck('id')->all();
        });
    }

    /** Administrators of the organisation open every project. */
    public static function seesAll(User $user): bool
    {
        return $user->hasPermission('admin.org');
    }

    /** Owners of a project, and organisation administrators, manage its details and members. */
    public static function canManage(User $user, Project $project): bool
    {
        return self::seesAll($user) || DB::table('project_members')->where('project_id', $project->id)->where('user_id', $user->id)->where('role', 'owner')->exists();
    }
}
