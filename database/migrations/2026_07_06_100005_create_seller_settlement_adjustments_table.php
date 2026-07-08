<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('seller_settlements')->cascadeOnDelete();
            // Signed: positive increases net proceeds (credit to the seller),
            // negative decreases it (a deduction, e.g. carried no-sale fees).
            $table->decimal('amount', 10, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('settlement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlement_adjustments');
    }
};
