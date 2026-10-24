<?php

namespace App\Support;

/** Granular permissions for platform administrators. Library roles never carry any of these. */
class PlatformPermissions
{
    public const ALL = [
        'libraries.manage' => 'Register, edit, activate and suspend libraries',
        'plans.manage' => 'Create and edit subscription plans',
        'subscriptions.manage' => 'Change subscriptions (extend, suspend, cancel, change plan)',
        'payments.approve' => 'Approve or reject subscription payments',
        'reports.view' => 'View and export platform reports',
        'audit.view' => 'View audit logs',
        'admins.manage' => 'Manage platform administrator accounts',
        'settings.manage' => 'Change platform settings',
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
