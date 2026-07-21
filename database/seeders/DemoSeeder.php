<?php

namespace Database\Seeders;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // A warning is not a guard. These accounts all use the password
        // "password"; seeding them onto a server that holds real patient data
        // would hand anyone who knows this repo a clinician login. Refuse in
        // production rather than trusting whoever runs the command to read the
        // notice afterwards.
        if (app()->environment('production')) {
            $this->command->error(
                'DemoSeeder refuses to run in production. It creates known '
                .'credentials. Create a real admin instead — see DEPLOYMENT.md.'
            );

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@daifootcare.test'],
            ['name' => 'Study Admin', 'password' => 'password', 'locale' => 'en']
        );
        // role is not mass-assignable by design, so it is set explicitly here.
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

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

        $this->command->info('Seeded admin@ / doctor@ / patient@daifootcare.test — password: password');
        $this->command->warn('Development credentials only. Never seed these in production.');
    }
}
