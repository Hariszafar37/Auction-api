<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('auction_lots')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();

            $table->string('pickup_location');
            $table->text('delivery_address');
            $table->string('preferred_dates')->nullable();
            $table->text('notes')->nullable();

            $table->string('status')->default('pending');
            $table->decimal('quote_amount', 10, 2)->nullable();
            $table->text('admin_notes')->nullable();

            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('arranged_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_requests');
    }
};
