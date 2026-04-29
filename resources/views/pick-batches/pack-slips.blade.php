<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pack Slips — Batch #{{ $pickBatch->id }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11pt; margin: 0; color: #111; }
        .slip { padding: 1cm; page-break-after: always; }
        .slip:last-child { page-break-after: avoid; }
        .tote { font-size: 22pt; font-weight: bold; border: 3px solid #111; display: inline-block; padding: 4px 16px; margin-bottom: 12px; }
        .meta { font-size: 10pt; color: #555; margin-bottom: 12px; }
        .address { margin-bottom: 16px; line-height: 1.5; }
        .barcode-wrap { margin-bottom: 12px; }
        .barcode-wrap svg { display: block; max-width: 240px; height: 48px; }
        .barcode-ref { font-family: monospace; font-size: 9pt; color: #555; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; border-bottom: 2px solid #111; padding: 4px 8px; font-size: 10pt; }
        td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
        .actions { padding: 1cm; }
        @media print { .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions">
        <button onclick="window.print()">Print</button>
        <a href="javascript:history.back()">Back</a>
    </div>

    @foreach ($pivotRows as $pivot)
    <div class="slip">
        <div class="tote">{{ $pivot->tote_code ?? '—' }}</div>
        <div class="meta">
            Batch #{{ $pickBatch->id }} &middot;
            {{ $pivot->shipment?->shipment_reference }}
            @if ($pivot->shipment?->channel)
                &middot; {{ $pivot->shipment->channel->name }}
            @endif
        </div>

        @if ($pivot->shipment?->shipment_reference)
        <div class="barcode-wrap">
            <svg data-barcode="{{ $pivot->shipment->shipment_reference }}"></svg>
            <div class="barcode-ref">{{ $pivot->shipment->shipment_reference }}</div>
        </div>
        @endif

        <div class="address">
            <strong>{{ trim(($pivot->shipment?->first_name ?? '').' '.($pivot->shipment?->last_name ?? '')) }}</strong><br>
            @if ($pivot->shipment?->company)
                {{ $pivot->shipment->company }}<br>
            @endif
            {{ $pivot->shipment?->address1 }}<br>
            @if ($pivot->shipment?->address2)
                {{ $pivot->shipment->address2 }}<br>
            @endif
            {{ $pivot->shipment?->city }}, {{ $pivot->shipment?->state_or_province }} {{ $pivot->shipment?->postal_code }}<br>
            {{ $pivot->shipment?->country }}
        </div>

        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pivot->shipment?->shipmentItems ?? [] as $item)
                <tr>
                    <td>{{ $item->product?->sku ?? '—' }}</td>
                    <td>{{ $item->product?->name ?? '—' }}</td>
                    <td>{{ $item->quantity }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endforeach

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('svg[data-barcode]').forEach(function (svg) {
                JsBarcode(svg, svg.getAttribute('data-barcode'), {
                    format: 'CODE128',
                    width: 2,
                    height: 40,
                    displayValue: false,
                    margin: 0,
                });
            });
        });
    </script>
</body>
</html>
