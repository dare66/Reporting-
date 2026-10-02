<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiFeedback;
use App\Models\AiRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Conversation persistence for the AI service. The AI service authenticates
 * as the end user (forwarding their JWT), so ownership and tenancy apply.
 */
class AiController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function conversations(Request $request): JsonResponse
    {
        return response()->json(['data' => AiConversation::where('user_id', $request->user()->id)->latest('updated_at')->limit(50)->get(['id', 'title', 'updated_at', 'context'])]);
    }

    public function conversation(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => AiConversation::where('user_id', $request->user()->id)->with('messages')->findOrFail($id)]);
    }

    public function createConversation(Request $request): JsonResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200']);

        return response()->json(['data' => AiConversation::create(['user_id' => $request->user()->id, 'title' => $data['title']])], 201);
    }

    public function deleteConversation(Request $request, string $id): JsonResponse
    {
        AiConversation::where('user_id', $request->user()->id)->where('id', $id)->delete();

        return response()->json(null, 204);
    }

    /** Persists a completed run: user turn, assistant turn, trace, evidence and usage. */
    public function recordRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'nullable|uuid', 'question' => 'required|string|max:4000', 'answer' => 'required|string', 'blocks' => 'array',
            'intent' => 'nullable|string', 'status' => 'required|in:succeeded,failed,refused', 'planner' => 'nullable|string', 'model' => 'nullable|string',
            'trace' => 'array', 'evidence' => 'array', 'tokens_in' => 'integer', 'tokens_out' => 'integer', 'cost_usd' => 'numeric',
            'latency_ms' => 'integer', 'error' => 'nullable|string', 'context' => 'array',
        ]);
        $user = $request->user();

        [$conversation, $run] = DB::transaction(function () use ($data, $user) {
            $conversation = isset($data['conversation_id'])
                ? AiConversation::where('user_id', $user->id)->findOrFail($data['conversation_id'])
                : AiConversation::create(['user_id' => $user->id, 'title' => mb_strimwidth($data['question'], 0, 80, '…')]);
            $run = AiRun::create([
                'user_id' => $user->id, 'conversation_id' => $conversation->id, 'question' => $data['question'], 'intent' => $data['intent'] ?? null,
                'status' => $data['status'], 'planner' => $data['planner'] ?? null, 'model' => $data['model'] ?? null,
                'trace' => $data['trace'] ?? [], 'evidence' => $data['evidence'] ?? [], 'tokens_in' => $data['tokens_in'] ?? 0,
                'tokens_out' => $data['tokens_out'] ?? 0, 'cost_usd' => $data['cost_usd'] ?? 0, 'latency_ms' => $data['latency_ms'] ?? null, 'error' => $data['error'] ?? null,
            ]);
            $conversation->messages()->create(['role' => 'user', 'content' => $data['question'], 'run_id' => $run->id]);
            $conversation->messages()->create(['role' => 'assistant', 'content' => $data['answer'], 'blocks' => $data['blocks'] ?? [], 'run_id' => $run->id]);
            $conversation->update(['context' => $data['context'] ?? $conversation->context ?? []]);
            $conversation->touch();

            return [$conversation, $run];
        });
        $this->audit->record('ai.run', ['resource_type' => 'ai_run', 'resource_id' => $run->id, 'result' => $data['status'] === 'succeeded' ? 'success' : 'failure'],
            ['intent' => $data['intent'] ?? null, 'planner' => $data['planner'] ?? null, 'evidence_count' => count($data['evidence'] ?? [])]);

        return response()->json(['data' => ['conversation_id' => $conversation->id, 'run_id' => $run->id]], 201);
    }

    public function feedback(Request $request, string $runId): JsonResponse
    {
        $data = $request->validate(['rating' => 'required|in:-1,1', 'comment' => 'nullable|string|max:2000']);
        AiRun::findOrFail($runId);
        $fb = AiFeedback::updateOrCreate(['run_id' => $runId, 'user_id' => $request->user()->id], $data);

        return response()->json(['data' => $fb]);
    }
}
