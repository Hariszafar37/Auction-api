<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit record of a buyer acknowledging the payment requirements
 * and release restrictions before proceeding with a non-card method.
 * Captures user, invoice, method, timestamp, IP and user-agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_acknowledgements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            // stripe_card | wire | cash | check
            $table->string('payment_method', 20);

            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_acknowledgements');
    }
};
