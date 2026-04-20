<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_configurations', function (Blueprint $table) {
            $table->id();

            // One of: deposit | buyer_fee | tax | tags | storage
            $table->string('fee_type', 30);

            // Human-readable label shown on invoices
            $table->string('label', 100);

            // How the fee is computed:
            //   flat        → fixed dollar amount (amount column)
            //   percentage  → percent of sale price (amount column, e.g. 6.5 = 6.5%)
            //   tiered      → sliding scale via fee_tiers table
            //   per_day     → flat rate × storage_days (amount column, e.g. 25.00)
            //   flat_range  → configurable range; deposit defaults to min_amount
            $table->string('calculation_type', 20);

            // Dollar amount or percentage value (depends on calculation_type)
            $table->decimal('amount', 10, 2)->nullable();

            // For flat_range (deposit): the configurable bounds
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();

            // Location key matching Auction.location; null = global default
            $table->string('location', 200)->nullable()->index();

            // buyer | seller (currently all fees are buyer-side)
            $table->string('applies_to', 10)->default('buyer');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Enforce one rule per (type, location) pair
            $table->unique(['fee_type', 'location'], 'fee_configurations_type_location_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_configurations');
    }
};
