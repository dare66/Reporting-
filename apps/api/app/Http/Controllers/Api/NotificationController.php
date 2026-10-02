<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = AppNotification::where('user_id', $request->user()->id)->latest()->limit(100)->get();

        return response()->json(['data' => $items, 'unread' => $items->whereNull('read_at')->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->where('id', $id)->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Server-sent events: pushes new notifications to the open app.
     * Bounded lifetime; EventSource reconnects automatically with Last-Event-ID.
     */
    public function stream(Request $request): StreamedResponse
    {
        $userId = $request->user()->id;
        $since = $request->header('Last-Event-ID') ? AppNotification::where('id', $request->header('Last-Event-ID'))->value('created_at') : now();

        return response()->stream(function () use ($userId, $since) {
            $deadline = time() + 55;
            echo "retry: 3000\n\n";
            while (time() < $deadline && ! connection_aborted()) {
                foreach (AppNotification::where('user_id', $userId)->where('created_at', '>', $since)->oldest()->get() as $n) {
                    echo "id: {$n->id}\nevent: notification\ndata: ".json_encode($n)."\n\n";
                    $since = $n->created_at;
                }
                echo ": keepalive\n\n";
                @ob_flush();
                flush();
                sleep(3);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
