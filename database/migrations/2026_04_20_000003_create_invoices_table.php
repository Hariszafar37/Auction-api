<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Human-readable invoice number (INV-2026-00001)
            $table->string('invoice_number', 30)->unique();

            $table->foreignId('lot_id')->constrained('auction_lots');
            $table->foreignId('auction_id')->constrained('auctions');
            $table->foreignId('buyer_id')->constrained('users');
            $table->foreignId('vehicle_id')->constrained('vehicles');

            // Sale price = winning bid (lot.sold_price)
            $table->unsignedInteger('sale_price');

            // Fee line items
            $table->decimal('deposit_amount', 10, 2)->default(0);
            $table->decimal('buyer_fee_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('tags_amount', 10, 2)->default(0);
            $table->unsignedSmallInteger('storage_days')->default(0);
            $table->decimal('storage_fee_amount', 10, 2)->default(0);

            // Totals
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('balance_due', 10, 2);

            // draft → pending → partial → paid | void
            $table->string('status', 20)->default('pending');

            // JSON snapshot of fee rules at invoice creation time (audit trail)
            $table->json('fee_snapshot')->nullable();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['buyer_id', 'status']);
            $table->index('lot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
