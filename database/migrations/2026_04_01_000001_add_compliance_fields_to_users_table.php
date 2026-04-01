<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('registration_ip_address', 45)->nullable()->after('email');
            $table->string('terms_version', 20)->nullable()->after('agreed_terms_at');
            $table->boolean('agree_bidder_terms')->default(false)->after('terms_version');
            $table->boolean('agree_ecomm_consent')->default(false)->after('agree_bidder_terms');
            $table->boolean('agree_accuracy_confirmed')->default(false)->after('agree_ecomm_consent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'middle_name',
                'registration_ip_address',
                'terms_version',
                'agree_bidder_terms',
                'agree_ecomm_consent',
                'agree_accuracy_confirmed',
            ]);
        });
    }
};
