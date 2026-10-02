<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\Notifier;
use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CollaborationController extends Controller
{
    private const RESOURCES = 'dashboard,report,insight,anomaly,metric,dataset';

    public function comments(Request $request): JsonResponse
    {
        $data = $request->validate(['resource_type' => 'required|in:'.self::RESOURCES, 'resource_id' => 'required|uuid']);

        return response()->json(['data' => Comment::with('user:id,name,title')->where($data)->orderBy('created_at')->get()]);
    }

    /** Supports @mentions of people ("@Priya") and of departments ("@Finance"). */
    public function comment(Request $request, Notifier $notifier): JsonResponse
    {
        $data = $request->validate(['resource_type' => 'required|in:'.self::RESOURCES, 'resource_id' => 'required|uuid', 'body' => 'required|string|max:5000',
            'parent_id' => 'nullable|uuid', 'link' => 'nullable|string|max:300']);
        preg_match_all('/@([\p{L}][\p{L}&\-]*)/u', $data['body'], $m);
        $handles = array_unique($m[1]);
        $matches = function (User $u) use ($handles): bool {
            foreach ($handles as $h) {
                if (strcasecmp(explode(' ', $u->name)[0], $h) === 0 || ($u->department && stripos($u->department->name, $h) === 0)) {
                    return true;
                }
            }

            return false;
        };
        $mentioned = User::with('department')->get()->filter($matches)->where('id', '!=', $request->user()->id);

        $comment = Comment::create(Arr::except($data, ['link']) + ['user_id' => $request->user()->id, 'mentions' => $mentioned->pluck('id')->values()]);
        if ($mentioned->isNotEmpty()) {
            $notifier->toUsers($mentioned->pluck('id')->all(), $request->user()->organisation_id, [
                'type' => 'mention', 'title' => $request->user()->name.' mentioned you', 'body' => mb_strimwidth($data['body'], 0, 200, '…'),
                'link' => $data['link'] ?? null, 'data' => ['comment_id' => $comment->id, 'resource_type' => $data['resource_type'], 'resource_id' => $data['resource_id']],
            ]);
        }

        return response()->json(['data' => $comment->load('user:id,name,title'), 'notified' => $mentioned->count()], 201);
    }

    public function resolve(string $id): JsonResponse
    {
        $c = Comment::findOrFail($id);
        $c->update(['resolved_at' => $c->resolved_at ? null : now()]);

        return response()->json(['data' => $c]);
    }

    public function bookmarks(Request $request): JsonResponse
    {
        return response()->json(['data' => Bookmark::where('user_id', $request->user()->id)->get()]);
    }

    public function toggleBookmark(Request $request): JsonResponse
    {
        $data = $request->validate(['resource_type' => 'required|in:'.self::RESOURCES, 'resource_id' => 'required|uuid']);
        $existing = Bookmark::where($data + ['user_id' => $request->user()->id])->first();
        $existing ? $existing->delete() : Bookmark::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['bookmarked' => ! $existing]);
    }
}
