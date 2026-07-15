<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();

            // Stable identifier the notification classes look themselves up by,
            // e.g. 'account_approved.dealer'. Variant-scoped, because the copy
            // genuinely differs per context (dealer vs seller vs government).
            $table->string('key')->unique();

            // The family this variant belongs to ('account_approved'), used to
            // group the admin UI, and the in-app notification `type` string.
            $table->string('group_key')->index();
            $table->string('notification_type');

            $table->string('name');
            $table->string('description')->nullable();

            // 'system'       — fired by application code; copy/channels editable, not deletable
            // 'announcement' — admin-composed, sent manually to an audience
            $table->string('category')->default('system')->index();

            // Master switch, then per-channel switches. in_app covers both the
            // database row and the realtime broadcast — users think of those as
            // one thing ("the bell"), so splitting them would only confuse.
            $table->boolean('enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);

            // Email content
            $table->string('subject')->nullable();
            $table->string('greeting')->nullable();
            $table->text('email_body')->nullable();
            $table->string('action_label')->nullable();

            // In-app content
            $table->string('title')->nullable();
            $table->text('message')->nullable();

            // Placeholders this template may use, and the channels the calling
            // code actually supports. Both are code-owned: the admin UI reads
            // them to render variable chips and to grey out impossible toggles,
            // but never writes them.
            $table->json('available_variables')->nullable();
            $table->json('supported_channels')->nullable();

            // Announcements only
            $table->json('audience')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
