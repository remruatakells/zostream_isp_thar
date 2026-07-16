<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@example.com')], [
            'name' => env('ADMIN_NAME', 'ISP Administrator'),
            'password' => env('ADMIN_PASSWORD', 'password'),
            'role' => 'admin',
            'branch_id' => null,
            'is_active' => true,
        ]);

        Package::firstOrCreate(['mikrotik_profile' => 'starter-10m'], [
            'name' => 'Starter 10 Mbps', 'rate_limit' => '10M/10M', 'price' => 499, 'validity_days' => 30,
        ]);
        Package::firstOrCreate(['mikrotik_profile' => 'family-30m'], [
            'name' => 'Family 30 Mbps', 'rate_limit' => '30M/30M', 'price' => 899, 'validity_days' => 30,
        ]);
    }
}
