<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }}: Library Management System{{ $offer ? ' | First '.$offer.' free' : '' }}</title>
    <meta name="description" content="Run your study library, reading room or school library from one place: students, seats, attendance, fees, books, barcode lending and reports.{{ $offer ? ' Start with '.$offer.' free.' : '' }}">
    <meta property="og:title" content="{{ $appName }}: Library Management System">
    <meta property="og:description" content="Students, seats, attendance, fees, books and reports in one simple system.{{ $offer ? ' First '.$offer.' free.' : '' }}">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ asset('marketing/dashboard.png') }}">
    <link rel="canonical" href="{{ url('/') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-white font-sans text-gray-900 antialiased">

@php
    $features = [
        ['users', 'Students & memberships', 'Everything about each member in one place.', ['Student register with photo, ID and contact details', 'Daily, weekly, monthly or quarterly plans', 'Renewals with expiry alerts before they lapse', 'Discounts, notes and full history', 'Student login to see their own details']],
        ['seat', 'Halls, floors & seats', 'Know who sits where, always.', ['Halls and floors with seat maps', 'Fixed and flexible seat allocation', 'Double booking is blocked automatically', 'Live view of free and occupied seats']],
        ['clock', 'Attendance', 'Check-in and check-out in seconds.', ['Check students in and out at the desk', 'Late arrivals marked automatically', 'Corrections kept with who changed what', 'Who is inside right now']],
        ['cash', 'Fees, dues & receipts', 'Collect every rupee and prove it.', ['Cash, UPI, bank transfer and card', 'Part payments with automatic dues tracking', 'Numbered receipts: print or download as PDF', 'Refunds and reversals with a reason on record', 'Students can send payment details for you to confirm']],
        ['receipt', 'Expenses & profit', 'See what is left at the end of the month.', ['Record rent, salaries and bills with receipts', 'Income against expense reports', 'Monthly and yearly figures on the dashboard']],
        ['chart', 'Reports & exports', 'The numbers owners actually ask for.', ['16+ ready reports: dues, expiry, attendance, income', 'Download as Excel, CSV or PDF', 'Dashboard that follows your academic year', 'Export your data any time. It is yours']],
        ['user-group', 'Team & permissions', 'Give each person only what they need.', ['Owner, manager, receptionist, accountant roles', 'Custom roles with fine-grained permissions', 'Several libraries under one login', 'Activity log of every sensitive action']],
        ['bell', 'Alerts & reminders', 'Never miss a renewal or a due book.', ['Expiring memberships and unpaid fees', 'Overdue and due-tomorrow book reminders', 'Notification bell for every user']],
    ];

    $bookFeatures = [
        ['book', 'Book catalogue', 'Books with covers, authors, categories, shelf and rack. Import thousands from Excel or CSV in one go, and fill in details automatically from the ISBN.'],
        ['printer', 'Barcode & QR labels', 'Every copy gets its own accession number. Print barcode or QR labels and use any USB scanner or phone.'],
        ['refresh', 'Issue, return & renew', 'Scan the member, scan the book. Due dates skip holidays, partial returns and renewals follow your rules.'],
        ['alert', 'Fines & waivers', 'Per-day fines with grace days and caps, lost and damaged book fees, and waivers only for authorised staff.'],
        ['ticket', 'Reservations', 'Members queue for books that are out. The returned copy is held for the next person and they are told.'],
        ['check-circle', 'Stock-taking', 'Scan the shelves and compare with the register. Missing copies are flagged and can be written off in one click.'],
        ['tablet', 'E-books', 'Upload PDFs or share links. Only your members can read them, with licence dates and usage counts.'],
        ['cog', 'Your rules, not ours', 'Loan days, limits, renewals and fines set separately for students, staff, faculty or your own member types.'],
    ];

    $steps = [
        ['1', 'Claim your offer or book a demo', 'Fill the short form below. It takes one minute.'],
        ['2', 'We set up your library', 'We create your account, halls, seats and plans. Usually the same day.'],
        ['3', 'Bring your data', 'Send your Excel sheets of students and books and we import them for you.'],
        ['4', 'Go live'.($offer ? ' with '.$offer.' free' : ''), 'Start taking students from day one. We stay on call to help.'],
    ];

    $faq = [
        [$offer ? 'How does the '.$offer.' free offer work?' : 'Can I try it first?', ($offer ? 'We set up your library and you use every feature of your plan free for '.$offer.'. ' : 'Yes. ').'No card or advance payment is needed. Near the end we tell you, and you choose a plan only if you want to continue. If you do not, nothing is charged and your data stays safe.'],
        ['Do I need to buy any hardware?', 'No. It runs in the browser on any phone, tablet or computer. A USB barcode scanner is useful if you lend books, but it is optional. Any common scanner works.'],
        ['Can you move my existing students and books?', 'Yes. Send us your Excel or CSV sheets and we import them for you, free. You can also import books yourself from the Books page.'],
        ['Is my data safe?', 'Each library\'s data is kept strictly separate. Passwords are protected, every sensitive action is logged, files are private, and we keep daily backups. Nothing is ever deleted when a plan ends.'],
        ['What if I have more than one branch?', 'Add each branch as its own library under one login, switch between them in one click, and keep students, fees and books separate for each.'],
        ['What about books and e-books?', 'Books and E-books are optional add-ons. Use only the study-room features, or add the library modules for a small extra monthly fee. We will switch them on for you.'],
        ['How do I pay after the free months?', 'By UPI or bank transfer. Upload the payment details in your account, we confirm it, and your invoice is ready to download.'],
        ['Can I cancel any time?', 'Yes. There are no contracts or hidden charges. If you stop, you can still download your data.'],
    ];
@endphp

{{-- offer bar --}}
@if ($offer)
    <div class="bg-brand-700 px-4 py-2 text-center text-sm font-medium text-white">
        Limited offer for new libraries: <strong>first {{ $offer }} free</strong>, no card needed.
        <a href="#offer" class="ml-1 underline decoration-white/60 underline-offset-2 hover:decoration-white">Claim it now →</a>
    </div>
@endif

{{-- header --}}
<header class="sticky top-0 z-30 border-b border-gray-100 bg-white/90 backdrop-blur" x-data="{ open: false }">
    <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
        <a href="{{ url('/') }}" class="flex items-center gap-2.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-white"><x-lm.icon name="book" class="h-5 w-5" /></span>
            <span class="leading-tight"><span class="block text-lg font-extrabold tracking-tight">{{ $appName }}</span><span class="block text-[11px] font-medium text-gray-500">Library Management System</span></span>
        </a>
        <nav class="hidden items-center gap-7 text-sm font-medium text-gray-600 lg:flex">
            <a href="#features" class="hover:text-gray-900">Features</a>
            <a href="#books" class="hover:text-gray-900">Library & books</a>
            <a href="#how" class="hover:text-gray-900">How it works</a>
            <a href="#pricing" class="hover:text-gray-900">Pricing</a>
            <a href="#faq" class="hover:text-gray-900">FAQ</a>
        </nav>
        <div class="flex items-center gap-2">
            <a href="{{ auth()->check() ? route('workspace') : route('login') }}" class="hidden rounded-lg px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 sm:inline-block">{{ auth()->check() ? 'Dashboard' : 'Log in' }}</a>
            <a href="{{ url('/') }}?type=demo#offer" class="hidden rounded-lg border border-gray-300 px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 md:inline-block">Book a demo</a>
            <a href="#offer" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">{{ $offer ? 'Start '.$offer.' free' : 'Get started' }}</a>
            <button type="button" class="rounded-lg p-2 text-gray-600 hover:bg-gray-100 lg:hidden" @click="open = !open" aria-label="Menu"><x-lm.icon name="menu" class="h-6 w-6" /></button>
        </div>
    </div>
    <div x-show="open" x-cloak class="border-t border-gray-100 bg-white px-4 py-3 text-sm font-medium text-gray-700 lg:hidden" @click="open = false">
        <a href="#features" class="block py-2">Features</a><a href="#books" class="block py-2">Library & books</a><a href="#how" class="block py-2">How it works</a>
        <a href="#pricing" class="block py-2">Pricing</a><a href="#faq" class="block py-2">FAQ</a><a href="{{ auth()->check() ? route('workspace') : route('login') }}" class="block py-2 font-semibold">{{ auth()->check() ? 'Dashboard' : 'Log in' }}</a>
    </div>
</header>

{{-- hero --}}
<section class="relative overflow-hidden bg-gradient-to-b from-brand-50 via-white to-white">
    <div class="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-brand-200/50 blur-3xl"></div>
    <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 py-14 sm:px-6 lg:grid-cols-2 lg:px-8 lg:py-20">
        <div>
            @if ($offer) <span class="inline-flex items-center gap-2 rounded-full bg-brand-100 px-4 py-1.5 text-sm font-semibold text-brand-800"><span class="h-2 w-2 rounded-full bg-brand-600"></span> First {{ $offer }} free for new libraries</span> @endif
            <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-tight text-gray-900 sm:text-5xl">Run your whole library from <span class="text-brand-600">one simple screen</span></h1>
            <p class="mt-5 max-w-xl text-lg leading-8 text-gray-600">Students, seats, attendance, fees and dues, books and barcode lending, reports and a student portal. Built for study libraries, reading rooms, coaching centres and school libraries, from one room to many branches.</p>
            <div class="mt-8 flex flex-wrap gap-3">
                <a href="#offer" class="inline-flex items-center rounded-xl bg-brand-600 px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-brand-600/30 hover:bg-brand-700">{{ $offer ? 'Claim '.$offer.' free' : 'Get started' }}</a>
                <a href="{{ url('/') }}?type=demo#offer" class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-6 py-3.5 text-base font-semibold text-gray-800 hover:bg-gray-50">Book a free demo</a>
            </div>
            <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600">
                @foreach (['No card needed', 'We set it up for you', 'Move your data free', 'Cancel any time'] as $t)
                    <li class="flex items-center gap-1.5"><x-lm.icon name="check-circle" class="h-4 w-4 text-brand-600" /> {{ $t }}</li>
                @endforeach
            </ul>
        </div>
        <div class="relative">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl shadow-gray-300/60">
                <div class="flex items-center gap-1.5 border-b border-gray-100 bg-gray-50 px-4 py-2.5"><span class="h-2.5 w-2.5 rounded-full bg-red-300"></span><span class="h-2.5 w-2.5 rounded-full bg-amber-300"></span><span class="h-2.5 w-2.5 rounded-full bg-brand-300"></span></div>
                <img src="{{ asset('marketing/dashboard.png') }}" alt="The library dashboard: students, memberships, seats, books issued, overdue books and fines" width="1440" height="1500" class="w-full" fetchpriority="high">
            </div>
            @if ($offer)
                <div class="absolute -bottom-5 -left-3 rotate-[-4deg] rounded-2xl bg-white px-5 py-3 shadow-xl ring-1 ring-gray-200 sm:-left-6">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">Launch offer</p>
                    <p class="text-2xl font-extrabold text-gray-900">{{ $offer }} <span class="text-brand-600">FREE</span></p>
                </div>
            @endif
        </div>
    </div>
</section>

{{-- who --}}
<section class="border-y border-gray-100 bg-white py-8">
    <div class="mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">Made for</p>
        <div class="mt-3 flex flex-wrap items-center justify-center gap-x-10 gap-y-2 text-base font-semibold text-gray-600">
            <span>Study libraries</span><span>Reading rooms</span><span>Coaching &amp; self-study centres</span><span>School &amp; college libraries</span><span>Community libraries</span><span>Multi-branch chains</span>
        </div>
    </div>
</section>

{{-- features --}}
<section id="features" class="bg-white py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Everything in one place</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Every feature your library needs, from day one</h2>
            <p class="mt-4 text-lg text-gray-600">No add-on tools, no spreadsheets. Here is what you get in the panel.</p>
        </div>
        <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($features as [$icon, $title, $lead, $points])
                <div class="rounded-2xl border border-gray-200 bg-white p-6 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-lg">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-100 text-brand-700"><x-lm.icon :name="$icon" class="h-6 w-6" /></span>
                    <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-gray-500">{{ $lead }}</p>
                    <ul class="mt-4 space-y-2 text-sm text-gray-700">
                        @foreach ($points as $p) <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /><span>{{ $p }}</span></li> @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- product tour --}}
<section class="bg-gray-50 py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">See it working</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Simple for your staff. Clear for your students.</h2>
        </div>
        <div class="mt-12 grid gap-8 lg:grid-cols-3">
            @foreach ([['books.png', 'The book catalogue', 'Search by title, author, ISBN or accession number. See how many copies are on the shelf.', 1440, 820], ['circulation.png', 'Issue and return in seconds', 'Pick the member, scan the books. Limits, due dates and fines are handled for you.', 1440, 820], ['portal.png', 'A portal for every student', 'Students see their membership, seat, payments, borrowed books and can renew or reserve.', 1440, 900]] as [$img, $t, $d, $w, $h])
                <figure class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                    <img src="{{ asset('marketing/'.$img) }}" alt="{{ $t }}" width="{{ $w }}" height="{{ $h }}" loading="lazy" class="w-full border-b border-gray-100">
                    <figcaption class="p-5"><h3 class="font-bold">{{ $t }}</h3><p class="mt-1 text-sm text-gray-600">{{ $d }}</p></figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>

{{-- books module --}}
<section id="books" class="bg-white py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Library &amp; books module</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">A complete book lending system, built in</h2>
            <p class="mt-4 text-lg text-gray-600">Lend physical books and share e-books with the same students you already manage. Switch it on only if you need it.</p>
        </div>
        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($bookFeatures as [$icon, $title, $text])
                <div class="rounded-2xl bg-gray-50 p-6">
                    <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-white text-brand-700 shadow-sm"><x-lm.icon :name="$icon" class="h-5 w-5" /></span>
                    <h3 class="mt-4 font-bold">{{ $title }}</h3>
                    <p class="mt-1.5 text-sm leading-6 text-gray-600">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- how --}}
<section id="how" class="bg-gray-900 py-20 text-white">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-400">How it works</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Live in about a day</h2>
        </div>
        <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($steps as [$n, $t, $d])
                <li class="rounded-2xl bg-white/5 p-6 ring-1 ring-white/10">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-600 text-lg font-bold">{{ $n }}</span>
                    <h3 class="mt-4 font-bold">{{ $t }}</h3><p class="mt-1.5 text-sm text-gray-300">{{ $d }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- pricing --}}
<section id="pricing" class="bg-white py-20">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Pricing</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Simple plans. {{ $offer ? 'The first '.$offer.' are free.' : 'No surprises.' }}</h2>
            <p class="mt-4 text-lg text-gray-600">Pick the size that fits your library. Change plans whenever you grow.</p>
        </div>

        @if ($plans->isNotEmpty())
            <div class="mt-12 grid gap-6 {{ $plans->count() >= 3 ? 'lg:grid-cols-3' : ($plans->count() === 2 ? 'lg:grid-cols-2 lg:mx-auto lg:max-w-4xl' : 'lg:mx-auto lg:max-w-md') }}">
                @foreach ($plans as $i => $plan)
                    @php
                        $popular = $plans->count() >= 3 && $i === 1;
                        $annual = $plan->price_annual ? (float) $plan->price_annual : null;
                        $save = $annual && $plan->price_monthly ? max(0, round((1 - $annual / ((float) $plan->price_monthly * 12)) * 100)) : 0;
                    @endphp
                    <div class="relative flex flex-col rounded-2xl border {{ $popular ? 'border-brand-500 shadow-2xl shadow-brand-600/10 ring-1 ring-brand-500' : 'border-gray-200 shadow-sm' }} bg-white p-7">
                        @if ($popular) <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-brand-600 px-4 py-1 text-xs font-bold uppercase tracking-wide text-white">Most popular</span> @endif
                        <h3 class="text-xl font-bold">{{ $plan->name }}</h3>
                        @if ($plan->description) <p class="mt-1 min-h-10 text-sm text-gray-500">{{ $plan->description }}</p> @endif
                        <p class="mt-5 flex items-end gap-1"><span class="text-4xl font-extrabold">₹{{ number_format((float) $plan->price_monthly) }}</span><span class="pb-1 text-gray-500">/ month</span></p>
                        @if ($annual) <p class="mt-1 text-sm text-gray-500">or ₹{{ number_format($annual) }} a year @if ($save > 0) <span class="font-semibold text-brand-700"> (save {{ $save }}%)</span>@endif</p> @endif
                        @if ($offer) <p class="mt-3 inline-block self-start rounded-md bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-800">First {{ $offer }} free</p> @endif
                        <ul class="mt-6 flex-1 space-y-2.5 text-sm text-gray-700">
                            <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> {{ $plan->max_students ? 'Up to '.number_format($plan->max_students).' students' : 'Unlimited students' }}</li>
                            <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> {{ $plan->max_seats ? 'Up to '.number_format($plan->max_seats).' seats' : 'Unlimited seats' }}</li>
                            <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> {{ $plan->max_staff ? $plan->max_staff.' staff accounts' : 'Unlimited staff accounts' }}</li>
                            <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> Students, seats, fees, receipts and student portal</li>
                            @foreach ((array) $plan->modules as $m) @if (isset(\App\Models\SubscriptionPlan::MODULES[$m])) <li class="flex gap-2"><x-lm.icon name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" /> {{ \App\Models\SubscriptionPlan::MODULES[$m] }}</li> @endif @endforeach
                        </ul>
                        <a href="{{ url('/') }}?plan={{ $plan->id }}#offer" class="mt-7 block rounded-xl {{ $popular ? 'bg-brand-600 text-white hover:bg-brand-700' : 'border border-gray-300 text-gray-900 hover:bg-gray-50' }} px-5 py-3 text-center text-sm font-semibold">{{ $offer ? 'Start '.$offer.' free' : 'Choose '.$plan->name }}</a>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mx-auto mt-12 max-w-4xl rounded-2xl border border-dashed border-brand-300 bg-brand-50/60 p-6 sm:p-8">
            <h3 class="text-lg font-bold">Add the library modules when you need them</h3>
            <p class="mt-1 text-sm text-gray-600">Books and E-books are optional add-ons for any plan, billed together with your subscription.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach ($addons as $a)
                    <div class="rounded-xl bg-white p-5 ring-1 ring-gray-200">
                        <div class="flex items-baseline justify-between"><p class="font-bold">{{ $a['name'] }}</p>@if ($a['price']) <p class="text-sm"><span class="text-xl font-extrabold">₹{{ number_format((float) $a['price']) }}</span> <span class="text-gray-500">/ month</span></p> @endif</div>
                        <p class="mt-1.5 text-sm text-gray-600">{{ $a['name'] === 'E-books' ? 'Digital books for your members, with licence dates and usage reports.' : 'Catalogue, barcode lending, fines, reservations and stock-taking.' }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- offer + form --}}
<section id="offer" class="relative overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-emerald-600 py-20 text-white">
    <div class="pointer-events-none absolute -left-20 top-10 h-72 w-72 rounded-full bg-white/10 blur-3xl"></div>
    <div class="relative mx-auto grid max-w-7xl items-start gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
        <div class="lg:pt-6">
            @if ($offer) <span class="inline-flex rounded-full bg-white/15 px-4 py-1.5 text-sm font-semibold">Launch offer</span> @endif
            <h2 class="mt-4 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">{{ $offer ? 'Join today and get your first '.$offer.' free' : 'Start managing your library better today' }}</h2>
            <p class="mt-5 max-w-lg text-lg text-brand-50">Tell us a little about your library and we will take it from there. Or book a free demo and see everything first.</p>
            <ul class="mt-8 space-y-3 text-base">
                @foreach (array_filter([$offer ? 'Every feature of your plan free for '.$offer : null, 'We create your account, seats and plans', 'Free import of your student and book sheets', 'Training for your staff', 'No card, no contract, cancel any time'] ) as $t)
                    <li class="flex gap-3"><x-lm.icon name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-brand-200" /> {{ $t }}</li>
                @endforeach
            </ul>
            @if ($supportPhone || $supportEmail)
                <p class="mt-8 text-sm text-brand-50">Prefer to talk? {{ $supportPhone ? 'Call or WhatsApp '.$supportPhone : '' }}{{ $supportPhone && $supportEmail ? ' or ' : '' }}{{ $supportEmail ? 'write to '.$supportEmail : '' }}.</p>
            @endif
        </div>
        <div class="text-gray-900">
            @livewire('lead-form')
        </div>
    </div>
</section>

{{-- faq --}}
<section id="faq" class="bg-white py-20">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <p class="text-sm font-semibold uppercase tracking-widest text-brand-600">Questions</p>
            <h2 class="mt-2 text-3xl font-extrabold tracking-tight sm:text-4xl">Good to know</h2>
        </div>
        <div class="mt-10 divide-y divide-gray-200 rounded-2xl border border-gray-200">
            @foreach ($faq as [$q, $a])
                <details class="group px-6 py-4" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-base font-semibold text-gray-900">{{ $q }}<span class="text-xl leading-none text-brand-600 transition group-open:rotate-45">+</span></summary>
                    <p class="mt-3 text-sm leading-6 text-gray-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- final call --}}
<section class="bg-brand-50 py-14">
    <div class="mx-auto max-w-3xl px-4 text-center">
        <h2 class="text-2xl font-extrabold sm:text-3xl">Ready to try it in your library?</h2>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="#offer" class="rounded-xl bg-brand-600 px-6 py-3.5 font-semibold text-white shadow-lg shadow-brand-600/30 hover:bg-brand-700">{{ $offer ? 'Claim '.$offer.' free' : 'Get started' }}</a>
            <a href="{{ url('/') }}?type=demo#offer" class="rounded-xl border border-gray-300 bg-white px-6 py-3.5 font-semibold text-gray-800 hover:bg-gray-50">Book a free demo</a>
        </div>

        @if ($whatsapp || $supportEmail)
            <div id="contact" class="mt-12 border-t border-brand-100 pt-10">
                <h3 class="text-2xl font-extrabold">Want this for your library?</h3>
                <p class="mt-2 text-gray-600">Get in touch for a live demo, pricing, or a custom setup for your institution. Talk to us directly.</p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    @if ($whatsapp) <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-brand-700"><x-lm.icon name="chat" class="h-5 w-5" /> {{ $supportPhone }}</a> @endif
                    @if ($supportEmail) <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode('Library software enquiry') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-800 hover:bg-gray-50"><x-lm.icon name="mail" class="h-5 w-5 text-gray-500" /> {{ $supportEmail }}</a> @endif
                </div>
            </div>
        @endif
    </div>
</section>

{{-- footer --}}
<footer class="bg-gray-950 text-sm text-gray-400">
    <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <div class="grid gap-10 text-center sm:grid-cols-2 sm:text-left lg:grid-cols-[1.4fr_1fr_1fr]">
            <div>
                <div class="flex items-center justify-center gap-3 text-white sm:justify-start">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600"><x-lm.icon name="book" class="h-5 w-5" /></span>
                    <span class="leading-tight"><span class="block text-lg font-extrabold tracking-tight">{{ $appName }}</span><span class="block whitespace-nowrap text-xs text-gray-400">Library Management System</span></span>
                </div>
                <p class="mx-auto mt-4 max-w-sm leading-6 sm:mx-0">Students, seats, fees, books and reports for study libraries, reading rooms and school libraries.</p>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-500">Explore</h3>
                <ul class="mt-4 space-y-2.5">
                    <li><a href="#features" class="hover:text-white">Features</a></li>
                    <li><a href="#books" class="hover:text-white">Library &amp; books</a></li>
                    <li><a href="#pricing" class="hover:text-white">Pricing</a></li>
                    <li><a href="#faq" class="hover:text-white">FAQ</a></li>
                    <li><a href="{{ auth()->check() ? route('workspace') : route('login') }}" class="hover:text-white">{{ auth()->check() ? 'Dashboard' : 'Log in' }}</a></li>
                </ul>
            </div>

            <div>
                <h3 class="text-xs font-semibold uppercase tracking-widest text-gray-500">Contact us</h3>
                <ul class="mt-4 space-y-2.5">
                    @if ($supportPhone) <li><a href="{{ $whatsapp ?? 'tel:'.preg_replace('/\s+/', '', $supportPhone) }}" @if ($whatsapp) target="_blank" rel="noopener" @endif class="inline-flex items-center gap-2 hover:text-white"><x-lm.icon name="chat" class="h-4 w-4 text-brand-500" /> {{ $supportPhone }}</a></li> @endif
                    @if ($supportEmail) <li><a href="mailto:{{ $supportEmail }}" class="inline-flex items-center gap-2 break-all hover:text-white"><x-lm.icon name="mail" class="h-4 w-4 shrink-0 text-brand-500" /> {{ $supportEmail }}</a></li> @endif
                    <li><a href="#offer" class="font-semibold text-brand-400 hover:text-brand-300">{{ $offer ? 'Claim '.$offer.' free →' : 'Get started →' }}</a></li>
                </ul>
            </div>
        </div>

        <div class="mt-10 flex flex-col items-center justify-between gap-2 border-t border-gray-800 pt-6 text-xs text-gray-500 sm:flex-row">
            <p>© {{ now()->year }} {{ $appName }}. All rights reserved.</p>
            <p>Developed by <a href="https://laravelexpert.in" target="_blank" rel="noopener" class="underline decoration-gray-600 underline-offset-2 hover:text-white">laravelexpert.in</a> — Nirbhay Dhaked</p>
        </div>
    </div>
</footer>
@if ($whatsapp)
    <a href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp" title="Chat with us on WhatsApp"
        class="fixed bottom-5 right-5 z-40 flex h-14 w-14 items-center justify-center rounded-full bg-brand-600 text-white shadow-xl shadow-brand-600/40 ring-4 ring-white transition hover:scale-105 hover:bg-brand-700 sm:bottom-6 sm:right-6">
        <x-lm.icon name="chat" class="h-7 w-7" />
    </a>
@endif

@livewireScripts
</body>
</html>
