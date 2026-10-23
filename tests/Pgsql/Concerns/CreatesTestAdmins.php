<?php

namespace Tests\Pgsql\Concerns;

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait CreatesTestAdmins
{
    protected function makeAdmin(string $role = 'ADMIN', array $overrides = []): Admin
    {
        $id = (string) Str::uuid();

        $admin = new Admin();
        $admin->forceFill(array_merge([
            'admin_id'             => $id,
            'username'             => 'autotest-' . strtolower($role) . '-' . substr($id, 0, 8),
            'password'             => Hash::make('Autotest#12345'),
            'full_name'            => 'Autotest ' . $role,
            'email'                => 'autotest+' . substr($id, 0, 8) . '@example.test',
            'role'                 => $role,
            'status'               => 'ACTIVE',
            'must_change_password' => false,
        ], $overrides));
        $admin->save();

        return $admin;
    }
}