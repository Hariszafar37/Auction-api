<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bid_increments', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_amount');              // lower bound (inclusive)
            $table->unsignedInteger('max_amount')->nullable();  // upper bound (inclusive), null = no upper limit
            $table->unsignedInteger('increment');               // amount to add per step
            $table->timestamps();

            $table->index('min_amount');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_increments');
    }
};
