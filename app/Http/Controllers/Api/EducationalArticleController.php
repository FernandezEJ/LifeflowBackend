<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalArticle;
use App\Services\EducationalArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EducationalArticleController extends Controller
{
    public function index(Request $request, EducationalArticleService $service): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($service->listing([]))->header('Cache-Control', 'private, no-store');
    }

    public function show(int $id, EducationalArticleService $service): JsonResponse
    {
        $article = EducationalArticle::where('status', 'published')->findOrFail($id);

        return response()->json(['article' => $service->profile($article)])->header('Cache-Control', 'private, no-store');
    }
}
