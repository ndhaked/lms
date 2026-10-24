<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $payment->receipt_no }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
    <div class="no-print mx-auto flex max-w-xl justify-end gap-2 px-4 pt-6">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download PDF</a>
        <button onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Print</button>
    </div>

    <main class="relative mx-auto my-6 max-w-xl overflow-hidden rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-200 print:my-0 print:shadow-none print:ring-0">
        @if ($payment->isReversed())
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                <span class="-rotate-12 rounded-lg border-4 border-red-300 px-6 py-2 text-5xl font-extrabold uppercase tracking-widest text-red-200">Reversed</span>
            </div>
        @endif

        @php $s = \App\Support\LibrarySettings::all($library->id); @endphp
        @if (filled($s['receipt_header'])) <p class="mb-3 whitespace-pre-line text-center text-xs text-gray-500">{{ $s['receipt_header'] }}</p> @endif

        <header class="flex items-start justify-between border-b border-gray-200 pb-4">
            <div>
                @if ($library->logo_path) <img src="{{ asset('storage/'.$library->logo_path) }}" alt="" class="mb-2 h-12 w-auto"> @endif
                <h1 class="text-xl font-bold">{{ $library->name }}</h1>
                @if (filled($s['tax_number'])) <p class="mt-0.5 text-xs text-gray-500">{{ $s['tax_number'] }}</p> @endif
                @if ($library->address) <p class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ $library->address }}</p> @endif
                <p class="mt-1 text-xs text-gray-500">{{ collect([$library->phone, $library->email])->filter()->implode(' · ') }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-gray-400">Receipt</p>
                <p class="text-lg font-semibold">{{ $payment->receipt_no }}</p>
                <p class="text-xs text-gray-500">{{ $payment->paid_on->format('d M Y') }}</p>
            </div>
        </header>

        <section class="mt-5 grid grid-cols-2 gap-4 text-sm">
            <div><p class="text-xs text-gray-400">Received from</p><p class="font-medium">{{ $payment->student->name }}</p><p class="text-xs text-gray-500">{{ $payment->student->student_code }} · {{ $payment->student->phone }}</p></div>
            <div class="text-right"><p class="text-xs text-gray-400">Payment method</p><p class="font-medium">{{ \App\Models\Payment::METHODS[$payment->method] ?? $payment->method }}</p>@if ($payment->reference)<p class="text-xs text-gray-500">Ref: {{ $payment->reference }}</p>@endif</div>
        </section>

        <table class="mt-6 w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500"><th class="pb-2 font-medium">Towards</th><th class="pb-2 text-right font-medium">Amount</th></tr></thead>
            <tbody>
                @foreach ($payment->allocations as $a)
                    <tr class="border-b border-gray-100">
                        <td class="py-2">{{ $a->membership->plan->name }} <span class="text-xs text-gray-400">({{ $a->membership->starts_on->format('d M Y') }} – {{ $a->membership->ends_on->format('d M Y') }})</span></td>
                        <td class="py-2 text-right">₹{{ number_format((float) $a->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td class="pt-3 text-right text-sm font-semibold">Total received</td><td class="pt-3 text-right text-lg font-bold">₹{{ number_format((float) $payment->amount, 2) }}</td></tr>
            </tfoot>
        </table>

        @if ($payment->refunds->isNotEmpty())
            <section class="mt-6 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">
                <p class="mb-1 font-semibold">Refunds against this receipt</p>
                @foreach ($payment->refunds as $r)
                    <p>{{ $r->refund_no }} · {{ $r->refunded_on->format('d M Y') }} · ₹{{ number_format((float) $r->amount, 2) }} — {{ $r->reason }}</p>
                @endforeach
            </section>
        @endif

        @if ($payment->isReversed())
            <p class="mt-6 text-xs text-red-600">This payment was reversed on {{ $payment->reversed_at->format('d M Y') }}: {{ $payment->reversal_reason }}</p>
        @endif

        @if (filled($s['tax_note'])) <p class="mt-4 text-xs text-gray-500">{{ $s['tax_note'] }}</p> @endif

        <footer class="mt-8 flex items-end justify-between border-t border-gray-200 pt-4 text-xs text-gray-500">
            <span>Recorded by {{ $payment->recorder?->name ?? '—' }}</span>
            <span>Computer-generated receipt</span>
        </footer>
        @if (filled($s['receipt_footer'])) <p class="mt-3 whitespace-pre-line text-center text-xs text-gray-500">{{ $s['receipt_footer'] }}</p> @endif
    </main>
</body>
</html>
