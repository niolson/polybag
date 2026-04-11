<!-- QZ Tray Status Banner -->
<div id="qz-status" class="mb-4 p-3 rounded-lg bg-gray-100 dark:bg-gray-800 text-sm hidden">
    <span id="qz-status-text">Connecting to QZ Tray...</span>
</div>

<x-qz-tray-script />

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const statusBanner = document.getElementById('qz-status');
        const statusText = document.getElementById('qz-status-text');

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

                document.dispatchEvent(new CustomEvent('qz-tray:connected'));

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

        // Rotate a base64 image 90° clockwise onto a fixed 4x6 canvas (600 DPI)
        function rotateImage90(base64Data) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    // Rotate to natural portrait dimensions
                    const rot = document.createElement('canvas');
                    rot.width = img.height;
                    rot.height = img.width;
                    const rotCtx = rot.getContext('2d');
                    rotCtx.translate(rot.width / 2, rot.height / 2);
                    rotCtx.rotate(Math.PI / 2);
                    rotCtx.drawImage(img, -img.width / 2, -img.height / 2);

                    // Stretch onto 4x6 canvas with small top/left margins
                    const canvas = document.createElement('canvas');
                    canvas.width = 2400;  // 4in at 600 DPI
                    canvas.height = 3600; // 6in at 600 DPI
                    const ctx = canvas.getContext('2d');
                    ctx.imageSmoothingEnabled = false;
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, 2400, 3600);
                    const mt = 20; // ~0.03in top margin
                    const ml = 10; // ~0.02in left margin
                    ctx.drawImage(rot, ml, mt, 2400 - ml, 3600 - mt);
                    resolve(canvas.toDataURL('image/png').split(',')[1]);
                };
                img.src = 'data:image/gif;base64,' + base64Data;
            });
        }

        // Print label via QZ Tray
        async function printLabel(base64Data, orientation = 'portrait', format = 'pdf', dpi = null) {
            const printer = getLabelPrinter();

            if (!printer) {
                showStatus('No label printer configured. Go to Device Settings.', 'error');
                return;
            }

            // Block reprinting ZPL labels when printer isn't configured for raw ZPL
            // PDF/image labels can always print via the pixel path on any printer
            if (format === 'zpl') {
                const configFormat = localStorage.getItem('labelFormat') || 'pdf';
                const configDpi = parseInt(localStorage.getItem('labelDpi') || '203');

                if (configFormat !== 'zpl') {
                    showStatus('This label is ZPL but your printer is configured for PDF. Go to Device Settings to change.', 'error');
                    return;
                }
                if (dpi && dpi !== configDpi) {
                    showStatus(`This label was generated for ${dpi} DPI but your printer is configured for ${configDpi} DPI. Go to Device Settings to change.`, 'error');
                    return;
                }
            }

            try {
                if (!qz.websocket.isActive()) {
                    showStatus('Reconnecting to QZ Tray...', 'info');
                    await initQZTray(true);
                }

                showStatus('Printing label...', 'info');

                // ZPL: send as raw data directly to the printer
                if (format === 'zpl') {
                    const config = qz.configs.create(printer);
                    const data = [atob(base64Data)];
                    await qz.print(config, data);
                    statusBanner.classList.add('hidden');
                    return;
                }

                // Pixel path (PDF/image/PNG)
                // Normalize image-type formats (gif, png, etc.) to 'image' for QZ Tray
                const isImageFormat = format === 'image' || format === 'png' || format === 'gif';
                if (isImageFormat) format = 'image';

                // Rotate landscape images (e.g. UPS GIF) to portrait
                let printData = base64Data;
                if (format === 'image' && orientation === 'landscape') {
                    printData = await rotateImage90(base64Data);
                    format = 'image';
                    orientation = 'portrait';
                }

                // Label is always 4x6 on thermal printer
                const config = qz.configs.create(printer, {
                    size: { width: 4, height: 6 },
                    units: 'in',
                    margins: { top: 0.05, right: 0.05, bottom: 0.05, left: 0.05 },
                    scaleContent: true
                });

                const data = [{
                    type: 'pixel',
                    format: format === 'image' ? 'image' : 'pdf',
                    flavor: 'base64',
                    data: printData,
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
            Livewire.on('print-label', async (event) => {
                if (event.orientation === 'report') {
                    await printReport(event.label);
                } else {
                    await printLabel(event.label, event.orientation || 'portrait', event.format || 'pdf', event.dpi || null);
                }
                if (event.redirectTo) {
                    window.location.href = event.redirectTo;
                }
            });

            Livewire.on('print-report', (event) => {
                printReport(event.data);
            });

            Livewire.on('print-batch-labels', async (event) => {
                const labels = event.labels || [];
                if (labels.length === 0) return;

                showStatus(`Printing 0/${labels.length} labels...`, 'info');

                let printed = 0;
                let failed = 0;

                for (const item of labels) {
                    try {
                        await printLabel(item.label, item.orientation || 'portrait', item.format || 'pdf', item.dpi || null);
                        printed++;
                    } catch (error) {
                        console.error('Batch print error:', error);
                        failed++;
                    }
                    showStatus(`Printed ${printed}/${labels.length} labels...${failed > 0 ? ` (${failed} failed)` : ''}`, 'info');
                }

                const msg = failed > 0
                    ? `Printed ${printed}/${labels.length} labels (${failed} failed)`
                    : `Printed all ${printed} labels`;
                showStatus(msg, failed > 0 ? 'warning' : 'success');
            });
        });

        // Initialize on page load
        initQZTray();
    });
</script>
