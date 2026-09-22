<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminRbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'payments.view' => [
                'Payments - View',
                'payments',
            ],

            'payments.refund' => [
                'Payments - Refund',
                'payments',
            ],

            'payments.retry' => [
                'Payments - Retry',
                'payments',
            ],

            'providers.view' => [
                'Providers - View',
                'providers',
            ],

            'providers.manage' => [
                'Providers - Manage',
                'providers',
            ],

            'credentials.manage' => [
                'Credentials - Manage',
                'providers',
            ],

            'routing.view' => [
                'Routing - View',
                'providers',
            ],

            'routing.manage' => [
                'Routing - Manage',
                'providers',
            ],

            'webhooks.view' => [
                'Webhooks - View',
                'webhooks',
            ],

            'reconciliation.view' => [
                'Reconciliation - View',
                'reconciliation',
            ],

            'reconciliation.resolve' => [
                'Reconciliation - Resolve',
                'reconciliation',
            ],

            'system.view' => [
                'System - View',
                'system',
            ],
        ];

        foreach ($permissions as $name => [$displayName, $group]) {
            Permission::updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $displayName,
                    'group_name' => $group,
                ]
            );
        }

        $adminRole = Role::updateOrCreate(
            ['name' => 'admin'],
            [
                'display_name' => 'Administrator',
                'description' => 'Full Payment Hub administration access.',
                'is_active' => true,
            ]
        );

        $adminRole->permissions()->sync(
            Permission::pluck('id')->all()
        );

        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (!$email || !$password) {
            throw new RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD must be configured before running AdminRbacSeeder.'
            );
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Payment Hub Administrator',
                'password' => Hash::make($password),
            ]
        );

        $user->roles()->syncWithoutDetaching([
            $adminRole->id,
        ]);
    }
}