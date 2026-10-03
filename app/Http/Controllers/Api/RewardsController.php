<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\UserVoucher;
use App\Services\PointsService;
use App\Services\RewardRedemptionService;
use App\Services\VoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardsController extends Controller
{
    // ========================================
    // PRIVATE LEDGER AND CURRENT CATALOGUE
    // IDs and balances always come from the authenticated account.
    // ========================================
    public function summary(Request $request, PointsService $points): JsonResponse
    {
        return response()->json($points->summary($request->user()->id));
    }

    public function transactions(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'type' => ['sometimes', 'in:donation_reward,reward_redemption']]);

        return response()->json(PointTransaction::where('user_id', $request->user()->id)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->orderByDesc('id')->paginate(20));
    }

    public function rewards(): JsonResponse
    {
        return response()->json(['data' => Reward::current()->orderByDesc('id')->get(), 'request_key' => (string) Str::uuid()]);
    }

    public function reward(int $id): JsonResponse
    {
        return response()->json(['reward' => Reward::current()->findOrFail($id)]);
    }

    // ========================================
    // CONFIRMED SPENDING
    // Only a request UUID is accepted; user, price, stock and status are server-owned.
    // Response includes current balance/stock so the UI never guesses a debit.
    // ========================================
    public function redeem(Request $request, int $id, RewardRedemptionService $redemption, VoucherService $vouchers, PointsService $points): JsonResponse
    {
        $this->only($request, ['request_key']);
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $voucher = $redemption->redeem($request->user()->id, $id, $data['request_key']);
        $vouchers->reconcile($request->user()->id);

        return response()->json(['voucher' => $vouchers->payload($voucher->refresh()),
            'summary' => $points->summary($request->user()->id), 'reward' => Reward::withTrashed()->findOrFail($id),
            'server_time' => now()->toISOString()]);
    }

    // ========================================
    // OWNED HISTORY AND FIVE-MINUTE ACTIVATION
    // Reads reconcile elapsed windows even when the app has been closed.
    // Empty activation bodies prevent a client from choosing a deadline.
    // ========================================
    public function vouchers(Request $request, VoucherService $service): JsonResponse
    {
        $request->validate(['status' => ['sometimes', 'in:available,active,redeemed,expired'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $service->reconcile($request->user()->id);
        $page = UserVoucher::where('user_id', $request->user()->id)->with('reward')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($v) => $service->payload($v)));

        return response()->json([...$page->toArray(), 'server_time' => now()->toISOString()]);
    }

    public function voucher(Request $request, int $id, VoucherService $service): JsonResponse
    {
        $service->reconcile($request->user()->id);
        $voucher = UserVoucher::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json(['voucher' => $service->payload($voucher), 'server_time' => now()->toISOString()]);
    }

    public function activate(Request $request, int $id, VoucherService $service): JsonResponse
    {
        $this->only($request, []);
        $voucher = $service->activate($request->user()->id, $id);

        return response()->json(['voucher' => $service->payload($voucher), 'server_time' => now()->toISOString()]);
    }

    private function only(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['request' => 'Unsupported request fields.']);
        }
    }
}
