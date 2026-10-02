<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\Insight;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Global search across dashboards, reports, datasets, metrics (incl. synonyms),
 * insights and the user's conversations. Natural-language questions are
 * flagged so the UI can hand them to the AI analyst.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => [], 'ask_ai' => false]);
        }
        $like = '%'.addcslashes($q, '%_').'%';
        $user = $request->user();
        $results = [];

        if ($user->hasPermission('dashboards.view')) {
            foreach (Dashboard::where(fn ($w) => $w->where('visibility', 'organisation')->orWhere('owner_id', $user->id))
                ->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('description', 'ilike', $like))->limit(5)->get() as $d) {
                $results[] = ['type' => 'dashboard', 'id' => $d->id, 'title' => $d->title, 'subtitle' => $d->description, 'link' => "/dashboards/{$d->id}"];
            }
        }
        if ($user->hasPermission('reports.view')) {
            foreach (Report::where(fn ($w) => $w->where('owner_id', $user->id)->orWhere('status', 'published'))->where('title', 'ilike', $like)->limit(5)->get() as $r) {
                $results[] = ['type' => 'report', 'id' => $r->id, 'title' => $r->title, 'subtitle' => ucfirst($r->status), 'link' => "/reports/{$r->id}"];
            }
        }
        $metrics = DB::table('metrics')->join('semantic_models', 'semantic_models.id', '=', 'metrics.semantic_model_id')
            ->where('semantic_models.organisation_id', $user->organisation_id)
            ->where(fn ($w) => $w->where('metrics.label', 'ilike', $like)->orWhere('metrics.description', 'ilike', $like)->orWhereRaw('metrics.synonyms::text ILIKE ?', [$like]))
            ->limit(6)->get(['metrics.key', 'metrics.label', 'metrics.description', 'semantic_models.key as model_key', 'semantic_models.name as model_name']);
        foreach ($metrics as $m) {
            $results[] = ['type' => 'metric', 'id' => "{$m->model_key}.{$m->key}", 'title' => $m->label, 'subtitle' => $m->model_name.($m->description ? ' · '.$m->description : ''), 'link' => "/explore?metric={$m->model_key}.{$m->key}"];
        }
        if ($user->hasPermission('data.view')) {
            foreach (Dataset::where('label', 'ilike', $like)->orWhere('description', 'ilike', $like)->limit(4)->get() as $d) {
                $results[] = ['type' => 'dataset', 'id' => $d->id, 'title' => $d->label, 'subtitle' => number_format((int) $d->row_count).' rows', 'link' => "/data/datasets/{$d->id}"];
            }
        }
        foreach (Insight::where('title', 'ilike', $like)->latest()->limit(4)->get() as $i) {
            $results[] = ['type' => 'insight', 'id' => $i->id, 'title' => $i->title, 'subtitle' => $i->body, 'link' => '/investigate?metric='.urlencode((string) $i->metric_key)];
        }
        foreach (AiConversation::where('user_id', $user->id)->where('title', 'ilike', $like)->latest('updated_at')->limit(4)->get() as $c) {
            $results[] = ['type' => 'conversation', 'id' => $c->id, 'title' => $c->title, 'subtitle' => 'Conversation · '.$c->updated_at->diffForHumans(), 'link' => "/ai?conversation={$c->id}"];
        }

        $looksLikeQuestion = (bool) preg_match('/^(what|why|how|show|which|who|when|compare|list|give|is|are|did|create|alert|forecast)\b|\?$/i', $q) || str_word_count($q) >= 4;

        return response()->json(['data' => $results, 'ask_ai' => $looksLikeQuestion && $user->hasPermission('ai.use')]);
    }
}
