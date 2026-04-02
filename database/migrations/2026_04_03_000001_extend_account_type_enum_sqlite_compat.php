<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the account_type column for SQLite (test environment).
 *
 * The previous migration (2026_03_30_000001) only ran the ALTER TABLE for MySQL.
 * SQLite stores enum() as TEXT + CHECK constraint, so 'business' and 'government'
 * are still rejected at the DB level in tests.
 *
 * This migration converts the column to a plain string for SQLite, removing the
 * constraint. Application-layer validation in AccountTypeRequest and
 * CreateGovProfileRequest enforces the allowed values in all environments.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('account_type', 50)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // No rollback needed — test env only, and RefreshDatabase wipes between runs
    }
};
