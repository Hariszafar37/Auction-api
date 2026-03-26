<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount');
            // manual=buyer typed it, proxy=buyer set max, auto=system auto-bid, auctioneer=admin
            $table->enum('type', ['manual', 'proxy', 'auto', 'auctioneer'])->default('manual');
            $table->boolean('is_winning')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('placed_at');
            $table->timestamps();

            $table->index(['auction_lot_id', 'amount']);
            $table->index(['auction_lot_id', 'is_winning']);
            $table->index(['user_id', 'auction_lot_id']);
            $table->index('placed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
