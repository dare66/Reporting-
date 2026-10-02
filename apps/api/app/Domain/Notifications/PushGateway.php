<?php

namespace App\Domain\Notifications;

use App\Models\AppNotification;
use App\Models\User;

/** Mobile push delivery (FCM/APNs). Bound in AppServiceProvider. */
interface PushGateway
{
    public function send(User $user, AppNotification $notification): void;
}
