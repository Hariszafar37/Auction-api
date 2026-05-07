<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_lot_id')->constrained('auction_lots')->cascadeOnDelete();
            $table->foreignId('bid_id')->nullable()->constrained('bids')->nullOnDelete();
            $table->foreignId('raised_by')->constrained('users');
            $table->text('reason');
            $table->string('status', 30)->default('open');
            $table->text('admin_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
