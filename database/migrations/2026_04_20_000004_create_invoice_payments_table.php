<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            // stripe_card | cash | check | deposit | other
            $table->string('method', 20);

            $table->decimal('amount', 10, 2);

            // Stripe PaymentIntent ID, check number, etc.
            $table->string('reference', 200)->nullable();

            // Stripe client_secret forwarded to frontend for card confirmation
            $table->string('stripe_client_secret', 400)->nullable();

            // pending | completed | failed | refunded
            $table->string('status', 20)->default('pending');

            $table->text('notes')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Admin who recorded a cash/check payment
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
