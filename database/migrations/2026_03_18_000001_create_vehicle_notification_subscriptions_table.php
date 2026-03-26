<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')
                  ->constrained('vehicles')
                  ->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            // Timestamp set when the notification has been dispatched.
            // NULL = not yet notified; non-NULL = already sent (prevents duplicates).
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // A user may subscribe once per vehicle.
            $table->unique(['vehicle_id', 'user_id']);

            // Query pattern: fetch all pending subscribers for a given vehicle.
            $table->index(['vehicle_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_notification_subscriptions');
    }
};
