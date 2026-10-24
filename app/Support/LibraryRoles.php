<?php

namespace App\Support;

/**
 * Default per-library roles and the permissions each one receives.
 * Permissions are global names; role assignment is scoped per library (Spatie teams).
 */
class LibraryRoles
{
    public const OWNER = 'Library Owner';
    public const MANAGER = 'Library Manager';
    public const RECEPTIONIST = 'Receptionist';
    public const ACCOUNTANT = 'Accountant';
    public const SUPERVISOR = 'Floor Supervisor';
    public const STUDENT = 'Student';

    /** @return list<string> every library-level permission */
    public static function permissions(): array
    {
        return array_merge([
            'library.manage', 'settings.manage', 'staff.manage', 'roles.manage', 'billing.view', 'billing.manage',
            'halls.manage', 'seats.view', 'seats.manage', 'seats.allocate',
            'students.view', 'students.create', 'students.update', 'students.deactivate', 'students.export',
            'plans.manage', 'memberships.view', 'memberships.manage', 'memberships.discount',
            'attendance.view', 'attendance.record', 'attendance.correct',
            'payments.view', 'payments.record', 'payments.refund', 'receipts.print',
            'expenses.view', 'expenses.manage',
            'reports.operational', 'reports.financial',
            'self.view',
        ], self::bookPermissions());
    }

    /** Books, e-books and circulation: only usable by libraries that have those modules. @return list<string> */
    public static function bookPermissions(): array
    {
        return [
            'books.view', 'books.manage', 'books.copies', 'books.import', 'books.export', 'books.audit',
            'ebooks.view', 'ebooks.manage', 'ebooks.read',
            'circulation.view', 'circulation.issue', 'circulation.return', 'circulation.renew', 'circulation.manage',
            'fines.view', 'fines.collect', 'fines.waive',
        ];
    }

    /** Permission groups and labels for the role editor. @return array<string, array<string, string>> */
    public static function groups(): array
    {
        return [
            'Library & team' => [
                'library.manage' => 'Manage library details',
                'settings.manage' => 'Change library settings',
                'staff.manage' => 'Manage staff accounts',
                'roles.manage' => 'Edit roles and permissions',
                'billing.view' => 'View the library subscription',
                'billing.manage' => 'Submit subscription payments and renewals',
            ],
            'Halls & seats' => [
                'halls.manage' => 'Create and edit halls',
                'seats.view' => 'View seats',
                'seats.manage' => 'Create and edit seats',
                'seats.allocate' => 'Allocate and release seats',
            ],
            'Students' => [
                'students.view' => 'View students',
                'students.create' => 'Register students',
                'students.update' => 'Edit students',
                'students.deactivate' => 'Suspend or deactivate students',
                'students.export' => 'Export student data',
            ],
            'Memberships' => [
                'plans.manage' => 'Manage membership plans',
                'memberships.view' => 'View memberships',
                'memberships.manage' => 'Assign, renew and change memberships',
                'memberships.discount' => 'Give discounts',
            ],
            'Attendance' => [
                'attendance.view' => 'View attendance',
                'attendance.record' => 'Check students in and out',
                'attendance.correct' => 'Correct attendance records',
            ],
            'Payments' => [
                'payments.view' => 'View payments and dues',
                'payments.record' => 'Record payments',
                'payments.refund' => 'Issue refunds and reverse payments',
                'receipts.print' => 'Print receipts',
            ],
            'Expenses' => [
                'expenses.view' => 'View expenses',
                'expenses.manage' => 'Record and edit expenses',
            ],
            'Reports' => [
                'reports.operational' => 'Operational reports',
                'reports.financial' => 'Financial reports',
            ],
            'Books' => [
                'books.view' => 'View the book catalogue',
                'books.manage' => 'Add, edit and archive books and categories',
                'books.copies' => 'Manage copies: accession, status, withdrawal',
                'books.import' => 'Import books from Excel / CSV',
                'books.export' => 'Export the catalogue',
                'books.audit' => 'Run stock-takes (count the shelves)',
            ],
            'E-books' => [
                'ebooks.view' => 'Open e-books (staff)',
                'ebooks.manage' => 'Add and edit e-books and files',
                'ebooks.read' => 'Read e-books (students)',
            ],
            'Circulation' => [
                'circulation.view' => 'View issued books and history',
                'circulation.issue' => 'Issue books',
                'circulation.return' => 'Return books',
                'circulation.renew' => 'Renew books',
                'circulation.manage' => 'Mark lost or damaged, reverse transactions',
                'fines.view' => 'View fines',
                'fines.collect' => 'Collect fines',
                'fines.waive' => 'Waive fines',
            ],
            'Students portal' => [
                'self.view' => 'See own profile, membership and payments',
            ],
        ];
    }

    /** Permissions only the library owner may ever hold (kept off editable roles). */
    public const OWNER_ONLY = [];

    /** @return list<string> the default role names (not renamable or deletable) */
    public static function defaults(): array
    {
        return array_keys(self::map());
    }

    /** @return array<string, list<string>> */
    public static function map(): array
    {
        $all = array_values(array_diff(self::permissions(), ['self.view']));

        return [
            self::OWNER => $all,
            self::MANAGER => [
                'seats.view', 'seats.manage', 'seats.allocate', 'halls.manage',
                'students.view', 'students.create', 'students.update', 'students.deactivate',
                'memberships.view', 'memberships.manage',
                'attendance.view', 'attendance.record', 'attendance.correct',
                'payments.view', 'receipts.print', 'reports.operational',
                'books.view', 'books.manage', 'books.copies', 'books.import', 'books.export', 'books.audit', 'ebooks.view', 'ebooks.manage',
                'circulation.view', 'circulation.issue', 'circulation.return', 'circulation.renew', 'circulation.manage', 'fines.view', 'fines.collect',
            ],
            self::RECEPTIONIST => [
                'seats.view', 'seats.allocate', 'students.view', 'students.create', 'students.update',
                'memberships.view', 'attendance.view', 'attendance.record',
                'payments.view', 'payments.record', 'receipts.print',
                'books.view', 'ebooks.view', 'circulation.view', 'circulation.issue', 'circulation.return', 'circulation.renew', 'fines.view', 'fines.collect',
            ],
            self::ACCOUNTANT => [
                'students.view', 'memberships.view', 'payments.view', 'payments.record', 'payments.refund',
                'receipts.print', 'expenses.view', 'expenses.manage', 'reports.financial', 'fines.view',
            ],
            self::SUPERVISOR => ['seats.view', 'attendance.view', 'attendance.record', 'books.view', 'ebooks.view', 'circulation.view'],
            self::STUDENT => ['self.view', 'ebooks.read'],
        ];
    }
}
