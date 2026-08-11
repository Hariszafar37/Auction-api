<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-close scheduling.
 *
 * Deliberately does NOT reuse auctions.ends_at: that column records when the
 * auction *actually* ended and is load-bearing downstream (seller settlement
 * release date = ends_at + 7 days, invoice due-date base). Pre-setting it to a
 * future scheduled time would silently corrupt both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            // Auction-wide default closing time. Lots without their own
            // scheduled_close_at fall back to this.
            $table->timestamp('scheduled_end_at')->nullable()->after('starts_at');
        });

        Schema::table('auction_lots', function (Blueprint $table) {
            // Per-lot closing time — overrides the auction-wide default.
            // When this moment passes the lot enters its final countdown.
            $table->timestamp('scheduled_close_at')->nullable()->after('countdown_ends_at');

            $table->index(['status', 'scheduled_close_at'], 'auction_lots_status_scheduled_close_idx');
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropIndex('auction_lots_status_scheduled_close_idx');
            $table->dropColumn('scheduled_close_at');
        });

        Schema::table('auctions', function (Blueprint $table) {
            $table->dropColumn('scheduled_end_at');
        });
    }
};
