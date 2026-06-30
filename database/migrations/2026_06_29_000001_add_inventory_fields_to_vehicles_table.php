<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the new inventory / detail columns.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('asset_number', 100)->nullable()->after('vin');
            $table->string('inventory_number', 50)->nullable()->unique()->after('asset_number');
            $table->string('exterior_color', 50)->nullable()->after('trim');
            $table->string('interior_color', 50)->nullable()->after('exterior_color');
            $table->string('interior_seating_type', 50)->nullable()->after('interior_color');
            // Odometer disclosure status: actual | not_actual | tmu (true miles unknown)
            $table->string('odometer_status', 20)->nullable()->after('mileage');
            $table->unsignedTinyInteger('number_of_keys')->nullable()->after('odometer_status');
            $table->unsignedTinyInteger('number_of_fobs')->nullable()->after('number_of_keys');
        });

        // 2. Backfill: migrate the legacy single `color` into `exterior_color`,
        //    and assign a deterministic inventory number to existing rows.
        DB::table('vehicles')->select('id', 'color')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('vehicles')->where('id', $row->id)->update([
                    'exterior_color'   => $row->color,
                    'inventory_number' => sprintf('INV-%05d', $row->id),
                ]);
            }
        });

        // 3. Drop the legacy column — `exterior_color` is now the single source of truth.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }

    public function down(): void
    {
        // Re-create the legacy column and restore its value from exterior_color.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('color')->nullable()->after('trim');
        });

        DB::statement('UPDATE vehicles SET color = exterior_color');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'asset_number',
                'inventory_number',
                'exterior_color',
                'interior_color',
                'interior_seating_type',
                'odometer_status',
                'number_of_keys',
                'number_of_fobs',
            ]);
        });
    }
};
