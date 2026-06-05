<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persist the reusable Stripe PaymentMethod id (pm_...) saved via SetupIntent.
     * This is the chargeable handle used for off-session deposit charges on win.
     * The brand/last_four/expiry columns remain for display only.
     */
    public function up(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->string('stripe_payment_method_id', 100)->nullable()->after('payment_method_added');
        });
    }

    public function down(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->dropColumn('stripe_payment_method_id');
        });
    }
};
