<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: 4in 6in; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: sans-serif; font-size: 8pt; margin: 0; color: #111; }

        .slip {
            width: 4in;
            padding: 0.15in;
            page-break-after: always;
        }
        .slip:last-child { page-break-after: avoid; }

        /* Row 1: Logo | Return address */
        .row-header {
            display: flex;
            gap: 0.1in;
            padding-bottom: 0.08in;
            border-bottom: 1px solid #ccc;
            margin-bottom: 0.08in;
            min-height: 0.65in;
            align-items: flex-start;
        }
        .logo-cell { flex: 0 0 50%; }
        .logo-cell img { max-width: 100%; max-height: 0.55in; object-fit: contain; object-position: left top; }
        .return-address-cell { flex: 1; font-size: 7pt; line-height: 1.35; text-align: right; color: #333; }
        .return-address-cell .co { font-weight: bold; color: #111; }

        /* Row 2: Tote | Order + barcode */
        .row-tote {
            display: flex;
            gap: 0.1in;
            padding-bottom: 0.08in;
            border-bottom: 1px solid #ccc;
            margin-bottom: 0.08in;
            align-items: center;
        }
        .tote-cell { flex: 0 0 35%; }
        .tote-label { font-size: 6.5pt; color: #666; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
        .tote-code {
            font-size: 20pt;
            font-weight: bold;
            border: 2px solid #111;
            display: inline-block;
            padding: 1px 8px;
            line-height: 1.2;
        }
        .order-cell { flex: 1; text-align: right; }
        .barcode-wrap svg { display: block; width: 100%; height: 30px; margin-left: auto; }
        .order-ref { font-family: monospace; font-size: 7pt; color: #555; margin-top: 2px; }
        .order-ref .scan-code { color: #888; margin-left: 4px; }

        /* Row 3: Ship-to | Order summary placeholder */
        .row-recipient {
            display: flex;
            gap: 0.1in;
            padding-bottom: 0.08in;
            border-bottom: 1px solid #ccc;
            margin-bottom: 0.08in;
        }
        .ship-to-cell { flex: 1; line-height: 1.45; }
        .ship-to-cell .name { font-size: 9pt; font-weight: bold; }
        .ship-to-cell .addr { font-size: 8pt; }
        .order-summary-cell { flex: 0 0 38%; border: 1px solid #e0e0e0; min-height: 0.85in; background: #fafafa; }

        /* Row 4: Items table */
        .items-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
        .items-table th { text-align: left; border-bottom: 1.5px solid #111; padding: 3px 4px; font-size: 7.5pt; }
        .items-table td { padding: 3px 4px; border-bottom: 1px solid #e8e8e8; vertical-align: top; }
        .items-table tr:last-child td { border-bottom: none; }

        /* Row 5: Footer (custom message / return instructions) */
        .row-footer { margin-top: 0.08in; padding-top: 0.06in; border-top: 1px solid #ccc; font-size: 7pt; color: #444; line-height: 1.4; }
        .row-footer .footer-label { font-weight: bold; color: #111; margin-bottom: 1px; }
        .row-footer .footer-block + .footer-block { margin-top: 0.05in; }

        .actions { padding: 0.5cm; }
        @media print { .actions { display: none; } }
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

    @foreach ($slips as $slip)
    @php
        $client = $slip->client;
        $shipment = $slip->shipment;
    @endphp
    <div class="slip">
        {{-- Row 1: Logo | Return address --}}
        <div class="row-header">
            <div class="logo-cell">
                @if (!empty($slip->logoDataUri))
                    <img src="{{ $slip->logoDataUri }}" alt="Logo">
                @endif
            </div>
            <div class="return-address-cell">
                @if ($client?->hasReturnAddress())
                    @php $displayName = $client->return_company ?: ($client->company_name ?: $client->name); @endphp
                    <span class="co">{{ $displayName }}</span><br>
                    @if ($client->return_name)
                        {{ $client->return_name }}<br>
                    @endif
                    {{ $client->return_address1 }}<br>
                    @if ($client->return_address2)
                        {{ $client->return_address2 }}<br>
                    @endif
                    {{ $client->return_city }}, {{ $client->return_state_or_province }} {{ $client->return_postal_code }}
                @elseif ($client?->company_name || $client?->name)
                    <span class="co">{{ $client->company_name ?: $client->name }}</span>
                @elseif ($defaultLocation)
                    @if ($defaultLocation->company)
                        <span class="co">{{ $defaultLocation->company }}</span><br>
                    @elseif ($defaultLocation->first_name || $defaultLocation->last_name)
                        <span class="co">{{ trim(($defaultLocation->first_name ?? '').' '.($defaultLocation->last_name ?? '')) }}</span><br>
                    @endif
                    {{ $defaultLocation->address1 }}<br>
                    @if ($defaultLocation->address2)
                        {{ $defaultLocation->address2 }}<br>
                    @endif
                    {{ $defaultLocation->city }}, {{ $defaultLocation->state_or_province }} {{ $defaultLocation->postal_code }}
                @endif
            </div>
        </div>

        {{-- Row 2: Tote | Order + barcode. The tote only exists for a slip printed from a pick batch. --}}
        <div class="row-tote">
            @if ($slip->toteCode !== null)
            <div class="tote-cell">
                <div class="tote-label">Tote</div>
                <div class="tote-code">{{ $slip->toteCode }}</div>
            </div>
            @endif
            <div class="order-cell">
                {{-- The PolyBag code, not the order reference: a reference is only unique within one connection (ADR-0007). --}}
                <div class="barcode-wrap">
                    {!! $generator->getBarcode($slip->scanCode, \Picqer\Barcode\BarcodeGeneratorSVG::TYPE_CODE_128, 2, 30) !!}
                </div>
                <div class="order-ref">{{ $shipment->shipment_reference }} <span class="scan-code">{{ $slip->scanCode }}</span></div>
            </div>
        </div>

        {{-- Row 3: Ship-to address | Order summary placeholder --}}
        <div class="row-recipient">
            <div class="ship-to-cell">
                <div class="name">{{ trim(($shipment->first_name ?? '').' '.($shipment->last_name ?? '')) }}</div>
                @if ($shipment->company)
                    <div class="addr">{{ $shipment->company }}</div>
                @endif
                <div class="addr">{{ $shipment->address1 }}</div>
                @if ($shipment->address2)
                    <div class="addr">{{ $shipment->address2 }}</div>
                @endif
                <div class="addr">{{ $shipment->city }}, {{ $shipment->state_or_province }} {{ $shipment->postal_code }}</div>
            </div>
            <div class="order-summary-cell"></div>
        </div>

        {{-- Row 4: Items table --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Qty</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipment->shipmentItems as $item)
                <tr>
                    <td>{{ $item->product?->sku ?? '—' }}</td>
                    <td>{{ $item->product?->name ?? '—' }}</td>
                    <td>{{ $item->quantity }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Row 5: Custom message / return instructions --}}
        @if ($client?->custom_message || $client?->return_instructions)
        <div class="row-footer">
            @if ($client->custom_message)
            <div class="footer-block">{{ $client->custom_message }}</div>
            @endif
            @if ($client->return_instructions)
            <div class="footer-block">
                <div class="footer-label">Returns</div>
                {{ $client->return_instructions }}
            </div>
            @endif
        </div>
        @endif
    </div>
    @endforeach
</body>
</html>
