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
            DB::statement("ALTER TABLE user_documents MODIFY COLUMN type ENUM(
                'id','license','dealer_license','salesman_license',
                'articles_of_incorporation','authority_letter',
                'power_of_attorney','bill_of_sale','other'
            ) NOT NULL");

            DB::statement("ALTER TABLE user_documents ADD COLUMN status ENUM(
                'pending_review','approved','rejected','needs_resubmission'
            ) NOT NULL DEFAULT 'pending_review' AFTER type");
        }

        Schema::table('user_documents', function (Blueprint $table) {
            $table->text('admin_notes')->nullable()->after('status');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('admin_notes');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_documents', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['admin_notes', 'reviewed_by', 'reviewed_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE user_documents DROP COLUMN status");

            DB::statement("ALTER TABLE user_documents MODIFY COLUMN type ENUM(
                'id','license','other'
            ) NOT NULL");
        }
    }
};
