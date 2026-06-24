<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Auction Entry Terms & Conditions — versioned history.
 *
 * One document governs every auction (no per-auction terms). Each admin save
 * creates a NEW row with an auto-incremented minor version (1.0 → 1.1 → 1.2)
 * and flips is_current; prior rows are retained so acceptances can reference
 * the exact text the user agreed to.
 *
 * NOTE: This is entirely separate from the registration-level `users.terms_version`
 * (site/registration terms) — they are unrelated concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_terms', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();                 // "1.0", "1.1", …
            $table->string('header')->default('Enter Auction');
            $table->text('intro')->nullable();
            $table->json('important_information')->nullable();    // array of bullet strings
            $table->longText('full_terms_content')->nullable();   // complete T&C body (rich text)
            $table->string('checkbox_label');                     // single required-checkbox text
            $table->string('fees_url')->nullable();               // "View Auction Fees" — hidden if null
            $table->string('payment_policy_url')->nullable();     // "View Payment Policy" — hidden if null
            $table->boolean('is_current')->default(false)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_terms');
    }
};
