<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->softDeletes();
            $table->string('amount_mode', 10)->default('custom');
            $table->string('image_path')->nullable();
        });
        Schema::table('user_vouchers', function (Blueprint $table): void {
            $table->json('reward_snapshot')->nullable();
        });
        // Freeze the details currently available for legacy vouchers. Earlier
        // edits cannot be reconstructed; future edits never rewrite this snapshot.
        DB::table('user_vouchers')->orderBy('id')->chunkById(100, function ($vouchers): void {
            $rewards = DB::table('rewards')->whereIn('id', $vouchers->pluck('reward_id'))->get()->keyBy('id');
            foreach ($vouchers as $voucher) {
                $reward = $rewards->get($voucher->reward_id);
                if ($reward) {
                    DB::table('user_vouchers')->where('id', $voucher->id)->update(['reward_snapshot' => json_encode([
                        'id' => $reward->id, 'name' => $reward->name, 'description' => $reward->description,
                        'voucher_value' => $reward->voucher_value, 'points_cost' => $voucher->points_spent,
                        'image_url' => $reward->image_url,
                    ], JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

    public function down(): void
    {
        throw new LogicException('Reward snapshots protect owned voucher history. Roll forward instead.');
    }
};
