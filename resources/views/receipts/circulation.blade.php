<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ['issue' => 'Issue slip', 'return' => 'Return receipt', 'fine' => 'Fine receipt'][$kind] }} {{ $number }}</title>
    @vite(['resources/css/app.css'])
    <style>@media print { .no-print { display: none !important; } body { background: #fff !important; } }</style>
</head>
<body class="bg-gray-100 font-sans text-gray-900 antialiased">
    <div class="no-print mx-auto flex max-w-xl justify-end gap-2 px-4 pt-6">
        <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Download PDF</a>
        <button onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Print</button>
    </div>

    <main class="mx-auto my-6 max-w-xl rounded-xl bg-white p-8 shadow-sm ring-1 ring-gray-200 print:my-0 print:shadow-none print:ring-0">
        @php $s = \App\Support\LibrarySettings::all($library->id); @endphp
        @if (filled($s['receipt_header'])) <p class="mb-3 whitespace-pre-line text-center text-xs text-gray-500">{{ $s['receipt_header'] }}</p> @endif

        <header class="flex items-start justify-between border-b border-gray-200 pb-4">
            <div>
                <h1 class="text-xl font-bold">{{ $library->name }}</h1>
                @if ($library->address) <p class="mt-1 whitespace-pre-line text-xs text-gray-500">{{ $library->address }}</p> @endif
                <p class="mt-1 text-xs text-gray-500">{{ collect([$library->phone, $library->email])->filter()->implode(' · ') }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-gray-400">{{ ['issue' => 'Issue slip', 'return' => 'Return receipt', 'fine' => 'Fine receipt'][$kind] }}</p>
                <p class="text-lg font-semibold">{{ $number }}</p>
                <p class="text-xs text-gray-500">{{ $date?->format('d M Y, H:i') }}</p>
            </div>
        </header>

        <section class="mt-5 text-sm">
            <p class="text-xs text-gray-400">Member</p>
            <p class="font-medium">{{ $student->name }}</p>
            <p class="text-xs text-gray-500">{{ $student->student_code }} · {{ $student->phone }}</p>
        </section>

        @if ($items->isNotEmpty())
            <table class="mt-6 w-full text-sm">
                <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500">
                    <th class="pb-2 font-medium">Book</th><th class="pb-2 font-medium">Accession</th>
                    <th class="pb-2 text-right font-medium">{{ $kind === 'issue' ? 'Due date' : 'Condition' }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($items as $i)
                        <tr class="border-b border-gray-100">
                            <td class="py-2">{{ $i->book->title }}@if ($i->book->author)<span class="block text-xs text-gray-400">{{ $i->book->author }}</span>@endif</td>
                            <td class="py-2 text-gray-600">{{ $i->copy->accession_no }}</td>
                            <td class="py-2 text-right">{{ $kind === 'issue' ? $i->due_on->format('d M Y') : ucfirst($i->return_condition ?? 'good') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($fines->isNotEmpty())
            <table class="mt-6 w-full text-sm">
                <thead><tr class="border-b border-gray-200 text-left text-xs text-gray-500"><th class="pb-2 font-medium">{{ $kind === 'fine' ? 'Fine paid' : 'Fines raised' }}</th><th class="pb-2 text-right font-medium">Amount</th></tr></thead>
                <tbody>
                    @foreach ($fines as $f)
                        <tr class="border-b border-gray-100"><td class="py-2">{{ \App\Models\Fine::TYPES[$f->type] ?? $f->type }}<span class="block text-xs text-gray-400">{{ $f->note }}</span></td><td class="py-2 text-right">₹{{ number_format((float) ($kind === 'fine' ? $f->paid_amount : $f->amount), 2) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td class="pt-3 text-right text-sm font-semibold">Total</td><td class="pt-3 text-right text-lg font-bold">₹{{ number_format((float) $fines->sum(fn ($f) => (float) ($kind === 'fine' ? $f->paid_amount : $f->amount)), 2) }}</td></tr></tfoot>
            </table>
            @if ($kind === 'return') <p class="mt-2 text-xs text-gray-500">Fines can be paid at the library desk.</p> @endif
        @endif

        @if ($kind === 'issue') <p class="mt-6 text-xs text-gray-500">Please return the books by their due dates to avoid fines.</p> @endif
        @if (filled($s['receipt_footer'])) <p class="mt-6 whitespace-pre-line border-t border-gray-100 pt-4 text-center text-xs text-gray-500">{{ $s['receipt_footer'] }}</p> @endif
        <p class="mt-4 text-center text-[11px] text-gray-400">{{ $by ? 'Handled by '.$by : '' }}</p>
    </main>
</body>
</html>
