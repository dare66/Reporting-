<?php

namespace App\Domain\Notifications;

use App\Models\AppNotification;
use App\Models\User;

/**
 * Development gateway: records pushes in the log. Production binds an FCM
 * gateway once device tokens are registered by the mobile app (Phase 8).
 */
class LogPushGateway implements PushGateway
{
    public function send(User $user, AppNotification $notification): void
    {
        logger()->info('push.send', ['user' => $user->id, 'title' => $notification->title, 'link' => $notification->link]);
    }
}
