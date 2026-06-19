<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // Optional map coordinates. When present, the public location page
            // renders an embedded map + "Get Directions" pinned to this point;
            // otherwise it falls back to the street address. Nullable so existing
            // locations keep working without coordinates.
            $table->decimal('latitude', 10, 7)->nullable()->after('manager_name');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
