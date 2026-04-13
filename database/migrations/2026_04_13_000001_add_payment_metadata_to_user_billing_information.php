<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend user_billing_information with PCI-safe payment metadata.
 * Raw PAN and CVV are never persisted — only brand, last_four, and expiry
 * are stored so we can (a) enforce bidding and (b) display the card on file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->string('cardholder_name', 100)->nullable()->after('payment_method_added');
            $table->string('card_brand', 20)->nullable()->after('cardholder_name');
            $table->string('card_last_four', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_expiry_month')->nullable()->after('card_last_four');
            $table->unsignedSmallInteger('card_expiry_year')->nullable()->after('card_expiry_month');
        });
    }

    public function down(): void
    {
        Schema::table('user_billing_information', function (Blueprint $table) {
            $table->dropColumn([
                'cardholder_name',
                'card_brand',
                'card_last_four',
                'card_expiry_month',
                'card_expiry_year',
            ]);
        });
    }
};
