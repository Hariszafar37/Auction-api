<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_dealer_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name');
            $table->string('owner_name');
            $table->string('phone', 30);
            $table->string('primary_contact');
            $table->string('license_number', 100);
            $table->date('license_expiration_date');
            $table->string('tax_id_number', 50)->nullable();
            $table->string('dealer_address');
            $table->string('dealer_country', 2);
            $table->string('dealer_city', 100);
            $table->string('dealer_zip_code', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_dealer_information');
    }
};
