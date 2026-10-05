<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reserve a government consignor asks for, set on the vehicle before it
 * is listed.
 *
 * Government users do not put their own vehicles into auctions — an admin
 * does — so there is no lot yet when they choose a reserve. This column holds
 * it until then and pre-fills the admin's Add Lot form. The lot's own
 * reserve_price stays the one bidding, If Sale and settlements use.
 *
 * Private, like the lot reserve: only the admin and owner vehicle responses
 * expose it, never the public inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedInteger('reserve_price')->nullable()->after('title_state');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('reserve_price');
        });
    }
};
