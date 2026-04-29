<x-filament-panels::page>
    <div class="mb-4 print:hidden">
        <x-filament::button onclick="window.print()" icon="heroicon-o-printer" color="success">
            Print Barcodes
        </x-filament::button>
        <span class="ml-4 text-sm text-gray-500">{{ $boxSizes->count() }} box sizes</span>
    </div>

    <style>
        @media print {
            /* Hide everything by default */
            body * {
                visibility: hidden;
            }

            /* Show only the print container and its contents */
            .print-container, .print-container * {
                visibility: visible;
            }

            /* Position print container at top left */
            .print-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 0.25in !important;
                margin: 0 !important;
            }

            body, html {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Ensure colors print */
            .barcode-item {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .barcode-svg {
                width: 100% !important;
                height: 50px !important;
            }
        }

        .print-container {
            background: white;
            padding: 1rem;
        }

        .barcode-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            page-break-inside: auto;
        }

        .barcode-item {
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
            text-align: center;
            page-break-inside: avoid;
            background: white;
        }

        .barcode-code {
            font-size: 10px;
            color: #6b7280;
            font-family: monospace;
            margin-bottom: 4px;
        }

        .barcode-svg {
            width: 100%;
            height: 50px;
        }

        .barcode-label {
            font-size: 11px;
            font-weight: 600;
            color: #111827;
            margin-top: 4px;
            line-height: 1.2;
        }

        .barcode-dimensions {
            font-size: 9px;
            color: #9ca3af;
        }
    </style>

    <div class="print-container">
        <div class="barcode-grid">
            @foreach($boxSizes as $boxSize)
                <div class="barcode-item">
                    <div class="barcode-code">{{ $boxSize->code }}</div>
                    <svg class="barcode-svg" data-code="{{ $boxSize->code }}"></svg>
                    <div class="barcode-label">{{ $boxSize->label }}</div>
                    <div class="barcode-dimensions">{{ $boxSize->length }}" x {{ $boxSize->width }}" x {{ $boxSize->height }}"</div>
                </div>
            @endforeach
        </div>
    </div>

    @vite('resources/js/barcodes.js')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            generateBarcodes();
        });

        document.addEventListener('livewire:navigated', function() {
            generateBarcodes();
        });

        function generateBarcodes() {
            document.querySelectorAll('.barcode-svg[data-code]').forEach(function(svg) {
                const code = svg.getAttribute('data-code');
                if (code) {
                    try {
                        JsBarcode(svg, code, {
                            format: "CODE128",
                            width: 2,
                            height: 40,
                            displayValue: false,
                            margin: 0
                        });
                    } catch (e) {
                        console.error('Error generating barcode for:', code, e);
                    }
                }
            });
        }
    </script>
</x-filament-panels::page>
