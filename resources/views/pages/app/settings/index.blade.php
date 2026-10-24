<?php

use App\Livewire\Concerns\AuthorizesActions;
use App\Models\AuditLog;
use App\Support\LibrarySettings;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::panel')] class extends Component
{
    use AuthorizesActions, WithFileUploads;

    #[Url]
    public string $tab = 'profile';

    // profile
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $timezone = 'Asia/Kolkata';
    public string $currency = 'INR';
    public string $opening_time = '';
    public string $closing_time = '';
    public $logo = null;
    public ?string $existingLogo = null;

    // hours & days
    public array $working_days = [];
    public array $holidays = [];
    public string $holidayDate = '';
    public string $holidayLabel = '';

    // seats
    public string $seat_prefix = '';
    public string $seat_padding = '2';

    // attendance
    public bool $attendance_require_membership = true;
    public string $attendance_grace_minutes = '15';

    // memberships
    public string $renewal_window_days = '7';
    public bool $renewal_starts_after_expiry = true;
    public bool $student_payment_submissions = false;
    public string $academic_year_start_month = '4';

    // books & e-books
    public string $accession_prefix = 'ACC';
    public string $accession_padding = '6';
    public bool $ebook_require_membership = true;
    public bool $isbn_lookup = true;
    public array $loanRules = [];
    public array $memberTypesList = [];      // key => label
    public string $newMemberType = '';
    public bool $circulation_require_membership = true;
    public bool $block_same_title_twice = true;
    public bool $allow_renew_overdue = false;
    public bool $student_can_renew = true;
    public bool $fine_skip_closed_days = true;
    public string $block_issue_fines_over = '0';
    public string $lost_fine_multiplier = '1';
    public string $lost_fine_extra = '0';
    public string $repeat_overdue_alert = '3';
    public string $loan_no_prefix = 'LN';
    public bool $reservations_enabled = true;
    public string $reservation_hold_days = '3';

    // receipts & tax
    public string $receipt_prefix = 'RC';
    public string $receipt_header = '';
    public string $receipt_footer = '';
    public string $tax_number = '';
    public string $tax_note = '';

    // notifications & data
    public bool $notify_expiry = true;
    public bool $notify_dues = true;
    public bool $export_enabled = true;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');

        $library = Tenancy::library();
        $this->fill([
            'name' => $library->name, 'email' => (string) $library->email, 'phone' => (string) $library->phone, 'address' => (string) $library->address,
            'timezone' => $library->timezone, 'currency' => $library->currency,
            'opening_time' => $library->opening_time ? substr($library->opening_time, 0, 5) : '',
            'closing_time' => $library->closing_time ? substr($library->closing_time, 0, 5) : '',
            'existingLogo' => $library->logo_path,
        ]);

        $s = LibrarySettings::all();
        $this->fill([
            'working_days' => array_map('strval', $s['working_days']),
            'holidays' => $s['holidays'],
            'seat_prefix' => (string) $s['seat_prefix'], 'seat_padding' => (string) $s['seat_padding'],
            'attendance_require_membership' => (bool) $s['attendance_require_membership'],
            'attendance_grace_minutes' => (string) $s['attendance_grace_minutes'],
            'renewal_window_days' => (string) $s['renewal_window_days'],
            'renewal_starts_after_expiry' => (bool) $s['renewal_starts_after_expiry'], 'student_payment_submissions' => (bool) $s['student_payment_submissions'], 'academic_year_start_month' => (string) $s['academic_year_start_month'],
            'accession_prefix' => (string) $s['accession_prefix'], 'accession_padding' => (string) $s['accession_padding'], 'ebook_require_membership' => (bool) $s['ebook_require_membership'], 'isbn_lookup' => (bool) $s['isbn_lookup'],
            'memberTypesList' => \App\Support\CirculationRules::memberTypes(),
            'loanRules' => collect(\App\Support\CirculationRules::memberTypes())->map(fn ($l, $t) => array_map(fn ($v) => (string) $v, \App\Support\CirculationRules::rule($t)))->all(),
            'circulation_require_membership' => (bool) $s['circulation_require_membership'], 'block_same_title_twice' => (bool) $s['block_same_title_twice'],
            'allow_renew_overdue' => (bool) $s['allow_renew_overdue'], 'student_can_renew' => (bool) $s['student_can_renew'], 'fine_skip_closed_days' => (bool) $s['fine_skip_closed_days'],
            'block_issue_fines_over' => (string) $s['block_issue_fines_over'], 'lost_fine_multiplier' => (string) $s['lost_fine_multiplier'], 'lost_fine_extra' => (string) $s['lost_fine_extra'],
            'repeat_overdue_alert' => (string) $s['repeat_overdue_alert'], 'loan_no_prefix' => (string) $s['loan_no_prefix'],
            'reservations_enabled' => (bool) $s['reservations_enabled'], 'reservation_hold_days' => (string) $s['reservation_hold_days'],
            'receipt_prefix' => (string) $s['receipt_prefix'], 'receipt_header' => (string) $s['receipt_header'],
            'receipt_footer' => (string) $s['receipt_footer'], 'tax_number' => (string) $s['tax_number'], 'tax_note' => (string) $s['tax_note'],
            'notify_expiry' => (bool) $s['notify_expiry'], 'notify_dues' => (bool) $s['notify_dues'], 'export_enabled' => (bool) $s['export_enabled'],
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetErrorBag();
    }

    protected function saved(string $what, array $changed): void
    {
        if ($changed) {
            AuditLog::record('settings.updated', Tenancy::library(), ['section' => $what, 'changed' => $changed]);
        }
        LibrarySettings::flush();
        $this->dispatch('notify', message: 'Settings saved.');
    }

    protected function changedKeys(array $values): array
    {
        $current = LibrarySettings::all();

        return array_keys(array_filter($values, fn ($v, $k) => ($current[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
    }

    public function saveProfile(): void
    {
        \App\Support\Entitlements::assertWritable();
        $this->authorizePermission('settings.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'max:8'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ]);

        $library = Tenancy::library();
        $library->fill(array_map(fn ($v) => $v === '' ? null : $v, collect($data)->except('logo')->all()));

        if ($this->logo) {
            if ($library->logo_path) {
                Storage::disk('public')->delete($library->logo_path);
            }
            $library->logo_path = $this->logo->store('logos', 'public');
            $this->existingLogo = $library->logo_path;
            $this->logo = null;
        }

        $changed = array_keys($library->getDirty());
        $library->save();
        Tenancy::set($library->fresh()); // a changed time zone applies from now on

        $this->saved('profile', $changed);
    }

    public function addHoliday(): void
    {
        $this->authorizePermission('settings.manage');
        $this->validate(['holidayDate' => ['required', 'date'], 'holidayLabel' => ['required', 'string', 'max:60']], [], ['holidayDate' => 'date', 'holidayLabel' => 'name']);

        $this->holidays = collect($this->holidays)->reject(fn ($h) => $h['date'] === $this->holidayDate)
            ->push(['date' => $this->holidayDate, 'label' => $this->holidayLabel])->sortBy('date')->values()->all();
        $this->reset(['holidayDate', 'holidayLabel']);
    }

    public function removeHoliday(string $date): void
    {
        $this->holidays = collect($this->holidays)->reject(fn ($h) => $h['date'] === $date)->values()->all();
    }

    public function saveHours(): void
    {
        $this->authorizePermission('settings.manage');
        $this->validate(['working_days' => ['array'], 'working_days.*' => ['in:1,2,3,4,5,6,7']]);

        $values = ['working_days' => array_map('intval', $this->working_days), 'holidays' => $this->holidays];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('hours', $changed);
    }

    public function saveSeats(): void
    {
        $this->authorizePermission('settings.manage');
        $data = $this->validate(['seat_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_.\/ ]*$/'], 'seat_padding' => ['required', 'integer', 'between:1,5']]);

        $values = ['seat_prefix' => (string) $data['seat_prefix'], 'seat_padding' => (int) $data['seat_padding']];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('seats', $changed);
    }

    public function saveAttendance(): void
    {
        $this->authorizePermission('settings.manage');
        $data = $this->validate(['attendance_grace_minutes' => ['required', 'integer', 'between:0,240']]);

        $values = ['attendance_require_membership' => $this->attendance_require_membership, 'attendance_grace_minutes' => (int) $data['attendance_grace_minutes']];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('attendance', $changed);
    }

    public function addMemberType(): void
    {
        $this->authorizePermission('settings.manage');
        \App\Support\Entitlements::assertWritable();
        $this->validate(['newMemberType' => ['required', 'string', 'max:30']], [], ['newMemberType' => 'member type']);

        $label = trim($this->newMemberType);
        if (collect($this->memberTypesList)->contains(fn ($l) => mb_strtolower($l) === mb_strtolower($label))) {
            $this->addError('newMemberType', 'You already have a member type with that name.');

            return;
        }
        if (count($this->memberTypesList) >= 12) {
            $this->addError('newMemberType', 'At most 12 member types.');

            return;
        }

        $base = substr(preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii($label))) ?: 'type', 0, 10);
        $key = $base;
        for ($i = 2; isset($this->memberTypesList[$key]); $i++) {
            $key = substr($base, 0, 10).$i;
        }

        $this->memberTypesList[$key] = $label;
        $this->loanRules[$key] = array_map(fn ($v) => (string) $v, \App\Support\CirculationRules::rule('student'));   // starts like a student
        $this->newMemberType = '';
    }

    public function removeMemberType(string $key): void
    {
        $this->authorizePermission('settings.manage');
        \App\Support\Entitlements::assertWritable();

        if ($key === 'student' || ! isset($this->memberTypesList[$key])) {
            $this->dispatch('notify', type: 'error', message: 'The Student type cannot be removed.');

            return;
        }
        if (\App\Models\Student::where('member_type', $key)->exists()) {
            $this->dispatch('notify', type: 'error', message: 'Members still use this type. Change their type first.');

            return;
        }

        unset($this->memberTypesList[$key], $this->loanRules[$key]);
    }

    public function saveBooks(): void
    {
        $this->authorizePermission('settings.manage');
        abort_unless(\App\Support\Entitlements::hasModule('books') || \App\Support\Entitlements::hasModule('ebooks'), 403);

        $data = $this->validate([
            'accession_prefix' => ['required', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'accession_padding' => ['required', 'integer', 'between:3,10'],
        ], ['accession_prefix.regex' => 'Use 1 to 10 letters or digits, no spaces or symbols.'], ['accession_prefix' => 'accession prefix', 'accession_padding' => 'number width']);

        $this->validate(['memberTypesList.*' => ['required', 'string', 'max:30']], ['memberTypesList.*.required' => 'Give every member type a name.'], ['memberTypesList.*' => 'member type name']);

        $rules = $this->validate([
            'loanRules.*.loan_days' => ['required', 'integer', 'between:1,365'],
            'loanRules.*.max_items' => ['required', 'integer', 'between:1,100'],
            'loanRules.*.max_renewals' => ['required', 'integer', 'between:0,20'],
            'loanRules.*.renewal_days' => ['required', 'integer', 'between:1,365'],
            'loanRules.*.fine_per_day' => ['required', 'numeric', 'between:0,100000'],
            'loanRules.*.grace_days' => ['required', 'integer', 'between:0,60'],
            'loanRules.*.fine_cap' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'loanRules.*.max_reservations' => ['required', 'integer', 'between:0,20'],
            'reservation_hold_days' => ['required', 'integer', 'between:1,30'],
            'block_issue_fines_over' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'lost_fine_multiplier' => ['required', 'numeric', 'between:0,20'],
            'lost_fine_extra' => ['required', 'numeric', 'between:0,9999999'],
            'repeat_overdue_alert' => ['required', 'integer', 'between:0,50'],
            'loan_no_prefix' => ['required', 'regex:/^[A-Za-z0-9]{1,10}$/'],
        ], ['loanRules.*.required' => 'Fill in every lending rule.', 'loan_no_prefix.regex' => 'Use 1 to 10 letters or digits.'], ['loan_no_prefix' => 'loan number prefix']);

        $clean = [];
        $types = array_map(fn ($l) => trim($l), $this->memberTypesList);
        foreach (array_keys($types) as $type) {
            $r = $this->loanRules[$type] ?? [];
            $clean[$type] = [
                'loan_days' => (int) $r['loan_days'], 'max_items' => (int) $r['max_items'], 'max_renewals' => (int) $r['max_renewals'], 'renewal_days' => (int) $r['renewal_days'],
                'fine_per_day' => number_format((float) $r['fine_per_day'], 2, '.', ''), 'grace_days' => (int) $r['grace_days'], 'max_reservations' => (int) $r['max_reservations'],
                'fine_cap' => ($r['fine_cap'] ?? '') === '' ? null : number_format((float) $r['fine_cap'], 2, '.', ''),
            ];
        }

        $values = [
            'accession_prefix' => strtoupper($data['accession_prefix']), 'accession_padding' => (int) $data['accession_padding'], 'ebook_require_membership' => $this->ebook_require_membership, 'isbn_lookup' => $this->isbn_lookup,
            'member_types' => $types, 'loan_rules' => $clean, 'loan_no_prefix' => strtoupper($rules['loan_no_prefix']),
            'reservations_enabled' => $this->reservations_enabled, 'reservation_hold_days' => (int) $rules['reservation_hold_days'],
            'circulation_require_membership' => $this->circulation_require_membership, 'block_same_title_twice' => $this->block_same_title_twice,
            'allow_renew_overdue' => $this->allow_renew_overdue, 'student_can_renew' => $this->student_can_renew, 'fine_skip_closed_days' => $this->fine_skip_closed_days,
            'block_issue_fines_over' => (float) $rules['block_issue_fines_over'], 'lost_fine_multiplier' => (float) $rules['lost_fine_multiplier'],
            'lost_fine_extra' => (float) $rules['lost_fine_extra'], 'repeat_overdue_alert' => (int) $rules['repeat_overdue_alert'],
        ];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('books', $changed);
    }

    public function saveMemberships(): void
    {
        $this->authorizePermission('settings.manage');
        $data = $this->validate(['renewal_window_days' => ['required', 'integer', 'between:1,90'], 'academic_year_start_month' => ['required', 'integer', 'between:1,12']]);

        $values = ['renewal_window_days' => (int) $data['renewal_window_days'], 'renewal_starts_after_expiry' => $this->renewal_starts_after_expiry, 'student_payment_submissions' => $this->student_payment_submissions, 'academic_year_start_month' => (int) $data['academic_year_start_month']];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('memberships', $changed);
    }

    public function saveReceipts(): void
    {
        $this->authorizePermission('settings.manage');
        $data = $this->validate([
            'receipt_prefix' => ['required', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/'],
            'receipt_header' => ['nullable', 'string', 'max:300'],
            'receipt_footer' => ['nullable', 'string', 'max:300'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'tax_note' => ['nullable', 'string', 'max:300'],
        ], ['receipt_prefix.regex' => 'Use capital letters and digits only.']);

        $values = array_map(fn ($v) => (string) $v, $data);
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('receipts', $changed);
    }

    public function saveNotifications(): void
    {
        $this->authorizePermission('settings.manage');

        $values = ['notify_expiry' => $this->notify_expiry, 'notify_dues' => $this->notify_dues, 'export_enabled' => $this->export_enabled];
        $changed = $this->changedKeys($values);
        LibrarySettings::update($values);
        $this->saved('notifications', $changed);
    }
}
?>

<div class="max-w-4xl space-y-4">
    <x-slot:header><x-ui.page-title title="Library settings" subtitle="Profile, hours, rules and receipts" /></x-slot:header>

    @php
        $bookTab = \App\Support\Entitlements::hasModule('books') || \App\Support\Entitlements::hasModule('ebooks');
        $tabs = ['profile' => 'Profile', 'hours' => 'Hours & holidays', 'seats' => 'Seats', 'attendance' => 'Attendance', 'memberships' => 'Memberships', 'receipts' => 'Receipts & tax', 'notifications' => 'Notifications & data'] + ($bookTab ? ['books' => 'Books & e-books'] : []);
        $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $btn = fn ($method) => $method;
    @endphp

    <div class="flex gap-1 overflow-x-auto border-b border-gray-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" wire:key="st-{{ $key }}"
                class="whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium {{ $tab === $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5">
        @if ($tab === 'profile')
            <form wire:submit="saveProfile" class="space-y-4">
                <div class="flex items-center gap-4">
                    @if ($logo && $logo->isPreviewable()) <img src="{{ $logo->temporaryUrl() }}" class="h-16 w-16 rounded-full object-cover ring-1 ring-gray-200" alt="">
                    @elseif ($existingLogo) <img src="{{ asset('storage/'.$existingLogo) }}" class="h-16 w-16 rounded-full object-cover ring-1 ring-gray-200" alt="">
                    @else <span class="flex h-16 w-16 items-center justify-center rounded-full bg-brand-600 text-white"><x-lm.icon name="book" class="h-8 w-8" /></span> @endif
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Logo</label>
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="mt-1 text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700">
                        @error('logo') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" label="Library name" required wire:model="name" />
                    <x-ui.input name="email" label="Email" type="email" wire:model="email" />
                    <x-ui.input name="phone" label="Phone" type="tel" wire:model="phone" />
                    <x-ui.input name="currency" label="Currency" wire:model="currency" />
                    <x-ui.select name="timezone" label="Time zone" wire:model="timezone" hint="Dates, times and 'today' follow this zone.">
                        @foreach (timezone_identifiers_list() as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                    </x-ui.select>
                    <div></div>
                    <x-ui.input name="opening_time" label="Opening time" type="time" wire:model="opening_time" />
                    <x-ui.input name="closing_time" label="Closing time" type="time" wire:model="closing_time" />
                    <x-ui.textarea name="address" label="Address" wire:model="address" class="sm:col-span-2" />
                </div>
                <x-ui.button type="submit" wire:target="saveProfile,logo">Save profile</x-ui.button>
            </form>

        @elseif ($tab === 'hours')
            <form wire:submit="saveHours" class="space-y-5">
                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Working days</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($days as $n => $label)
                            <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700"><input type="checkbox" wire:model="working_days" value="{{ $n }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $label }}</label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium text-gray-700">Holidays</p>
                    <div class="flex flex-wrap items-start gap-2">
                        <x-ui.input name="holidayDate" type="date" wire:model="holidayDate" />
                        <x-ui.input name="holidayLabel" placeholder="e.g. Diwali" wire:model="holidayLabel" />
                        <x-ui.button variant="secondary" wire:click="addHoliday" class="mt-1">Add holiday</x-ui.button>
                    </div>
                    <ul class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                        @forelse ($holidays as $h)
                            <li class="flex items-center justify-between px-3 py-2" wire:key="hol-{{ $h['date'] }}"><span>{{ \Illuminate\Support\Carbon::parse($h['date'])->format('d M Y') }} — {{ $h['label'] }}</span><button type="button" wire:click="removeHoliday('{{ $h['date'] }}')" class="text-red-600 hover:text-red-800">Remove</button></li>
                        @empty
                            <li class="px-3 py-4 text-center text-gray-400">No holidays added.</li>
                        @endforelse
                    </ul>
                </div>
                <x-ui.button type="submit">Save hours &amp; holidays</x-ui.button>
            </form>

        @elseif ($tab === 'seats')
            <form wire:submit="saveSeats" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="seat_prefix" label="Seat number prefix" wire:model="seat_prefix" hint='Used when generating seats, e.g. "A-" gives A-01.' />
                    <x-ui.input name="seat_padding" label="Number length" type="number" min="1" max="5" wire:model="seat_padding" hint="2 gives 01, 02 …" />
                </div>
                <x-ui.button type="submit">Save seat settings</x-ui.button>
            </form>

        @elseif ($tab === 'attendance')
            <form wire:submit="saveAttendance" class="space-y-4">
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="attendance_require_membership" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Require a running membership to check in</span><br><span class="text-xs text-gray-500">Turn off to let students without a membership (for example a trial day) check in.</span></span></label>
                <x-ui.input name="attendance_grace_minutes" label="Late-arrival grace (minutes)" type="number" min="0" max="240" wire:model="attendance_grace_minutes" hint="A student is marked late when they arrive after their plan's shift start plus this grace." class="max-w-xs" />
                <x-ui.button type="submit">Save attendance rules</x-ui.button>
            </form>

        @elseif ($tab === 'books' && $bookTab)
            <form wire:submit="saveBooks" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="accession_prefix" label="Accession number prefix" wire:model="accession_prefix" hint="New copies are numbered like {{ strtoupper($accession_prefix ?: 'ACC') }}-{{ str_pad('1', max(3, min(10, (int) $accession_padding)), '0', STR_PAD_LEFT) }}. Existing numbers do not change." />
                    <x-ui.input name="accession_padding" label="Digits in the number" type="number" min="3" max="10" wire:model="accession_padding" />
                </div>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="isbn_lookup" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Fill book details from the ISBN</span><br><span class="text-xs text-gray-500">Looks the ISBN up in the free Open Library and Google Books catalogues. Only the ISBN is sent. Off: you type every detail.</span></span></label>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="ebook_require_membership" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Students need a running membership to read e-books</span><br><span class="text-xs text-gray-500">Off: any student with a login can read them.</span></span></label>
                @if (\App\Support\Entitlements::hasModule('books'))
                    <div class="border-t border-gray-100 pt-4">
                        <h4 class="text-sm font-semibold text-gray-900">Member types</h4>
                        <p class="mb-3 mt-1 text-xs text-gray-500">Each type gets its own lending rules below. Choose a member's type on their student record.</p>
                        <ul class="space-y-2">
                            @foreach ($memberTypesList as $key => $label)
                                <li class="flex items-center gap-2" wire:key="mt-{{ $key }}">
                                    <input type="text" wire:model="memberTypesList.{{ $key }}" maxlength="30" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                    @if ($key !== 'student') <button type="button" wire:click="removeMemberType('{{ $key }}')" wire:confirm="Remove this member type?" class="text-xs font-medium text-red-600 hover:text-red-800">Remove</button>
                                    @else <span class="text-xs text-gray-400">always present</span> @endif
                                    @error("memberTypesList.$key") <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-3 flex items-center gap-2">
                            <input type="text" wire:model="newMemberType" wire:keydown.enter.prevent="addMemberType" placeholder="New type, e.g. Alumni" maxlength="30" class="w-56 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <x-ui.button type="button" variant="secondary" wire:click="addMemberType">Add type</x-ui.button>
                        </div>
                        @error('newMemberType') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="border-t border-gray-100 pt-4">
                        <h4 class="text-sm font-semibold text-gray-900">Lending rules</h4>
                        <p class="mb-3 mt-1 text-xs text-gray-500">Set per member type. A member's type is chosen on their student record. Closed days and holidays (Hours &amp; holidays tab) never count as due dates or late days.</p>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="text-left text-xs text-gray-500">
                                    <th class="pb-2 pr-3 font-medium">Member type</th><th class="pb-2 pr-3 font-medium">Loan days</th><th class="pb-2 pr-3 font-medium">Max books</th><th class="pb-2 pr-3 font-medium">Renewals</th><th class="pb-2 pr-3 font-medium">Days per renewal</th>
                                    <th class="pb-2 pr-3 font-medium">Fine ₹/day</th><th class="pb-2 pr-3 font-medium">Grace days</th><th class="pb-2 pr-3 font-medium">Fine cap ₹</th><th class="pb-2 font-medium">Max reservations</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($memberTypesList as $type => $label)
                                        <tr class="border-t border-gray-100" wire:key="lr-{{ $type }}">
                                            <td class="py-2 pr-3 font-medium text-gray-900">{{ $label }}</td>
                                            @foreach (['loan_days', 'max_items', 'max_renewals', 'renewal_days', 'fine_per_day', 'grace_days', 'fine_cap', 'max_reservations'] as $field)
                                                <td class="py-2 pr-3"><input type="number" step="{{ $field === 'fine_per_day' || $field === 'fine_cap' ? '0.01' : '1' }}" min="0" wire:model="loanRules.{{ $type }}.{{ $field }}" @if ($field === 'fine_cap') placeholder="none" @endif class="w-20 rounded-lg border-gray-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                                                    @error("loanRules.$type.$field") <p class="text-xs text-red-600">{{ $message }}</p> @enderror</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <x-ui.input name="loan_no_prefix" label="Loan number prefix" wire:model="loan_no_prefix" />
                            <x-ui.input name="block_issue_fines_over" label="Stop issuing when unpaid fines reach ₹" type="number" step="0.01" min="0" wire:model="block_issue_fines_over" hint="0 = never stop." />
                            <x-ui.input name="repeat_overdue_alert" label="Alert after this many late returns (90 days)" type="number" min="0" wire:model="repeat_overdue_alert" hint="0 = no alerts." />
                            <x-ui.input name="lost_fine_multiplier" label="Lost book fine = price ×" type="number" step="0.1" min="0" wire:model="lost_fine_multiplier" />
                            <x-ui.input name="reservation_hold_days" label="Hold a returned copy for (days)" type="number" min="1" max="30" wire:model="reservation_hold_days" hint="For the member at the front of the queue." />
                            <x-ui.input name="lost_fine_extra" label="…plus handling fee ₹" type="number" step="0.01" min="0" wire:model="lost_fine_extra" />
                        </div>
                        <div class="mt-4 space-y-2.5 text-sm text-gray-700">
                            @foreach ([['circulation_require_membership', 'Members need a running membership to borrow', ''], ['block_same_title_twice', 'A member cannot hold two copies of the same title', ''], ['allow_renew_overdue', 'Allow renewing overdue books', ''], ['student_can_renew', 'Members can renew their own books from their page', ''], ['fine_skip_closed_days', 'Closed days and holidays do not earn fines', ''], ['reservations_enabled', 'Members can reserve a book that is out', '']] as [$prop, $text])
                                <label class="flex items-center gap-3"><input type="checkbox" wire:model="{{ $prop }}" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"> {{ $text }}</label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <x-ui.button type="submit">Save</x-ui.button>
            </form>

        @elseif ($tab === 'memberships')
            <form wire:submit="saveMemberships" class="space-y-4">
                <x-ui.input name="renewal_window_days" label="Expiring-soon window (days)" type="number" min="1" max="90" wire:model="renewal_window_days" hint="Memberships ending within this many days show as expiring and trigger reminders." class="max-w-xs" />
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="renewal_starts_after_expiry" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Renewals continue from the old end date</span><br><span class="text-xs text-gray-500">Off: a renewal starts today instead.</span></span></label>
                <x-ui.select name="academic_year_start_month" label="Academic year starts in" wire:model="academic_year_start_month" hint="Drives the Academic Year filter on the dashboard. April means Apr–Mar.">
                    @foreach (range(1, 12) as $mo) <option value="{{ $mo }}">{{ \Carbon\Carbon::create(2000, $mo, 1)->format('F') }}</option> @endforeach
                </x-ui.select>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="student_payment_submissions" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Let students send payment details</span><br><span class="text-xs text-gray-500">Students with a login can tell you they paid (amount, method, reference, proof). Nothing is recorded until staff confirm it.</span></span></label>
                <x-ui.button type="submit">Save membership rules</x-ui.button>
            </form>

        @elseif ($tab === 'receipts')
            <form wire:submit="saveReceipts" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="receipt_prefix" label="Receipt number prefix" required wire:model="receipt_prefix" hint="Capital letters/digits. Applies to new receipts; numbering continues." />
                    <x-ui.input name="tax_number" label="Tax / registration number" wire:model="tax_number" hint="Printed under the library name." />
                    <x-ui.textarea name="receipt_header" label="Receipt header text" wire:model="receipt_header" />
                    <x-ui.textarea name="receipt_footer" label="Receipt footer text" wire:model="receipt_footer" />
                    <x-ui.textarea name="tax_note" label="Tax note" wire:model="tax_note" class="sm:col-span-2" hint='e.g. "Prices include GST". Informational only.' />
                </div>
                <x-ui.button type="submit">Save receipt settings</x-ui.button>
            </form>

        @elseif ($tab === 'notifications')
            <form wire:submit="saveNotifications" class="space-y-4">
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="notify_expiry" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span class="font-medium">Remind about memberships that are about to expire</span></label>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="notify_dues" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span class="font-medium">Remind about outstanding fees</span></label>
                <label class="flex items-start gap-3 text-sm text-gray-700"><input type="checkbox" wire:model="export_enabled" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"><span><span class="font-medium">Allow data exports</span><br><span class="text-xs text-gray-500">Off: nobody can download CSV/Excel reports or student lists.</span></span></label>
                <x-ui.button type="submit">Save</x-ui.button>
            </form>
        @endif
    </div>
</div>
