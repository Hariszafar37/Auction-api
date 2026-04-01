<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gov_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique('user_id');
            $table->string('entity_name', 255);
            $table->enum('entity_subtype', ['government', 'charity', 'repo']);
            $table->string('department_division', 255)->nullable();
            $table->string('point_of_contact_name', 255);
            $table->string('contact_title', 255)->nullable();
            $table->string('phone', 30);
            $table->string('office_phone', 30)->nullable();
            $table->string('address', 500);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('zip', 20);
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->string('invite_token', 100)->nullable()->unique();
            $table->timestamp('invite_sent_at')->nullable();
            $table->timestamp('invite_accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gov_profiles');
    }
};
