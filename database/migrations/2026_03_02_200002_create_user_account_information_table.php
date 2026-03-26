<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_account_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth');
            $table->string('address');
            $table->string('country', 2);
            $table->string('state', 100);
            $table->string('county', 100)->nullable();
            $table->string('city', 100);
            $table->string('zip_postal_code', 20);
            $table->string('driver_license_number', 100);
            $table->date('driver_license_expiration_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_information');
    }
};
