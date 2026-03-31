<?php

// Creates the seller_profiles table for individual seller approval tracking.
// Mirrors the business_profiles pattern: basic account info stays in
// user_account_information; this table stores only the seller application
// data and approval state for the admin queue.
//
// Individual sellers apply post-activation (they are already 'active' users).
// On approval they receive the 'seller' Spatie role — they retain 'buyer'.
// On rejection the record persists (rejected status) allowing re-application
// via updateOrCreate in the controller.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Seller application data
            $table->text('application_notes')->nullable();
            $table->string('vehicle_types', 500)->nullable();

            // Approval state
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();

            // Audit
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('packet_accepted_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
