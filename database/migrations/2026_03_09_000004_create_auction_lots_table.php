<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('lot_number');

            // Lot lifecycle state
            $table->enum('status', [
                'pending',
                'open',
                'countdown',
                'if_sale',
                'sold',
                'reserve_not_met',
                'no_sale',
                'cancelled',
            ])->default('pending');

            // Pricing
            $table->unsignedInteger('starting_bid')->default(100);   // in dollars
            $table->unsignedInteger('reserve_price')->nullable();     // private — not shown to bidders

            // Current bid state
            $table->unsignedInteger('current_bid')->nullable();
            $table->foreignId('current_winner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('bid_count')->default(0);

            // Seller approval ("If Sale") settings
            $table->boolean('requires_seller_approval')->default(false);
            $table->timestamp('seller_notified_at')->nullable();
            $table->timestamp('seller_decision_deadline')->nullable(); // 48hr window
            $table->timestamp('seller_approved_at')->nullable();

            // Anti-sniping countdown
            $table->timestamp('countdown_ends_at')->nullable();
            $table->unsignedSmallInteger('countdown_seconds')->default(30);
            $table->unsignedTinyInteger('countdown_extensions')->default(0);

            // Lot timing
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Sale outcome
            $table->unsignedInteger('sold_price')->nullable();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['auction_id', 'lot_number']);
            $table->index(['status', 'countdown_ends_at']);
            $table->index(['auction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_lots');
    }
};
