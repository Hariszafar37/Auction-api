<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which card a stripe_card attempt was raised against: the buyer's
 * saved card on file ('saved') or a card typed into the form ('new').
 *
 * Needed because the two produce materially different PaymentIntents — a saved
 * card attaches `customer` + `payment_method` up front, a new card attaches
 * neither — while sharing the same invoice and amount. Without a discriminator
 * the "reuse the pending intent" lookup in PaymentController could hand a
 * saved-card intent to a buyer who asked for a new card, and the shared Stripe
 * idempotency key would make the second attempt fail outright.
 *
 * Nullable and display-free: existing rows keep working untouched (the lookup
 * reads a NULL card_source as 'new', which is what every pre-existing row is),
 * and every non-card method — deposit, wire, cash, check, ledger entries —
 * simply leaves it null. It is never shown to a buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->string('card_source', 10)->nullable()->after('stripe_client_secret');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropColumn('card_source');
        });
    }
};
