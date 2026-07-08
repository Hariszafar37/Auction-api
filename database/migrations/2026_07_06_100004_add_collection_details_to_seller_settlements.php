<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table) {
            // How the seller's outstanding no-sale/registration fees were collected.
            $table->string('collection_method', 30)->nullable()->after('collected_at');   // cash | check | card | wire | account_credit | other
            $table->string('collection_reference', 100)->nullable()->after('collection_method');
            $table->foreignId('collected_by')->nullable()->after('collection_reference')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('seller_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('collected_by');
            $table->dropColumn(['collection_method', 'collection_reference']);
        });
    }
};
