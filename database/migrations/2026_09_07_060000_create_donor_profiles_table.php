<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // DONOR PERSONAL INFORMATION
    // Stores one profile per account and prevents duplicate mobile numbers.
    // Deleting an account also removes its linked profile.
    // ========================================
    public function up(): void
    {
        Schema::create('donor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('mobile_number', 13)->unique();
            $table->date('birth_date');
            $table->enum('gender', ['Male', 'Female']);
            $table->enum('blood_type', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']);
            $table->timestamps();
        });
    }

    // ========================================
    // MIGRATION REVERSAL
    // Defines Laravel's rollback operation; normal setup only runs up().
    // ========================================
    public function down(): void
    {
        Schema::dropIfExists('donor_profiles');
    }
};
