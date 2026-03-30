<?php

// These columns were listed in DealerProfile::$fillable but were never added to the table.
// The original migration (2026_03_02_000003_add_activation_fields_to_dealer_profiles_table)
// was voided (empty up()) when user_dealer_information was created as the activation-step table.
// Our Phase B complete() sync now writes all dealer data into DealerProfile for admin review,
// so the columns must actually exist.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_profiles', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('company_name');
            $table->string('phone_primary', 30)->nullable()->after('owner_name');
            $table->string('primary_contact')->nullable()->after('phone_primary');
            $table->date('dealer_license_expiration_date')->nullable()->after('dealer_license');
            $table->string('tax_id_number', 50)->nullable()->after('dealer_license_expiration_date');
            $table->string('dealer_address_line1')->nullable()->after('tax_id_number');
            $table->string('dealer_address_line2')->nullable()->after('dealer_address_line1');
            $table->string('dealer_city', 100)->nullable()->after('dealer_address_line2');
            $table->string('dealer_state', 100)->nullable()->after('dealer_city');
            $table->string('dealer_postal_code', 20)->nullable()->after('dealer_state');
            $table->string('dealer_country', 2)->nullable()->after('dealer_postal_code');
            $table->string('dealer_license_document_path')->nullable()->after('dealer_country');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'owner_name',
                'phone_primary',
                'primary_contact',
                'dealer_license_expiration_date',
                'tax_id_number',
                'dealer_address_line1',
                'dealer_address_line2',
                'dealer_city',
                'dealer_state',
                'dealer_postal_code',
                'dealer_country',
                'dealer_license_document_path',
            ]);
        });
    }
};
