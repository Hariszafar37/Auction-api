<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Independent capability switches, decoupled from account status so
            // an admin can revoke bidding while leaving selling intact (and vice
            // versa). Default true preserves existing behaviour for all rows.
            $table->boolean('bidding_enabled')->default(true)->after('status');
            $table->boolean('selling_enabled')->default(true)->after('bidding_enabled');
        });

        // Add 'blocked' to the status enum (MySQL only; SQLite stores enums as strings).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN status
                ENUM('pending_email_verification','pending_password','pending_activation','active','suspended','blocked')
                NOT NULL DEFAULT 'pending_email_verification'");
        }

        // Immutable audit trail for administrative account actions
        // (suspend / block / reactivate / bidding & selling toggles).
        // Kept separate from approval_histories so the approval dashboard/history
        // feed stays a pure record of dealer/business/seller/gov/POA approvals.
        Schema::create('account_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            // suspended | blocked | reactivated | bidding_disabled | bidding_enabled | selling_disabled | selling_enabled
            $table->string('action', 40)->index();
            $table->string('previous_value', 40)->nullable();
            $table->string('new_value', 40)->nullable();
            $table->text('reason')->nullable();
            // The admin who performed the action (nullable — system actions).
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['subject_user_id', 'performed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_actions');

        if (DB::getDriverName() === 'mysql') {
            // Downgrade any blocked users so the narrowed enum doesn't reject them.
            DB::table('users')->where('status', 'blocked')->update(['status' => 'suspended']);
            DB::statement("ALTER TABLE users MODIFY COLUMN status
                ENUM('pending_email_verification','pending_password','pending_activation','active','suspended')
                NOT NULL DEFAULT 'pending_email_verification'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bidding_enabled', 'selling_enabled']);
        });
    }
};
