<?php

namespace App\Domain\Notifications;

use App\Models\AppNotification;
use App\Models\User;
use App\Support\Tenancy\TenantScopeBypass;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Fans a notification out to in-app, push and email channels.
 * In-app rows are the source of truth; push/email are best-effort deliveries.
 *
 * @phpstan-type Payload array{type: string, title: string, body: string, severity?: string, link?: ?string, data?: array<string, mixed>, channels?: list<string>}
 */
class Notifier
{
    /** Notification types people can tune in their settings. */
    public const CATEGORIES = ['alert', 'anomaly', 'report', 'mention'];

    public function __construct(private readonly PushGateway $push) {}

    /**
     * @param  array<string>  $userIds
     * @param  Payload  $payload
     */
    public function toUsers(array $userIds, string $organisationId, array $payload): int
    {
        $users = TenantScopeBypass::run(fn () => User::whereIn('id', $userIds)->where('organisation_id', $organisationId)->where('status', 'active')->get());
        foreach ($users as $user) {
            $this->deliver($user, $payload);
        }

        return $users->count();
    }

    /** @param  Payload  $payload */
    public function toPermission(string $permission, string $organisationId, array $payload): int
    {
        $users = TenantScopeBypass::run(fn () => User::with('roles')->where('organisation_id', $organisationId)->where('status', 'active')->get())
            ->filter(fn (User $u) => $u->hasPermission($permission));
        foreach ($users as $user) {
            $this->deliver($user, $payload);
        }

        return $users->count();
    }

    /** @param  Payload  $payload */
    private function deliver(User $user, array $payload): void
    {
        $channels = self::channelsFor($user, $payload['type'], $payload['channels'] ?? ['in_app']);
        $notification = AppNotification::create([
            'organisation_id' => $user->organisation_id,
            'user_id' => $user->id,
            'type' => $payload['type'],
            'severity' => $payload['severity'] ?? 'info',
            'title' => $payload['title'],
            'body' => $payload['body'],
            'link' => $payload['link'] ?? null,
            'data' => $payload['data'] ?? [],
            'channels' => $channels,
        ]);

        if (in_array('push', $channels, true)) {
            $this->push->send($user, $notification);
        }
        if (in_array('email', $channels, true)) {
            try {
                Mail::raw($payload['body']."\n\n".url($payload['link'] ?? '/'), fn ($m) => $m->to($user->email)->subject($payload['title']));
            } catch (Throwable $e) {
                logger()->warning('notify.email_failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The sender's channels narrowed by the person's preferences. In-app is the
     * record and always kept; email and push can be turned off per category.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function channelsFor(User $user, string $type, array $requested): array
    {
        $prefs = (array) (($user->preferences ?? [])['notifications'][$type] ?? []);

        return array_values(array_filter(
            array_unique(['in_app', ...$requested]),
            fn ($channel) => $channel === 'in_app' || ($prefs[$channel] ?? true) !== false,
        ));
    }
}
