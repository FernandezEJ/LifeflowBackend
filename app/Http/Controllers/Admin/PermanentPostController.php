<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePermanentPostRequest;
use App\Models\PermanentPost;
use App\Services\Admin\PermanentPostService;
use Illuminate\Http\JsonResponse;

class PermanentPostController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['post' => PermanentPost::redCross()])->header('Cache-Control', 'private, no-store');
    }

    public function update(SavePermanentPostRequest $request, PermanentPostService $service): JsonResponse
    {
        return response()->json(['post' => $service->save($request->user(), $request->validated())])->header('Cache-Control', 'private, no-store');
    }
}
