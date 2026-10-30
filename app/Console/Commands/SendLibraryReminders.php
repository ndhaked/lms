<?php

namespace App\Console\Commands;

use App\Models\Library;
use App\Models\Student;
use App\Models\StudentMembership;
use App\Support\LibrarySettings;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class SendLibraryReminders extends Command
{
    protected $signature = 'library:send-reminders {--library= : only this library id}';

    protected $description = 'Send in-app reminders: memberships about to expire and outstanding fees (run daily).';

    public function handle(): int
    {
        $sent = 0;

        Library::where('status', Library::ACTIVE)
            ->when($this->option('library'), fn ($q, $id) => $q->whereKey($id))
            ->each(function (Library $library) use (&$sent) {
                $sent += Tenancy::run($library, fn () => $this->forLibrary($library));
            });

        $this->info("Reminders sent: {$sent}");

        return self::SUCCESS;
    }

    protected function forLibrary(Library $library): int
    {
        $sent = 0;
        $slug = $library->slug;
        $today = today()->toDateString();

        if (LibrarySettings::get('notify_expiry')) {
            $window = (int) LibrarySettings::get('renewal_window_days');
            $expiring = StudentMembership::with('student')->expiringWithin($window)->get();

            if ($expiring->isNotEmpty()) {
                $sent += Notifier::library($library, 'memberships.view', 'membership.expiring',
                    $expiring->count().' membership(s) expire within '.$window.' days',
                    $expiring->take(3)->map(fn ($m) => $m->student->name.' ('.$m->ends_on->format('d M').')')->implode(', ').($expiring->count() > 3 ? '…' : ''),
                    route('app.memberships.index', ['library' => $slug, 'state' => 'active']), "exp:{$library->id}:{$today}");
            }

            // Students who have their own login are told about their own membership, once per membership.
            foreach ($expiring->filter(fn ($m) => $m->student->user_id) as $m) {
                $user = $m->student->user ?? \App\Models\User::find($m->student->user_id);
                if ($user && $user->is_active) {
                    $sent += (int) Notifier::user($user, $library->id, 'membership.expiring_self', 'Your membership is ending soon',
                        "Your membership ends on {$m->ends_on->format('d M Y')}. Ask the library to renew it.", route('app.my.index', ['library' => $slug]),
                        "exp-self:{$m->id}:{$m->ends_on->toDateString()}");
                }
            }
        }

        if (LibrarySettings::get('notify_dues')) {
            $overdue = StudentMembership::owing()->withBalance()->whereDate('student_memberships.ends_on', '<', today())->get();

            if ($overdue->isNotEmpty()) {
                $total = number_format((float) $overdue->sum('balance'), 2);
                $sent += Notifier::library($library, 'payments.view', 'dues.overdue', $overdue->count()." overdue fee(s) — ₹{$total}",
                    'Memberships that have ended but are not fully paid.', route('app.dues.index', ['library' => $slug, 'filter' => 'overdue']),
                    'dues:'.$library->id.':'.now()->format('o-W'));   // at most once a week
            }
        }

        $this->messageStudents();

        if (\App\Support\Entitlements::hasModule('books')) {
            app(\App\Actions\Circulation\Reservations::class)->expireDue();      // uncollected reservations lapse; their copy moves on
        }

        if (LibrarySettings::get('notify_dues') && \App\Support\Entitlements::hasModule('books')) {
            $sent += $this->bookReminders($library);
        }

        return $sent;
    }

    /** Books due tomorrow and books that are overdue: told to the member (if they have a login) and, as a daily digest, to staff. */
    protected function bookReminders(Library $library): int
    {
        $sent = 0;
        $slug = $library->slug;

        $dueSoon = \App\Models\LoanItem::with(['book', 'student'])->open()->whereDate('due_on', today()->addDay()->toDateString())->get();
        $late = \App\Models\LoanItem::with(['book', 'student'])->overdue()->get();

        foreach ($dueSoon as $i) {
            if ($user = $i->student->user_id ? \App\Models\User::find($i->student->user_id) : null) {
                $sent += (int) Notifier::user($user, $library->id, 'loan.due_soon', 'A book is due tomorrow', "“{$i->book->title}” is due on {$i->due_on->format('d M Y')}. You can renew it from your page.",
                    route('app.my.index', ['library' => $slug]), "loan-due:{$i->id}:{$i->due_on->toDateString()}");
            }
        }

        foreach ($late as $i) {
            if ($user = $i->student->user_id ? \App\Models\User::find($i->student->user_id) : null) {
                $sent += (int) Notifier::user($user, $library->id, 'loan.overdue', 'A book is overdue', "“{$i->book->title}” was due on {$i->due_on->format('d M Y')}. Please return it; a fine may apply.",
                    route('app.my.index', ['library' => $slug]), "loan-late:{$i->id}:".now()->format('o-W'));   // at most once a week per book
            }
        }

        if ($late->isNotEmpty()) {
            $sent += Notifier::library($library, 'circulation.view', 'loan.overdue_digest', $late->count().' book(s) overdue',
                $late->take(3)->map(fn ($i) => $i->student->name.': '.$i->book->title)->implode('; ').($late->count() > 3 ? '…' : ''),
                route('app.circulation.loans', ['library' => $slug, 'status' => 'overdue']), "overdue-digest:{$library->id}:".today()->toDateString());
        }

        return $sent;
    }

    /** SMS / WhatsApp to the students themselves: a membership about to end (once each) and unpaid dues (once a week). */
    protected function messageStudents(): void
    {
        if (! \App\Support\Messaging::connected()) {
            return;
        }

        $window = max(1, (int) LibrarySettings::get('renewal_window_days'));
        foreach (StudentMembership::with(['student', 'plan'])->expiringWithin($window)->get() as $m) {
            \App\Support\Messaging::trigger('expiring', $m->student, ['date' => $m->ends_on->format('d M Y'), 'plan' => $m->plan?->name ?? ''], "membership:{$m->id}:{$m->ends_on->toDateString()}");
        }

        $week = now()->format('o-W');
        foreach (StudentMembership::with('student')->owing()->withBalance()->whereDate('student_memberships.ends_on', '<', today())->get()->groupBy('student_id') as $studentId => $rows) {
            \App\Support\Messaging::trigger('due', $rows->first()->student, ['amount' => '₹'.number_format((float) $rows->sum('balance'), 2), 'due' => '₹'.number_format((float) $rows->sum('balance'), 2)], "student:{$studentId}:{$week}");
        }
    }
}
