<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_histories', function (Blueprint $table) {
            $table->id();
            // Approval domain: dealer | business | seller | government | poa
            $table->string('approval_type', 30)->index();
            // Primary key of the underlying record (profile id or power_of_attorney id)
            $table->unsignedBigInteger('related_id')->index();
            // The applicant the approval belongs to (nullable — record may be deleted later)
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            // applied | approved | rejected (extensible)
            $table->string('action', 30);
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->text('remarks')->nullable();
            // The admin who performed the action (nullable — system/synthesized actions)
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['approval_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_histories');
    }
};
