<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Plant;
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
        $demoPassword = 'Pilot2026!';
        $demoUsers = [
            ['name' => 'Demo Operator', 'username' => 'operator', 'email' => 'operator.demo@example.com', 'role' => 'operator'],
            ['name' => 'Demo Technician', 'username' => 'technician', 'email' => 'technician.demo@example.com', 'role' => 'technician'],
            ['name' => 'Demo Supervisor', 'username' => 'supervisor', 'email' => 'supervisor.demo@example.com', 'role' => 'supervisor'],
            ['name' => 'Demo QA', 'username' => 'qa', 'email' => 'qa.demo@example.com', 'role' => 'qa'],
            ['name' => 'Demo Admin', 'username' => 'admin', 'email' => 'admin.demo@example.com', 'role' => 'admin'],
        ];

        if (app()->environment(['local', 'testing'])) {
            $demoUsers[] = ['name' => 'Developer', 'username' => 'developer', 'email' => 'developer@rapidplast.co.id', 'role' => 'admin'];
        }

        $defaultPlantId = Plant::query()->where('code', 'RX03')->value('id');

        foreach ($demoUsers as $demoUser) {
            User::updateOrCreate(
                ['email' => $demoUser['email']],
                [...$demoUser, 'plant_id' => $demoUser['role'] === 'admin' ? null : $defaultPlantId, 'password' => $demoPassword],
            );
        }
    }
}
