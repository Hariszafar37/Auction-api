<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a unique, human-friendly bidder number to every user.
     *
     * New users are auto-assigned (1000 + id) by User::booted(). Existing
     * rows are backfilled here with the same formula so numbers stay unique
     * and stable across the dataset. Admins may later override any value.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('bidder_number')->nullable()->unique()->after('id');
        });

        // Backfill: deterministic, collision-free (ids are unique).
        DB::table('users')->whereNull('bidder_number')->orderBy('id')->each(function ($user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['bidder_number' => 1000 + $user->id]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['bidder_number']);
            $table->dropColumn('bidder_number');
        });
    }
};
