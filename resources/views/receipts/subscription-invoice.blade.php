<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
    <div class="no-print mx-auto flex max-w-2xl justify-end px-4 pt-6">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download PDF</a>
        <button onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Print</button>
    </div>

    <main class="mx-auto my-6 max-w-2xl rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-200 print:my-0 print:shadow-none print:ring-0">
        <header class="flex items-start justify-between border-b border-gray-200 pb-4">
            <div>
                <h1 class="text-xl font-bold">{{ $platform['app_name'] ?: config('app.name') }}</h1>
                <p class="mt-1 text-xs text-gray-500">{{ collect([$platform['support_email'], $platform['support_phone']])->filter()->implode(' · ') }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-gray-400">Subscription invoice</p>
                <p class="text-lg font-semibold">{{ $invoice->invoice_no }}</p>
                <p class="text-xs text-gray-500">{{ $invoice->issued_on->format('d M Y') }}</p>
            </div>
        </header>

        <section class="mt-5 grid grid-cols-2 gap-4 text-sm">
            <div><p class="text-xs text-gray-400">Billed to</p><p class="font-medium">{{ $invoice->library->name }}</p><p class="text-xs text-gray-500">{{ $invoice->library->code }}</p>@if ($invoice->library->address)<p class="whitespace-pre-line text-xs text-gray-500">{{ $invoice->library->address }}</p>@endif</div>
            <div class="text-right"><p class="text-xs text-gray-400">Status</p><p class="font-medium capitalize">{{ $invoice->status }}</p>@if ($invoice->payment)<p class="text-xs text-gray-500">Payment ref: {{ $invoice->payment->reference }}</p>@endif</div>
        </section>

        <table class="mt-6 w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500"><th class="pb-2 font-medium">Description</th><th class="pb-2 font-medium">Period</th><th class="pb-2 text-right font-medium">Amount</th></tr></thead>
            <tbody>
                @forelse ((array) $invoice->lines as $line)
                    <tr class="border-b border-gray-100"><td class="py-2">{{ $line['label'] }}</td><td class="py-2 text-gray-600">{{ $invoice->period_starts_on->format('d M Y') }} – {{ $invoice->period_ends_on->format('d M Y') }}</td><td class="py-2 text-right">₹{{ number_format((float) $line['amount'], 2) }}</td></tr>
                @empty
                    <tr class="border-b border-gray-100"><td class="py-2">{{ $invoice->description }}</td><td class="py-2 text-gray-600">{{ $invoice->period_starts_on->format('d M Y') }} – {{ $invoice->period_ends_on->format('d M Y') }}</td><td class="py-2 text-right">₹{{ number_format((float) $invoice->amount, 2) }}</td></tr>
                @endforelse
            </tbody>
            <tfoot><tr><td colspan="2" class="pt-3 text-right text-sm font-semibold">Total</td><td class="pt-3 text-right text-lg font-bold">₹{{ number_format((float) $invoice->amount, 2) }}</td></tr></tfoot>
        </table>

        <footer class="mt-8 border-t border-gray-200 pt-4 text-center text-xs text-gray-500">This is a computer-generated invoice for the library's platform subscription. It is separate from the fees the library collects from its students.</footer>
    </main>
</body>
</html>
