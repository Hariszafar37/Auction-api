<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE dealer_profiles ADD COLUMN dealer_classification ENUM(
                'maryland_retail','maryland_wholesale',
                'out_of_state_retail','out_of_state_wholesale'
            ) NULL AFTER approval_status");
        }

        Schema::table('dealer_profiles', function (Blueprint $table) {
            if (DB::getDriverName() !== 'mysql') {
                // SQLite (used in tests) does not support ENUM; use nullable string instead
                $table->string('dealer_classification', 30)->nullable();
            }
            $table->boolean('can_sell_to_public')->nullable();
            $table->boolean('inspection_passed')->nullable();
            $table->boolean('tags_required')->nullable();
            $table->boolean('bill_of_sale_received')->default(false);
            $table->text('admin_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dealer_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'can_sell_to_public',
                'inspection_passed',
                'tags_required',
                'bill_of_sale_received',
                'admin_notes',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE dealer_profiles DROP COLUMN dealer_classification");
        }
    }
};
