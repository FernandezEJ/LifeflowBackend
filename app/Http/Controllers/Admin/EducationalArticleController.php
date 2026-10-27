<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveEducationalArticleRequest;
use App\Models\EducationalArticle;
use App\Services\EducationalArticleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EducationalArticleController extends Controller
{
    public function __construct(private readonly EducationalArticleService $service) {}

    public function categories(): JsonResponse
    {
        return response()->json(['categories' => EducationalArticle::CATEGORIES]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(['draft', 'published', 'deleted'])],
            'category' => ['sometimes', Rule::in(array_keys(EducationalArticle::CATEGORIES))],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->service->listing($filters, staff: true))->header('Cache-Control', 'private, no-store');
    }

    public function show(int $id): JsonResponse
    {
        return $this->response(EducationalArticle::withTrashed()->findOrFail($id));
    }

    public function store(SaveEducationalArticleRequest $request): JsonResponse
    {
        return $this->response($this->service->save($request->user(), $request->validated()), 201);
    }

    public function update(SaveEducationalArticleRequest $request, int $id): JsonResponse
    {
        return $this->response($this->service->save($request->user(), $request->validated(), $id));
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->response($this->service->transition($request->user(), $id, 'publish'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->response($this->service->transition($request->user(), $id, 'delete'));
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        return $this->response($this->service->transition($request->user(), $id, 'restore'));
    }

    private function response(EducationalArticle $article, int $status = 200): JsonResponse
    {
        return response()->json(['article' => $this->service->profile($article), 'categories' => EducationalArticle::CATEGORIES], $status)->header('Cache-Control', 'private, no-store');
    }
}
