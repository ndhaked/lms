@php
    $user = auth()->user();
    $rows = [];

    if ($user->can('attendance.view')) {
        $rows[] = ['Check-ins', \App\Models\Attendance::whereDate('checked_in_at', today())->count(), 'clock', 'green'];
    }
    if ($user->can('payments.view')) {
        $rows[] = ['Payments', \App\Models\Payment::where('status', 'completed')->whereDate('paid_on', today())->count(), 'cash', 'blue'];
    }
    if ($user->can('students.view')) {
        $rows[] = ['New students', \App\Models\Student::whereDate('registered_on', today())->count(), 'users', 'purple'];
    }
    if ($user->can('memberships.view')) {
        $rows[] = ['Expiring ('.(int) \App\Support\LibrarySettings::get('renewal_window_days').' days)', \App\Models\StudentMembership::expiringWithin((int) \App\Support\LibrarySettings::get("renewal_window_days"))->count(), 'alert', 'red'];
    }

    if (\App\Support\Entitlements::hasModule('books') && $user->can('circulation.view')) {
        $rows[] = ['Books issued', \App\Models\LoanItem::whereDate('issued_at', today())->whereIn('status', ['issued', 'returned', 'lost'])->count(), 'book', 'blue'];
        $rows[] = ['Overdue books', \App\Models\LoanItem::overdue()->count(), 'alert', 'red'];
    }

    $tones = ['green' => 'bg-brand-100 text-brand-600', 'blue' => 'bg-blue-100 text-blue-600', 'purple' => 'bg-violet-100 text-violet-600', 'red' => 'bg-red-100 text-red-500'];
@endphp

@if ($rows)
    <div class="m-4 shrink-0 rounded-2xl bg-brand-50/70 p-4">
        <p class="mb-3 text-[13px] font-semibold text-gray-700">Today's Summary</p>
        <ul class="space-y-3">
            @foreach ($rows as [$label, $value, $icon, $tone])
                <li class="flex items-center gap-3 text-xs text-gray-600">
                    <span class="flex h-6 w-6 items-center justify-center rounded-md {{ $tones[$tone] }}"><x-lm.icon :name="$icon" class="h-3.5 w-3.5" /></span>
                    <span class="flex-1">{{ $label }}</span>
                    <span class="font-semibold {{ $tone === 'red' && $value > 0 ? 'text-red-500' : 'text-gray-900' }}">{{ number_format($value) }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
