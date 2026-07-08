<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            // Seller-side fee configuration. DB-driven so amounts can be tuned by
            // an admin without a deploy, mirroring the buyer fee engine.
            $table->decimal('seller_registration_fee', 10, 2)->default(50)->after('late_fee_amount');
            $table->decimal('seller_commission_rate', 5, 2)->default(10)->after('seller_registration_fee');   // percent
            $table->unsignedInteger('seller_commission_threshold')->default(1000)->after('seller_commission_rate');
            $table->decimal('seller_commission_flat', 10, 2)->default(100)->after('seller_commission_threshold');
            $table->decimal('seller_no_sale_fee', 10, 2)->default(50)->after('seller_commission_flat');
            // Calendar days after the auction date before proceeds are released.
            $table->unsignedSmallInteger('seller_release_days')->default(7)->after('seller_no_sale_fee');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table) {
            $table->dropColumn([
                'seller_registration_fee',
                'seller_commission_rate',
                'seller_commission_threshold',
                'seller_commission_flat',
                'seller_no_sale_fee',
                'seller_release_days',
            ]);
        });
    }
};
