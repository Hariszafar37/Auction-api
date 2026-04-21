<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->unique()->constrained('auction_lots')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();

            // Gate pass
            $table->uuid('gate_pass_token')->nullable()->unique();
            $table->timestamp('gate_pass_generated_at')->nullable();

            // Pickup lifecycle
            $table->string('pickup_status')->default('awaiting_payment');
            $table->text('pickup_notes')->nullable();
            $table->timestamp('picked_up_at')->nullable();

            // Document milestones
            $table->timestamp('title_received_at')->nullable();
            $table->timestamp('title_verified_at')->nullable();
            $table->timestamp('title_released_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_details');
    }
};
