<?php

// Creates the business_profiles table for admin approval tracking.
// Mirrors the dealer_profiles pattern: activation wizard data stays in
// user_business_information; this table stores only approval state +
// a minimal denormalized subset for the admin queue view.
//
// The up() method also backfills a row for any existing business user who
// completed activation before this migration (status = 'pending_activation').
// Without the backfill those users would be invisible to the admin queue.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Denormalized subset for admin list view (avoids join on every page load)
            $table->string('legal_business_name')->nullable();
            $table->string('entity_type', 50)->nullable();

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

        // Backfill: create a pending BusinessProfile for every business user who
        // completed activation before this migration ran.
        // Condition: account_type = 'business' AND status = 'pending_activation'
        // (these users called complete() but never got a business_profiles row).
        $pendingBusinessUsers = DB::table('users')
            ->where('account_type', 'business')
            ->where('status', 'pending_activation')
            ->whereNotNull('activation_completed_at')
            ->get(['id', 'activation_completed_at']);

        foreach ($pendingBusinessUsers as $user) {
            // Pull the business info if it exists for denormalized fields
            $bizInfo = DB::table('user_business_information')
                ->where('user_id', $user->id)
                ->first(['legal_business_name', 'entity_type']);

            DB::table('business_profiles')->insertOrIgnore([
                'user_id'              => $user->id,
                'legal_business_name'  => $bizInfo?->legal_business_name,
                'entity_type'          => $bizInfo?->entity_type,
                'approval_status'      => 'pending',
                'packet_accepted_at'   => $user->activation_completed_at,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_profiles');
    }
};
