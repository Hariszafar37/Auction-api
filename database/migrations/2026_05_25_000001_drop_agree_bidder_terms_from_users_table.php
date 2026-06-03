<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'agree_bidder_terms')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('agree_bidder_terms');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'agree_bidder_terms')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('agree_bidder_terms')->default(false)->after('terms_version');
            });
        }
    }
};
