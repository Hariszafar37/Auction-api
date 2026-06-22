<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin manual release override. When set, gate pass / title / vehicle / document
 * release is permitted despite an unpaid or unverified balance. Fully audited:
 * who overrode, when, and why (reason required at the API layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->foreignId('release_overridden_by')->nullable()->after('title_released_at')->constrained('users')->nullOnDelete();
            $table->timestamp('release_overridden_at')->nullable()->after('release_overridden_by');
            $table->string('release_override_reason', 500)->nullable()->after('release_overridden_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropForeign(['release_overridden_by']);
            $table->dropColumn(['release_overridden_by', 'release_overridden_at', 'release_override_reason']);
        });
    }
};
