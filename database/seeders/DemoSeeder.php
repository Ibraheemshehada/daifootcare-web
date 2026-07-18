<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $doctor = User::firstOrCreate(
            ['email' => 'doctor@daifootcare.test'],
            ['name' => 'Dr. Demo', 'password' => 'password', 'locale' => 'en']
        );
        $doctor->forceFill(['role' => User::ROLE_DOCTOR])->save();

        $patientUser = User::firstOrCreate(
            ['email' => 'patient@daifootcare.test'],
            ['name' => 'Demo Patient', 'password' => 'password', 'locale' => 'ar']
        );
        $patientUser->forceFill(['role' => User::ROLE_PATIENT])->save();

        Patient::firstOrCreate(
            ['user_id' => $patientUser->id],
            ['diabetes_type' => 'type_2', 'gender' => 'male']
        );

        $this->command->info('Seeded doctor@daifootcare.test / patient@daifootcare.test (password: password)');
    }
}
