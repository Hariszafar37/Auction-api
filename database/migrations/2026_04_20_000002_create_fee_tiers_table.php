<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fee_configuration_id')
                ->constrained('fee_configurations')
                ->cascadeOnDelete();

            // Sale price range (inclusive both sides; null to = no upper limit)
            $table->unsignedInteger('sale_price_from');
            $table->unsignedInteger('sale_price_to')->nullable();

            // How to compute the fee for this tier
            $table->enum('fee_calculation_type', ['flat', 'percentage']);

            // Dollar amount (flat) or percentage points (e.g. 3.5 = 3.5%)
            $table->decimal('fee_amount', 10, 2);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['fee_configuration_id', 'sale_price_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_tiers');
    }
};
