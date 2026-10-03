<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $p['report']['title'] }}</title>
<style>
    @page { margin: 28px 24px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #1f2933; }
    h1 { font-size: 15px; margin: 0 0 2px 0; }
    .meta { color: #52606d; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #e4e7eb; text-align: left; font-weight: bold; padding: 4px 5px; border: 1px solid #cbd2d9; }
    td { padding: 3px 5px; border: 1px solid #e4e7eb; vertical-align: top; }
    .r { text-align: right; }
    .summary { margin-top: 12px; width: auto; }
    .summary td { border: none; padding: 1px 12px 1px 0; }
    .note { color: #52606d; margin-top: 6px; }
</style>
</head>
<body>
<h1>{{ $p['report']['title'] }}</h1>
<div class="meta">
    {{ $p['farm']['name'] }} &middot; generated {{ $p['generated_at'] }} ({{ $p['timezone'] }})
    @foreach ($p['filters'] as $name => $value) &middot; {{ str_replace('_', ' ', $name) }}: {{ $value }} @endforeach
</div>
<table>
    <thead>
    <tr>
        @foreach ($p['columns'] as $c)
            <th class="{{ in_array($c['type'], $right, true) ? 'r' : '' }}">{{ $c['label'] }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($p['rows'] as $row)
        <tr>
            @foreach ($p['columns'] as $c)
                <td class="{{ in_array($c['type'], $right, true) ? 'r' : '' }}">{{ $row[$c['key']] ?? '' }}</td>
            @endforeach
        </tr>
    @empty
        <tr><td colspan="{{ count($p['columns']) }}">No data for this period.</td></tr>
    @endforelse
    </tbody>
</table>
@if ($summary)
    <table class="summary">
        @foreach ($summary as $label => $value)
            <tr><td><strong>{{ $label }}</strong></td><td>{{ $value }}</td></tr>
        @endforeach
    </table>
@endif
@foreach ($p['notes'] as $note)
    <div class="note">{{ $note }}</div>
@endforeach
</body>
</html>
