<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->timestamp('gate_pass_revoked_at')->nullable()->after('gate_pass_generated_at');
            $table->string('revocation_reason')->nullable()->after('gate_pass_revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropColumn(['gate_pass_revoked_at', 'revocation_reason']);
        });
    }
};
