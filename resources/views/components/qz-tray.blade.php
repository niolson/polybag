<!-- QZ Tray Status Banner -->
<div id="qz-status" class="mb-4 p-3 rounded-lg bg-gray-100 dark:bg-gray-800 text-sm hidden">
    <span id="qz-status-text">Connecting to QZ Tray...</span>
</div>

<!-- QZ Tray Library -->
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const statusBanner = document.getElementById('qz-status');
        const statusText = document.getElementById('qz-status-text');

        // Set up QZ Tray certificate authentication
        function setupQzSecurity() {
            if (typeof qz === 'undefined') return;

            qz.security.setCertificatePromise(function(resolve, reject) {
                fetch('/qz-certificate.pem')
                    .then(response => response.ok ? response.text() : reject(response.statusText))
                    .then(resolve)
                    .catch(reject);
            });

            qz.security.setSignatureAlgorithm('SHA512');
            qz.security.setSignaturePromise(function(toSign) {
                return function(resolve, reject) {
                    fetch('/qz/sign', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                        },
                        body: JSON.stringify({ request: toSign })
                    })
                    .then(response => response.ok ? response.text() : reject(response.statusText))
                    .then(resolve)
                    .catch(reject);
                };
            });
        }

        // Show status during initial connection
        function showStatus(message, type = 'info') {
            statusBanner.classList.remove('hidden', 'bg-green-100', 'bg-red-100', 'bg-yellow-100', 'bg-gray-100',
                'dark:bg-green-900', 'dark:bg-red-900', 'dark:bg-yellow-900', 'dark:bg-gray-800');

            const colors = {
                'success': 'bg-green-100 dark:bg-green-900',
                'error': 'bg-red-100 dark:bg-red-900',
                'warning': 'bg-yellow-100 dark:bg-yellow-900',
                'info': 'bg-gray-100 dark:bg-gray-800'
            };

            statusBanner.classList.add(...colors[type].split(' '));
            statusText.textContent = message;

            // Auto-hide success messages
            if (type === 'success') {
                setTimeout(() => statusBanner.classList.add('hidden'), 3000);
            }
        }

        // Get printer names from localStorage
        function getLabelPrinter() {
            return localStorage.getItem('labelPrinter');
        }

        function getReportPrinter() {
            return localStorage.getItem('reportPrinter');
        }

        // Initialize QZ Tray connection
        // showStatusOnSuccess: false for initial page load, true for reconnects during printing
        async function initQZTray(showStatusOnSuccess = false) {
            if (typeof qz === 'undefined') {
                showStatus('QZ Tray library failed to load', 'error');
                return false;
            }

            try {
                // Set up certificate authentication
                setupQzSecurity();

                if (!qz.websocket.isActive()) {
                    await qz.websocket.connect();
                }

                const printer = getLabelPrinter();
                if (!printer) {
                    // Always warn if no printer configured
                    showStatus('QZ Tray connected - No printer configured. Go to Device Settings.', 'warning');
                } else if (showStatusOnSuccess) {
                    // Only show success message when explicitly requested (e.g., during print reconnect)
                    showStatus(`Connected - Printer: ${printer}`, 'success');
                }
                // Otherwise, silently connected - no banner needed

                return true;
            } catch (error) {
                console.error('QZ Tray connection error:', error);

                if (error.message && error.message.includes('Unable to connect')) {
                    showStatus('QZ Tray not running. Please start QZ Tray.', 'error');
                } else {
                    showStatus(`QZ Tray error: ${error.message || 'Connection failed'}`, 'error');
                }

                return false;
            }
        }

        // Print label via QZ Tray
        async function printLabel(base64Data, orientation = 'portrait') {
            const printer = getLabelPrinter();

            if (!printer) {
                showStatus('No label printer configured. Go to Device Settings.', 'error');
                return;
            }

            try {
                if (!qz.websocket.isActive()) {
                    showStatus('Reconnecting to QZ Tray...', 'info');
                    await initQZTray(true);
                }

                showStatus('Printing label...', 'info');

                // Label is always 4x6 on thermal printer
                // Add small margins to account for printer centering drift
                const config = qz.configs.create(printer, {
                    size: { width: 4, height: 6 },
                    units: 'in',
                    margins: { top: 0.05, right: 0.05, bottom: 0.05, left: 0.05 },
                    scaleContent: true
                });

                // For landscape PDFs, rotate content 90 degrees to fit
                const data = [{
                    type: 'pixel',
                    format: 'pdf',
                    flavor: 'base64',
                    data: base64Data,
                    options: orientation === 'landscape' ? { rotation: 90 } : {}
                }];

                await qz.print(config, data);
                // Success is shown via Filament notification, no need for banner
                statusBanner.classList.add('hidden');
            } catch (error) {
                console.error('Print error:', error);
                showStatus(`Print failed: ${error.message || 'Unknown error'}`, 'error');
            }
        }

        // Print report (8.5x11) via QZ Tray
        async function printReport(base64Data) {
            const printer = getReportPrinter();

            if (!printer) {
                showStatus('No report printer configured. Go to Device Settings.', 'error');
                return;
            }

            try {
                if (!qz.websocket.isActive()) {
                    showStatus('Reconnecting to QZ Tray...', 'info');
                    await initQZTray(true);
                }

                showStatus('Printing report...', 'info');

                const config = qz.configs.create(printer, {
                    size: { width: 8.5, height: 11 },
                    units: 'in',
                    scaleContent: true
                });

                const data = [{
                    type: 'pixel',
                    format: 'pdf',
                    flavor: 'base64',
                    data: base64Data
                }];

                await qz.print(config, data);
                statusBanner.classList.add('hidden');
            } catch (error) {
                console.error('Report print error:', error);
                showStatus(`Report print failed: ${error.message || 'Unknown error'}`, 'error');
            }
        }

        // Listen for print events from Livewire
        document.addEventListener('livewire:init', () => {
            Livewire.on('print-label', (event) => {
                printLabel(event.label, event.orientation || 'portrait');
            });

            Livewire.on('print-report', (event) => {
                printReport(event.data);
            });
        });

        // Initialize on page load
        initQZTray();
    });
</script>
