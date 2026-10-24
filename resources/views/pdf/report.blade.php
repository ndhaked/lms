<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #111827; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; font-size: 8px; color: #374151; background: #f3f4f6; padding: 4px 5px; border-bottom: 1px solid #d1d5db; }
        td { padding: 3.5px 5px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .sum { margin-top: 6px; font-size: 9px; }
        .sum span { margin-right: 14px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="muted">{{ $library }} · generated {{ $generated }}@if (filled($range)) · {{ $range }}@endif</div>
    @if ($summary) <div class="sum">@foreach ($summary as $k => $v) <span><b>{{ $k }}:</b> {{ $v }}</span> @endforeach</div> @endif

    <table>
        <thead><tr>@foreach ($headers as $h) <th>{{ $h }}</th> @endforeach</tr></thead>
        <tbody>
            @foreach ($rows as $row) <tr>@foreach ($row as $cell) <td>{{ $cell }}</td> @endforeach</tr> @endforeach
        </tbody>
    </table>
</body>
</html>
