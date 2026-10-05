<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DonorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DonorController extends Controller
{
    public function index(Request $request, DonorService $service): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'blood_type' => ['sometimes', 'nullable', Rule::in(['all', 'Unknown', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'eligibility' => ['sometimes', 'nullable', Rule::in(['all', 'eligible', 'temporarily_ineligible', 'unassessed', 'indeterminate'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        return response()->json($service->listing($filters))->header('Cache-Control', 'private, no-store');
    }
}
