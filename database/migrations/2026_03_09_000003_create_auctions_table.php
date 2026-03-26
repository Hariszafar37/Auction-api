<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // set when auction ends
            $table->enum('status', ['draft', 'scheduled', 'live', 'ended', 'cancelled'])->default('draft');
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
