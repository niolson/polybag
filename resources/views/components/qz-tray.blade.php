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

        // Queue a message to appear on the *next* page. The ship flow redirects as
        // soon as a label prints, which would wipe a banner shown here before the
        // operator could read it.
        function showStatusAfterNavigation(message, type = 'info') {
            sessionStorage.setItem('qzPendingStatus', JSON.stringify({ message, type, at: Date.now() }));
        }

        const PENDING_STATUS_TTL_MS = 60000;

        function flushPendingStatus() {
            const stored = sessionStorage.getItem('qzPendingStatus');

            if (!stored) {
                return;
            }

            sessionStorage.removeItem('qzPendingStatus');

            try {
                const pending = JSON.parse(stored);

                // Only the very next page should show it. If the redirect landed
                // somewhere without this component, the message must not resurface
                // later attached to unrelated work.
                if (Date.now() - (pending.at ?? 0) > PENDING_STATUS_TTL_MS) {
                    return;
                }

                showStatus(pending.message, pending.type || 'warning');
            } catch (error) {
                console.error('Could not restore carried-over status:', error);
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

        // Print label via QZ Tray.
        // Throws on any failure so callers can tell a real print from a no-op —
        // the label printed flag on the package depends on this.
        async function printLabel(base64Data, orientation = 'portrait', format = 'pdf', dpi = null) {
            const printer = getLabelPrinter();

            if (!printer) {
                showStatus('No label printer configured. Go to Device Settings.', 'error');
                throw new Error('No label printer configured');
            }

            // Block reprinting ZPL labels when printer isn't configured for raw ZPL
            // PDF/image labels can always print via the pixel path on any printer
            if (format === 'zpl') {
                const configFormat = localStorage.getItem('labelFormat') || 'pdf';
                const configDpi = parseInt(localStorage.getItem('labelDpi') || '203');

                if (configFormat !== 'zpl') {
                    showStatus('This label is ZPL but your printer is configured for PDF. Go to Device Settings to change.', 'error');
                    throw new Error('Label is ZPL but printer is configured for PDF');
                }
                if (dpi && dpi !== configDpi) {
                    showStatus(`This label was generated for ${dpi} DPI but your printer is configured for ${configDpi} DPI. Go to Device Settings to change.`, 'error');
                    throw new Error(`Label DPI ${dpi} does not match printer DPI ${configDpi}`);
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
                throw error;
            }
        }

        // Print report (8.5x11) via QZ Tray.
        // Returns whether it printed. Reports rather than throws, because the
        // callers that print paperwork alongside a label must not lose the label
        // print to a failure on the paper half — but they do have to be able to
        // tell the difference, so the outcome cannot be silent either.
        async function printReport(base64Data, format = 'pdf') {
            const printer = getReportPrinter();

            if (!printer) {
                showStatus('No report printer configured. Go to Device Settings.', 'error');
                return false;
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

                const isImageFormat = format === 'image' || format === 'png' || format === 'gif';

                const data = [{
                    type: 'pixel',
                    format: isImageFormat ? 'image' : 'pdf',
                    flavor: 'base64',
                    data: base64Data
                }];

                await qz.print(config, data);
                statusBanner.classList.add('hidden');

                return true;
            } catch (error) {
                console.error('Report print error:', error);
                showStatus(`Report print failed: ${error.message || 'Unknown error'}`, 'error');

                return false;
            }
        }

        // Tell the server a label actually reached the printer. Best-effort: a failed
        // ack must never surface as a print failure, since the label did print — but
        // it must not pass silently either, or the package looks unprinted forever.
        // Returns whether the print was recorded.
        async function acknowledgePrint(packageId) {
            if (!packageId) {
                return true;
            }

            try {
                const response = await fetch(`/labels/${packageId}/printed`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    keepalive: true,
                });

                // fetch only rejects on network failure — 419/429/500 all resolve.
                if (!response.ok) {
                    console.error(`Failed to record label print: HTTP ${response.status}`);
                    return false;
                }

                return true;
            } catch (error) {
                console.error('Failed to record label print:', error);
                return false;
            }
        }

        // Listen for print events from Livewire
        document.addEventListener('livewire:init', () => {
            Livewire.on('print-label', async (event) => {
                // Survive the redirect below, which the ship flow always sets.
                const warn = (message) => event.redirectTo
                    ? showStatusAfterNavigation(message, 'warning')
                    : showStatus(message, 'warning');

                try {
                    if (event.orientation === 'report') {
                        // A label on 8.5x11 stock, which is the report printer's path already.
                        if (!await printReport(event.label, event.format || 'pdf')) {
                            return;
                        }
                    } else {
                        await printLabel(event.label, event.orientation || 'portrait', event.format || 'pdf', event.dpi || null);

                        if (!await acknowledgePrint(event.packageId)) {
                            warn('Label printed, but recording it failed. It may still show as unprinted.');
                        }
                    }
                } catch (error) {
                    // printLabel/printReport already showed the error banner. Stay on the
                    // page so the operator sees it instead of following redirectTo.
                    return;
                }

                // The customs form follows the label it belongs to, onto paper. A
                // failure here is deliberately not a failure of the print: the
                // postage is bought and the label is out, so the redirect still
                // happens and the warning travels with it. printReport has already
                // shown what went wrong on this page.
                if (event.customsForm && !await printReport(event.customsForm)) {
                    warn('The label printed but its customs form did not. Reprint the package before the parcel leaves.');
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
                let unrecorded = 0;
                let customsFailed = 0;

                for (const item of labels) {
                    try {
                        await printLabel(item.label, item.orientation || 'portrait', item.format || 'pdf', item.dpi || null);
                        printed++;

                        if (!await acknowledgePrint(item.packageId)) {
                            unrecorded++;
                        }

                        // Interleaved rather than collected for the end of the run, so
                        // each parcel's paperwork comes off the printer beside its own
                        // label. Counted apart from `failed`: this label did print, and
                        // an operator reading the summary has to be able to tell the two
                        // apart to know what to do about it.
                        if (item.customsForm && !await printReport(item.customsForm)) {
                            customsFailed++;
                        }
                    } catch (error) {
                        console.error('Batch print error:', error);
                        failed++;
                    }
                    showStatus(`Printed ${printed}/${labels.length} labels...${failed > 0 ? ` (${failed} failed)` : ''}`, 'info');
                }

                let msg = failed > 0
                    ? `Printed ${printed}/${labels.length} labels (${failed} failed)`
                    : `Printed all ${printed} labels`;

                // These labels did print — they just may still show as unprinted.
                if (unrecorded > 0) {
                    msg += `. ${unrecorded} could not be recorded as printed`;
                }

                if (customsFailed > 0) {
                    msg += `. ${customsFailed} customs form${customsFailed === 1 ? '' : 's'} did not print`;
                }

                showStatus(msg, (failed > 0 || unrecorded > 0 || customsFailed > 0) ? 'warning' : 'success');

                // Let the page pick up the printed counts recorded during the loop.
                Livewire.dispatch('batch-print-finished');
            });
        });

        // Initialize on page load. The carried-over status is shown last so the
        // connection banner cannot bury it.
        initQZTray().finally(flushPendingStatus);
    });
</script>
