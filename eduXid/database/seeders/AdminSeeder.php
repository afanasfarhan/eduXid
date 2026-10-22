<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['login_id' => 'admin',  'name' => 'Admin Sekolah', 'role' => 'admin'],
            ['login_id' => 'guru',   'name' => 'Guru Setup',    'role' => 'guru'],
            ['login_id' => 'tendik', 'name' => 'Tendik Setup',  'role' => 'tendik'],
        ] as $u) {
            DB::table('users')->updateOrInsert(
                ['login_id' => $u['login_id']],
                [
                    'name'   => $u['name'],
                    'email'  => $u['login_id'] . '@eduxid.local',
                    'password' => Hash::make($u['login_id'] . '123'),
                    'role'   => $u['role'],
                    'must_change_password' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}