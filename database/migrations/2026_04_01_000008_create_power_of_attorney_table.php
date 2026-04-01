<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('power_of_attorney', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['upload', 'esign'])->default('upload');
            $table->enum('status', ['pending', 'signed', 'approved', 'rejected'])->default('pending');
            $table->string('file_path', 500)->nullable();
            $table->string('signer_printed_name', 255)->nullable();
            $table->timestamp('esigned_at')->nullable();
            $table->string('esign_ip_address', 45)->nullable();
            $table->text('admin_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('power_of_attorney');
    }
};
