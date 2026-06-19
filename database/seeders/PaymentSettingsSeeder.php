<?php

namespace Database\Seeders;

use App\Models\PaymentSetting;
use Illuminate\Database\Seeder;

class PaymentSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent: creates the singleton row with defaults if absent,
        // leaves any admin-customised values untouched.
        PaymentSetting::current();
    }
}
