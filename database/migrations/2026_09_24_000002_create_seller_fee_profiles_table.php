<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-account seller fees for government consignors.
 *
 * Government accounts do not settle on the global seller fees in
 * payment_settings. Each one is billed on its own agreed terms — often no fees
 * at all, sometimes a commission or flat charges. A government account with no
 * row here settles at zero. See SellerSettlementService::feeProfileFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_fee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Charged once, when a vehicle is entered into an auction.
            $table->decimal('registration_fee', 10, 2)->default(0);

            // none | percent (commission_value = % of sale price) | flat (commission_value = dollars)
            $table->string('commission_type', 10)->default('none');
            $table->decimal('commission_value', 10, 2)->default(0);

            // Charged when a vehicle is entered but does not sell.
            $table->decimal('no_sale_fee', 10, 2)->default(0);

            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_fee_profiles');
    }
};
