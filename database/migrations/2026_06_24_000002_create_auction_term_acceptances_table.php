<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable record that a user accepted the auction Terms & Conditions for a
 * specific auction at a specific version. Acceptance is once per
 * (user, auction, terms_version): a version bump leaves no matching row, which
 * re-prompts the user for that auction.
 *
 * Mirrors the PaymentAcknowledgement audit pattern (IP + user-agent captured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_term_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained('auctions')->cascadeOnDelete();
            $table->foreignId('auction_terms_id')->constrained('auction_terms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('terms_version');             // snapshot of the accepted version
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            // "Accept once per auction per version".
            $table->unique(['user_id', 'auction_id', 'terms_version'], 'auction_terms_user_unique');
            $table->index(['auction_id', 'terms_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_term_acceptances');
    }
};
