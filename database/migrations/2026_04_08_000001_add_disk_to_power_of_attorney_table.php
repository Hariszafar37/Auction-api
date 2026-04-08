<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('power_of_attorney', function (Blueprint $table) {
            // Track which filesystem disk the file lives on so the streaming
            // download controller can resolve it correctly even if the default
            // disk changes later. Matches the user_documents.disk column.
            $table->string('disk', 32)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('power_of_attorney', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
