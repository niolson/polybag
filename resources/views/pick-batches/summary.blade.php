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
        .orders { font-size: 10pt; }
        .each { font-weight: normal; color: #555; }
        .no-bin { color: #888; font-style: italic; }
        .actions { margin-bottom: 16px; }
        @media print {
            .actions { display: none; }
            body { margin: 0.5cm; }
        }
    </style>
</head>
<body>
    @if(request()->boolean('print'))
    <script>window.addEventListener('load', () => window.print());</script>
    @endif
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
                @if ($columns['bin'])
                <th>Bin</th>
                @endif
                @if ($columns['sku'])
                <th>SKU</th>
                @endif
                <th>Product</th>
                @if ($columns['barcode'])
                <th>Barcode</th>
                @endif
                <th>Qty</th>
                <th>{{ $columns['totes'] ? 'Totes' : 'Orders' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
            <tr>
                @if ($columns['bin'])
                <td>
                    @if (filled($row['bin_location']))
                        {{ $row['bin_location'] }}
                    @else
                        <span class="no-bin">—</span>
                    @endif
                </td>
                @endif
                @if ($columns['sku'])
                <td>{{ $row['sku'] }}</td>
                @endif
                <td>{{ $row['product_name'] }}</td>
                @if ($columns['barcode'])
                <td>{{ $row['barcode'] ?? '—' }}</td>
                @endif
                <td>{{ $row['quantity'] }}</td>
                @if ($columns['totes'])
                <td class="totes">
                    @foreach ($row['totes'] as $tote => $qty)
                        {{ $tote }}@if (count($row['totes']) > 1) <span class="each">×{{ $qty }}</span>@endif{{ !$loop->last ? ', ' : '' }}
                    @endforeach
                </td>
                @else
                <td class="orders">
                    @foreach ($row['orders'] as $order)
                        {{ $order['reference'] }}@if (count($row['orders']) > 1) <span class="each">×{{ $order['quantity'] }}</span>@endif{{ !$loop->last ? ', ' : '' }}
                    @endforeach
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
