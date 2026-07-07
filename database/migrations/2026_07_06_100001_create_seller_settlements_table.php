<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlements', function (Blueprint $table) {
            $table->id();

            // Human-readable settlement number (SS-2026-00001)
            $table->string('settlement_number', 30)->unique();

            // One settlement per registered lot — the unique key is the idempotency
            // guarantee: registration fee / commission / no-sale fee can never
            // duplicate for the same vehicle-in-auction.
            $table->foreignId('lot_id')->unique()->constrained('auction_lots');
            $table->foreignId('auction_id')->constrained('auctions');
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('vehicle_id')->constrained('vehicles');

            // Outcome is null while the settlement is only seeded (registration);
            // set to 'sold' or 'no_sale' once the lot is finalized.
            $table->string('outcome', 10)->nullable();

            // Sale price = winning bid (lot.sold_price). Null until sold.
            $table->unsignedInteger('sale_price')->nullable();

            // Fee line items (seller-side)
            $table->decimal('registration_fee', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('no_sale_fee', 10, 2)->default(0);
            // Future-ready: signed sum of manual adjustments (deductions/credits)
            $table->decimal('adjustments_total', 10, 2)->default(0);

            // Net payable to the seller. Signed — negative on no-sale (seller owes
            // the registration + no-sale fees).
            $table->decimal('net_proceeds', 10, 2)->default(0);

            // pending → ready_for_release → check_issued → paid
            // no_sale (terminal, fees due) | void
            $table->string('status', 20)->default('pending');

            // Proceeds become eligible ~7 calendar days after the auction date.
            $table->date('release_date')->nullable();

            // Check workflow
            $table->string('check_number', 50)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('check_issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // JSON snapshot of the fee rules applied at finalization (audit trail)
            $table->json('fee_snapshot')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['auction_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlements');
    }
};
