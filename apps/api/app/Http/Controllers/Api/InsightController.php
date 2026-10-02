<?php

namespace App\Http\Controllers\Api;

use App\Domain\Analytics\InsightEngine;
use App\Http\Controllers\Controller;
use App\Models\Insight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Insight::latest()->limit(20)->get()]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => Insight::findOrFail($id)]);
    }

    public function generate(Request $request, InsightEngine $engine): JsonResponse
    {
        return response()->json(['data' => $engine->generate($request->user(), $request->input('range', 'last_30_days'))]);
    }
}
