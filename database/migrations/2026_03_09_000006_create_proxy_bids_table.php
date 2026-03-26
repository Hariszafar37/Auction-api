<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('max_amount');   // bidder's maximum — kept private
            $table->boolean('is_active')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // One active proxy per user per lot
            $table->unique(['auction_lot_id', 'user_id']);
            $table->index(['auction_lot_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_bids');
    }
};
