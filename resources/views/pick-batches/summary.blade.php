<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Picking Summary — Batch #{{ $pickBatch->id }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11pt; margin: 1cm; color: #111; }
        h1 { font-size: 14pt; margin: 0 0 4px; }
        .meta { font-size: 10pt; color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 2px solid #111; padding: 4px 8px; font-size: 10pt; }
        td { padding: 4px 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
        tr:last-child td { border-bottom: none; }
        .totes { font-weight: bold; }
        .no-bin { color: #888; font-style: italic; }
        .actions { margin-bottom: 16px; }
        @media print {
            .actions { display: none; }
            body { margin: 0.5cm; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button onclick="window.print()">Print</button>
        <a href="javascript:history.back()">Back</a>
    </div>

    <h1>Picking Summary — Batch #{{ $pickBatch->id }}</h1>
    <div class="meta">
        {{ $pickBatch->created_at->format('M j, Y g:i A') }} &middot;
        {{ $pickBatch->total_shipments }} {{ Str::plural('order', $pickBatch->total_shipments) }} &middot;
        {{ count($rows) }} {{ Str::plural('SKU', count($rows)) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Bin</th>
                <th>SKU</th>
                <th>Product</th>
                <th>Qty</th>
                <th>Totes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
            <tr>
                <td>
                    @if (filled($row['bin_location']))
                        {{ $row['bin_location'] }}
                    @else
                        <span class="no-bin">—</span>
                    @endif
                </td>
                <td>{{ $row['sku'] }}</td>
                <td>{{ $row['product_name'] }}</td>
                <td>{{ $row['quantity'] }}</td>
                <td class="totes">{{ implode(', ', $row['tote_codes']) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
