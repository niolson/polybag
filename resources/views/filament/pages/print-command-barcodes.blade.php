<x-filament-panels::page>
    <div class="mb-4 print:hidden">
        <x-filament::button onclick="window.print()" icon="heroicon-o-printer" color="success">
            Print Command Barcodes
        </x-filament::button>
        <span class="ml-4 text-sm text-gray-500">{{ count($commands) }} commands</span>
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
                padding: 0.5in !important;
                margin: 0 !important;
            }

            body, html {
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* Ensure colors print */
            .command-item {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .barcode-svg {
                width: 100% !important;
                height: 60px !important;
            }
        }

        .print-container {
            background: white;
            padding: 1rem;
        }

        .command-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            page-break-inside: auto;
        }

        .command-item {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            page-break-inside: avoid;
            background: white;
        }

        .command-code {
            font-size: 14px;
            color: #6b7280;
            font-family: monospace;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .barcode-svg {
            width: 100%;
            height: 60px;
        }

        .command-label {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-top: 8px;
            line-height: 1.2;
        }

        .command-description {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }
    </style>

    <div class="print-container">
        <h2 class="text-lg font-bold mb-4 text-center">Pack Station Command Barcodes</h2>
        <div class="command-grid">
            @foreach($commands as $command)
                <div class="command-item">
                    <div class="command-code">{{ $command['code'] }}</div>
                    <svg class="barcode-svg" data-code="{{ $command['code'] }}"></svg>
                    <div class="command-label">{{ $command['label'] }}</div>
                    <div class="command-description">{{ $command['description'] }}</div>
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
                            width: 3,
                            height: 50,
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
