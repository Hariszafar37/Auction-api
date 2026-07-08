<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_actions', function (Blueprint $table) {
            // Security-sensitive admin actions — capture the actor's request
            // context for compliance and investigations.
            $table->string('ip_address', 45)->nullable()->after('performed_by'); // IPv4/IPv6
            $table->text('user_agent')->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('account_actions', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
