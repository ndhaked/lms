<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $kind }} {{ $number }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; }
        h1 { font-size: 18px; margin: 0 0 3px; }
        .muted { color: #6b7280; }
        .small { font-size: 9.5px; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding-bottom: 12px; border-bottom: 1px solid #d1d5db; }
        .kind { font-size: 9px; text-transform: uppercase; letter-spacing: .6px; color: #9ca3af; }
        .number { font-size: 15px; font-weight: bold; }
        .party { margin-top: 14px; }
        .party td { vertical-align: top; width: 50%; }
        .items { margin-top: 18px; }
        .items th { text-align: left; font-size: 9.5px; color: #6b7280; padding: 0 0 6px; border-bottom: 1px solid #d1d5db; font-weight: normal; }
        .items td { padding: 6px 0; border-bottom: 1px solid #f3f4f6; }
        .total td { padding-top: 10px; font-weight: bold; font-size: 13px; border: 0; }
        .box { margin-top: 14px; background: #fffbeb; border: 1px solid #fde68a; padding: 7px 9px; font-size: 9.5px; color: #92400e; }
        .reversed { color: #b91c1c; margin-top: 14px; font-size: 10px; }
        .foot { margin-top: 26px; padding-top: 8px; border-top: 1px solid #d1d5db; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    @if (filled($headerNote ?? null)) <p class="muted small" style="text-align:center">{!! nl2br(e($headerNote)) !!}</p> @endif

    <table class="head"><tr>
        <td>
            <h1>{{ $sender['name'] }}</h1>
            @foreach ($sender['lines'] as $line) @if (filled($line)) <div class="muted small">{!! nl2br(e($line)) !!}</div> @endif @endforeach
        </td>
        <td class="right">
            <div class="kind">{{ $kind }}</div>
            <div class="number">{{ $number }}</div>
            <div class="muted small">{{ $date }}</div>
        </td>
    </tr></table>

    <table class="party"><tr>
        <td>
            <div class="muted small">{{ $party['label'] }}</div>
            <div style="font-weight:bold">{{ $party['name'] }}</div>
            @foreach ($party['lines'] as $line) @if (filled($line)) <div class="muted small">{{ $line }}</div> @endif @endforeach
        </td>
        <td class="right">
            @foreach ($meta as [$k, $v]) @if (filled($v)) <div class="muted small">{{ $k }}</div><div style="font-weight:bold; margin-bottom:3px">{{ $v }}</div> @endif @endforeach
        </td>
    </tr></table>

    @if (count($rows))
        <table class="items">
            <thead><tr>@foreach ($columns as $i => $c) <th class="{{ $i === count($columns) - 1 ? 'right' : '' }}">{{ $c }}</th> @endforeach</tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>@foreach ($row as $i => $cell) <td class="{{ $i === count($row) - 1 ? 'right' : '' }}">{!! nl2br(e($cell)) !!}</td> @endforeach</tr>
                @endforeach
            </tbody>
            @if (filled($total ?? null))
                <tfoot><tr class="total"><td colspan="{{ count($columns) - 1 }}" class="right">{{ $totalLabel }}</td><td class="right">{{ $total }}</td></tr></tfoot>
            @endif
        </table>
    @endif

    @foreach (($notes ?? []) as $note) <div class="box">{{ $note }}</div> @endforeach
    @if (filled($reversed ?? null)) <div class="reversed">{{ $reversed }}</div> @endif
    @if (filled($taxNote ?? null)) <p class="muted small" style="margin-top:12px">{{ $taxNote }}</p> @endif

    <div class="foot">{{ $footer }}@if (filled($receiptFooter ?? null))<br>{!! nl2br(e($receiptFooter)) !!}@endif</div>
</body>
</html>
