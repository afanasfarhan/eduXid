<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Membuat akun setup awal (admin/guru/tendik) untuk aplikasi
     * standalone. updateOrInsert membuat seeder ini IDEMPOTEN:
     * aman dijalankan berulang, tidak merusak password yang sudah
     * diubah user, dan tidak error bila akun sudah ada.
     */
    public function run(): void
    {
        $accounts = [
            [
                'login_id' => 'admin',
                'name'     => 'Admin Sekolah',
                'email'    => 'admin@eduxid.id',
                'password' => 'admin123',
                'role'     => 'admin',
            ],
            [
                'login_id' => 'guru',
                'name'     => 'Guru Setup',
                'email'    => 'guru@eduxid.id',
                'password' => 'guru123',
                'role'     => 'guru',
            ],
            [
                'login_id' => 'tendik',
                'name'     => 'Tendik Setup',
                'email'    => 'tendik@eduxid.id',
                'password' => 'tendik123',
                'role'     => 'tendik',
            ],
        ];

        foreach ($accounts as $account) {
            DB::table('users')->updateOrInsert(
                ['login_id' => $account['login_id']],
                [
                    'name'     => $account['name'],
                    'email'    => $account['email'],
                    'password' => Hash::make($account['password']),
                    'role'     => $account['role'],
                    'must_change_password' => false,
                    'email_verified_at'    => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}