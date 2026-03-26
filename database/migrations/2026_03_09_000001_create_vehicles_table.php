<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('vin', 17)->unique();
            $table->unsignedSmallInteger('year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('mileage')->nullable();
            $table->enum('body_type', ['car', 'truck', 'suv', 'motorcycle', 'boat', 'atv', 'fleet', 'other'])->default('car');
            $table->string('transmission')->nullable();
            $table->string('engine')->nullable();
            $table->string('fuel_type')->nullable();
            // Light system: green = arbitration applies, red = as-is, blue = title attached
            $table->enum('condition_light', ['green', 'red', 'blue'])->default('green');
            $table->text('condition_notes')->nullable();
            $table->boolean('has_title')->default(true);
            $table->string('title_state', 5)->nullable();
            $table->enum('status', ['available', 'in_auction', 'sold', 'withdrawn'])->default('available');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['make', 'model', 'year']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
