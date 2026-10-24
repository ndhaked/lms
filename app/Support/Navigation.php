<?php

namespace App\Support;

use App\Models\Library;
use App\Models\SubscriptionPayment;

/**
 * Sidebar definitions. Hiding an item is only cosmetic: every route and every Livewire action
 * also enforces its own permission on the server.
 *
 * Item: [label, route name, icon, permission, active route pattern]
 *  - permission "a|b": any one is enough; "platform:x": a platform-administrator permission.
 *  - an item without a route is a module that is planned but not built yet ("Soon").
 */
class Navigation
{
    /** Library routes that belong to an optional, plan-controlled module. */
    protected const MODULE_ROUTES = [
        'app.attendance.index' => 'attendance',
        'app.expenses.index' => 'expenses',
        'app.reports.index' => 'reports',
        'app.books.index' => 'books|ebooks',
        'app.ebooks.index' => 'ebooks',
        'app.circulation.index' => 'books',
        'app.circulation.loans' => 'books',
        'app.fines.index' => 'books',
        'app.reservations.index' => 'books',
    ];

    public static function platform(): array
    {
        return self::build([
            ['Dashboard', 'admin.dashboard', 'home', null, 'admin.dashboard'],
            ['Leads', 'admin.leads.index', 'user-plus', 'platform:libraries.manage', 'admin.leads.*'],
            ['Libraries', 'admin.libraries.index', 'library', 'platform:libraries.manage', 'admin.libraries.index|admin.libraries.show|admin.libraries.edit'],
            ['Register Library', 'admin.libraries.create', 'plus-circle', 'platform:libraries.manage', 'admin.libraries.create'],
            ['Subscription Plans', 'admin.plans.index', 'ticket', 'platform:plans.manage', 'admin.plans.*'],
            ['Subscriptions', 'admin.subscriptions.index', 'refresh', 'platform:subscriptions.manage', 'admin.subscriptions.*'],
            ['Payment Approvals', 'admin.payment-approvals.index', 'check-badge', 'platform:payments.approve', 'admin.payment-approvals.*'],
            ['Support Requests', 'admin.support.index', 'mail', 'platform:libraries.manage', 'admin.support.*'],
            ['Platform Reports', 'admin.reports.index', 'chart', 'platform:reports.view', 'admin.reports.*'],
            ['Admin Users', 'admin.admins.index', 'user-group', 'platform:admins.manage', 'admin.admins.*'],
            ['Audit Logs', 'admin.audit-logs.index', 'shield', 'platform:audit.view', 'admin.audit-logs.*'],
            ['Settings', 'admin.settings.index', 'cog', 'platform:settings.manage', 'admin.settings.*'],
        ]);
    }

    public static function library(Library $library): array
    {
        $p = ['library' => $library->slug];

        return self::build([
            ['Dashboard', 'app.dashboard', 'home', null, 'app.dashboard'],
            ['My Library', 'app.my.index', 'users', 'self.view', 'app.my.*'],
            ['Students', 'app.students.index', 'users', 'students.view', 'app.students.*'],
            ['Halls & Floors', 'app.halls.index', 'building', 'seats.view', 'app.halls.*'],
            ['Seats', 'app.seats.index', 'seat', 'seats.view', 'app.seats.*'],
            ['Membership Plans', 'app.plans.index', 'ticket', 'memberships.view', 'app.plans.*'],
            ['Memberships', 'app.memberships.index', 'badge', 'memberships.view', 'app.memberships.*'],
            ['Books', 'app.books.index', 'book', 'books.view|ebooks.view', 'app.books.*'],
            ['Issue & Return', 'app.circulation.index', 'refresh', 'circulation.issue|circulation.return', 'app.circulation.index'],
            ['Loans', 'app.circulation.loans', 'clock', 'circulation.view', 'app.circulation.loans'],
            ['Reservations', 'app.reservations.index', 'ticket', 'circulation.view', 'app.reservations.*'],
            ['Fines', 'app.fines.index', 'cash', 'fines.view', 'app.fines.*'],
            ['E-Books', 'app.ebooks.index', 'tablet', 'ebooks.view|ebooks.read', 'app.ebooks.*'],
            ['Attendance', 'app.attendance.index', 'clock', 'attendance.view', 'app.attendance.*'],
            ['Payments', 'app.payments.index', 'cash', 'payments.view', 'app.payments.*'],
            ['Outstanding Dues', 'app.dues.index', 'alert', 'payments.view', 'app.dues.*'],
            ['Student Payments', 'app.payment-submissions.index', 'check-badge', 'payments.record', 'app.payment-submissions.*'],
            ['Expenses', 'app.expenses.index', 'receipt', 'expenses.view', 'app.expenses.*'],
            ['Reports', 'app.reports.index', 'chart', 'reports.operational|reports.financial', 'app.reports.*'],
            ['Staff', 'app.staff.index', 'user-group', 'staff.manage', 'app.staff.*'],
            ['Roles & Permissions', 'app.roles.index', 'shield', 'roles.manage', 'app.roles.*'],
            ['Settings', 'app.settings.index', 'cog', 'settings.manage', 'app.settings.*'],
            ['My Subscription', 'app.subscription.index', 'refresh', 'billing.view', 'app.subscription.*'],
        ], $p);
    }

    protected static function allowed(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }

        $user = auth()->user();

        return collect(explode('|', $permission))->contains(function ($p) use ($user) {
            return str_starts_with($p, 'platform:')
                ? ($user?->hasPlatformPermission(substr($p, 9)) ?? false)
                : ($user?->can($p) ?? false);
        });
    }

    protected static function build(array $items, array $params = []): array
    {
        return collect($items)
            ->filter(fn ($i) => self::allowed($i[3]))
            ->filter(fn ($i) => ! isset(self::MODULE_ROUTES[$i[1]]) || collect(explode('|', self::MODULE_ROUTES[$i[1]]))->contains(fn ($m) => Entitlements::hasModule($m)))
            ->map(fn ($i) => [
                'label' => $i[0],
                'route' => $i[1],
                'icon' => $i[2],
                'permission' => $i[3],
                'active' => $i[4],
                'params' => $params,
                'soon' => $i[1] === null,
                'badge' => match ($i[1]) {
                    'admin.payment-approvals.index' => SubscriptionPayment::where('status', SubscriptionPayment::PENDING)->count(),
                    'app.circulation.loans' => \App\Support\Entitlements::hasModule('books') ? \App\Models\LoanItem::overdue()->count() : null,
                    'app.reservations.index' => \App\Support\Entitlements::hasModule('books') ? \App\Models\Reservation::where('status', 'ready')->count() : null,
                    'app.payment-submissions.index' => \App\Models\PaymentSubmission::where('status', 'pending')->count(),
                    'admin.leads.index' => \App\Models\Lead::where('status', 'new')->count(),
                    'admin.support.index' => \App\Models\SupportRequest::where('status', 'open')->count(),
                    default => null,
                },
            ])
            ->values()
            ->all();
    }
}
