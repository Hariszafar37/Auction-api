<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-bases auto-assigned bidder numbers from the original (1000 + id)
     * formula to (10000 + id) so every number is a consistent 5-digit value.
     *
     * Why a second migration instead of editing the first:
     *   - The original migration has already run on some environments (local),
     *     so editing it would not re-apply there.
     *   - On environments where neither has run yet (staging), the original
     *     runs first (1000 + id) and this one immediately re-bases to
     *     (10000 + id) — both paths converge on the same final values.
     *
     * Only rows still holding the original auto-assigned value (1000 + id) or
     * NULL are touched, so any bidder number an admin set manually is preserved.
     * The two ranges (1000+id vs 10000+id) never overlap for realistic id
     * counts, so the rewrite is collision-free.
     */
    public function up(): void
    {
        DB::table('users')->orderBy('id')->each(function ($user) {
            if ($user->bidder_number === null || (int) $user->bidder_number === 1000 + $user->id) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['bidder_number' => 10000 + $user->id]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->orderBy('id')->each(function ($user) {
            if ((int) $user->bidder_number === 10000 + $user->id) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['bidder_number' => 1000 + $user->id]);
            }
        });
    }
};
