<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Allow null password (user sets it after email verification)
            $table->string('password')->nullable()->change();

            // Name breakdown
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');

            // Contact
            $table->string('primary_phone', 30)->nullable()->after('last_name');
            $table->string('secondary_phone', 30)->nullable()->after('primary_phone');

            // Consent / terms
            $table->boolean('consent_marketing')->default(false)->after('secondary_phone');
            $table->timestamp('agreed_terms_at')->nullable()->after('consent_marketing');

            // Password setup timestamp
            $table->timestamp('password_set_at')->nullable()->after('agreed_terms_at');

            // Account type: individual or dealer
            $table->enum('account_type', ['individual', 'dealer'])->nullable()->after('password_set_at');

            // Activation timestamp
            $table->timestamp('activation_completed_at')->nullable()->after('account_type');
        });

        // Change status enum to new values (MySQL only; SQLite stores enums as strings and skips this)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN status
                ENUM('pending_email_verification','pending_password','pending_activation','active','suspended')
                NOT NULL DEFAULT 'pending_email_verification'");
        }
    }

    public function down(): void
    {
        // Restore status enum (MySQL only)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN status
                ENUM('pending','active','suspended')
                NOT NULL DEFAULT 'pending'");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->dropColumn([
                'first_name',
                'last_name',
                'primary_phone',
                'secondary_phone',
                'consent_marketing',
                'agreed_terms_at',
                'password_set_at',
                'account_type',
                'activation_completed_at',
            ]);
        });
    }
};
