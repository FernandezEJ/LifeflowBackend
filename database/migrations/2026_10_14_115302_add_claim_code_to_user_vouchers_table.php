<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add claim metadata only; legacy status, tokens, deadlines and history are untouched. */
    public function up(): void
    {
        Schema::table('user_vouchers', function (Blueprint $table): void {
            $table->text('claim_code_encrypted')->nullable();
            $table->char('claim_code_digest', 64)->nullable()->unique();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('claimed_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Claim attribution protects voucher history. Roll forward instead.');
    }
};
