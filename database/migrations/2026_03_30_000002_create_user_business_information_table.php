<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_business_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Business identity
            $table->string('legal_business_name');
            $table->string('dba_name')->nullable();

            // Contact
            $table->string('primary_contact_name');
            $table->string('contact_title');
            $table->string('phone', 30);
            $table->string('office_phone', 30)->nullable();

            // Address
            $table->string('address');
            $table->string('suite')->nullable();
            $table->string('city', 100);
            $table->string('state', 2);
            $table->string('zip', 20);

            // Business details
            $table->enum('entity_type', ['corporation', 'llc', 'partnership', 'nonprofit', 'other']);
            $table->string('state_of_formation', 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_business_information');
    }
};
