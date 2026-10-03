<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAnnouncementRequest;
use App\Services\Admin\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:all,active,draft,expired,deleted'],
            'month' => ['sometimes', 'integer', 'between:1,12'], 'year' => ['sometimes', 'integer', 'between:1900,9999']]);

        return response()->json($this->service->listing($filters));
    }

    public function show(int $announcement): JsonResponse
    {
        return response()->json(['announcement' => $this->service->profile($this->service->find($announcement, true))]);
    }

    public function store(SaveAnnouncementRequest $request): JsonResponse
    {
        return response()->json(['announcement' => $this->service->profile($this->service->save($request->user(), $request->validated()))], 201);
    }

    public function update(SaveAnnouncementRequest $request, int $announcement): JsonResponse
    {
        return response()->json(['announcement' => $this->service->profile($this->service->save($request->user(), $request->validated(), $announcement))]);
    }

    public function publish(Request $request, int $announcement): JsonResponse
    {
        return $this->change($request, $announcement, 'publish');
    }

    public function draft(Request $request, int $announcement): JsonResponse
    {
        return $this->change($request, $announcement, 'draft');
    }

    public function destroy(Request $request, int $announcement): JsonResponse
    {
        return $this->change($request, $announcement, 'delete');
    }

    public function restore(Request $request, int $announcement): JsonResponse
    {
        return $this->change($request, $announcement, 'restore');
    }

    private function change(Request $request, int $id, string $action): JsonResponse
    {
        return response()->json(['announcement' => $this->service->profile($this->service->transition($request->user(), $id, $action))]);
    }
}
