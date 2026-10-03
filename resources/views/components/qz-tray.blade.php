<!-- QZ Tray Status Banner -->
<div id="qz-status" class="mb-4 hidden items-center justify-between gap-4 rounded-lg border px-4 py-3 text-sm">
    <span id="qz-status-text">Connecting to the label printer...</span>
    <button
        id="qz-status-button"
        type="button"
        class="hidden shrink-0 font-semibold underline underline-offset-4"
    ></button>
    <a
        id="qz-status-action"
        href="{{ \App\Filament\Pages\DeviceSettings::getUrl() }}"
        class="hidden shrink-0 font-semibold underline underline-offset-4"
    ></a>
</div>

<x-qz-tray-script />

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const statusBanner = document.getElementById('qz-status');
        const statusText = document.getElementById('qz-status-text');
        const statusAction = document.getElementById('qz-status-action');
        const statusButton = document.getElementById('qz-status-button');

        // Show status during initial connection. `button` is an in-page action,
        // `{ label, onClick }`, shown instead of the Device Settings link.
        function showStatus(message, type = 'info', actionLabel = null, autoHide = type === 'success', button = null) {
            statusBanner.classList.remove(
                'hidden', 'border-green-200', 'border-red-200', 'border-amber-200', 'border-gray-200',
                'bg-green-50', 'bg-red-50', 'bg-amber-50', 'bg-gray-50',
                'text-green-800', 'text-red-800', 'text-amber-800', 'text-gray-700',
                'dark:border-green-900', 'dark:border-red-900', 'dark:border-amber-900', 'dark:border-gray-700',
                'dark:bg-green-950', 'dark:bg-red-950', 'dark:bg-amber-950', 'dark:bg-gray-800',
                'dark:text-green-200', 'dark:text-red-200', 'dark:text-amber-200', 'dark:text-gray-200',
            );

            const colors = {
                'success': 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
                'error': 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
                'warning': 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
                'info': 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200'
            };

            statusBanner.classList.add('flex', ...colors[type].split(' '));
            statusText.textContent = message;
            statusAction.textContent = actionLabel || '';
            statusAction.classList.toggle('hidden', !actionLabel);
            statusButton.textContent = button?.label || '';
            statusButton.onclick = button?.onClick || null;
            statusButton.classList.toggle('hidden', !button);

            // Auto-hide success messages
            if (autoHide) {
                setTimeout(hideStatus, 3000);
            }
        }

        // `flex` and `hidden` both set display, so swap one for the other rather
        // than adding `hidden` on top.
        function hideStatus() {
            statusBanner.classList.remove('flex');
            statusBanner.classList.add('hidden');
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

        // Printer names come from this browser's Device Settings — see
        // the printer-settings-script component for which label printer a format goes to.
        function getReportPrinter() {
            return PrinterSettings.documentPrinter();
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

                if (!PrinterSettings.hasLabelPrinter()) {
                    // Always warn if no printer configured
                    showStatus('No label printer is selected for this computer.', 'warning', 'Choose printer');
                } else if (showStatusOnSuccess) {
                    // Only show success message when explicitly requested (e.g., during print reconnect)
                    const printer = PrinterSettings.labelPrinterFor(PrinterSettings.labelFormat());
                    showStatus(`Connected - Printer: ${printer}`, 'success');
                }
                // Otherwise, silently connected - no banner needed

                return true;
            } catch (error) {
                console.error('QZ Tray connection error:', error);

                if (error.message && error.message.includes('Unable to connect')) {
                    showStatus('The label printing app is not running. Open QZ Tray on this computer, then try again.', 'error');
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
            // Routed by what the label is, not by what this workstation prefers to
            // buy: a ZPL label needs the raw printer, anything else the image one.
            // A workstation with both can reprint labels bought either way.
            const printer = PrinterSettings.labelPrinterFor(format);

            if (!printer) {
                const message = format === 'zpl'
                    ? 'This label is ZPL but no raw label printer is configured. Go to Device Settings.'
                    : 'This label is an image but no PDF/image label printer is configured. Go to Device Settings.';
                showStatus(message, 'error');
                throw new Error(message);
            }

            if (format === 'zpl') {
                const configDpi = PrinterSettings.labelDpi();

                if (dpi && dpi !== configDpi) {
                    showStatus(`This label was generated for ${dpi} DPI but your raw label printer is configured for ${configDpi} DPI. Go to Device Settings to change.`, 'error');
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
                    hideStatus();
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
                hideStatus();
            } catch (error) {
                console.error('Print error:', error);
                showStatus(`Print failed: ${error.message || 'Unknown error'}`, 'error');
                throw error;
            }
        }

        // Send a document (8.5x11) to the report printer via QZ Tray. Throws on any
        // failure; resolves once QZ reports the job sent to the printer.
        async function sendReport(base64Data, format = 'pdf') {
            const printer = getReportPrinter();

            if (!printer) {
                const error = new Error('No document printer configured. Go to Device Settings.');
                error.isConfiguration = true;
                throw error;
            }

            if (!qz.websocket.isActive()) {
                showStatus('Reconnecting to QZ Tray...', 'info');
                await initQZTray(true);
            }

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
        }

        // Print report (8.5x11) via QZ Tray.
        // Returns whether it printed. Reports rather than throws, because the
        // callers that print paperwork alongside a label must not lose the label
        // print to a failure on the paper half — but they do have to be able to
        // tell the difference, so the outcome cannot be silent either.
        async function printReport(base64Data, format = 'pdf') {
            try {
                showStatus('Printing document...', 'info');
                await sendReport(base64Data, format);
                hideStatus();

                return true;
            } catch (error) {
                console.error('Report print error:', error);
                showStatus(error.isConfiguration ? error.message : `Document print failed: ${error.message || 'Unknown error'}`, 'error');

                return false;
            }
        }

        // Print a customs document by what it is. PDF and raster forms are paper
        // and go to the report printer; a ZPL one is label stock — generated by
        // the same purchase at the same DPI as the label — and goes to the label
        // printer raw, since the report path is pixel-only and would hand QZ ZPL
        // bytes declared as a PDF. Same contract as printReport: returns whether
        // it printed, never throws.
        async function printCustomsForm(base64Data, format = 'pdf', dpi = null) {
            if (format !== 'zpl') {
                return printReport(base64Data, format);
            }

            try {
                await printLabel(base64Data, 'portrait', 'zpl', dpi);

                return true;
            } catch (error) {
                // printLabel has already shown what went wrong.
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

        // Redeem a pack slip receipt: the slips it names are recorded as printed.
        // Returns whether the server recorded it.
        async function acknowledgePackSlips(receipt) {
            try {
                const response = await fetch(@json(route('pack-slips.printed')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ receipt }),
                    keepalive: true,
                });

                if (!response.ok) {
                    console.error(`Failed to record pack slip print: HTTP ${response.status}`);
                    return false;
                }

                return true;
            } catch (error) {
                console.error('Failed to record pack slip print:', error);
                return false;
            }
        }

        const slipCount = (n) => n === 1 ? '1 pack slip' : `${n} pack slips`;

        // Print a run of pack slips, sent by the server as consecutive jobs of
        // bounded size, each with its own receipt. A job is recorded only once QZ
        // reports it sent; the run stops at the first job that fails, and the jobs
        // before it stay recorded. "Sent" is as far as QZ can see: a printer that
        // jams afterwards is recovered by reprinting.
        async function printPackSlipJobs(jobs) {
            const total = jobs.reduce((sum, job) => sum + job.count, 0);
            const unrecorded = [];
            let sent = 0;
            let failure = null;

            for (const job of jobs) {
                showStatus(jobs.length > 1
                    ? `Sending pack slips ${sent + 1}–${sent + job.count} of ${total} to the printer...`
                    : `Sending ${slipCount(job.count)} to the printer...`, 'info');

                try {
                    await sendReport(job.data, 'pdf');
                } catch (error) {
                    console.error('Pack slip print error:', error);
                    failure = error.message || 'Unknown error';
                    break;
                }

                sent += job.count;

                if (!await acknowledgePackSlips(job.receipt)) {
                    unrecorded.push(job);
                }
            }

            const unrecordedCount = unrecorded.reduce((sum, job) => sum + job.count, 0);
            const recordedCount = sent - unrecordedCount;
            const parts = [];
            let type;

            if (failure && sent === 0) {
                parts.push(`Pack slips did not print: ${failure}. Nothing was recorded as printed.`);
                type = 'error';
            } else if (failure) {
                parts.push(`Pack slips stopped: ${failure}. ${sent} of ${total} were sent to the printer; the other ${total - sent} were not sent or recorded.`);
                type = 'error';
            } else {
                parts.push(`Sent ${slipCount(total)} to the printer. If no paper came out, reprint.`);
                type = 'success';
            }

            if (unrecordedCount > 0) {
                parts.push(`${slipCount(unrecordedCount)} sent but not recorded as printed.`);
                type = type === 'error' ? 'error' : 'warning';
            }

            const markPrinted = unrecorded.length === 0 ? null : {
                label: 'Mark as printed',
                onClick: async () => {
                    const stillUnrecorded = [];

                    for (const job of unrecorded) {
                        if (!await acknowledgePackSlips(job.receipt)) {
                            stillUnrecorded.push(job);
                        }
                    }

                    if (stillUnrecorded.length === 0) {
                        showStatus(`Recorded ${slipCount(unrecordedCount)} as printed.`, 'success');
                    } else {
                        unrecorded.splice(0, unrecorded.length, ...stillUnrecorded);
                        showStatus('Could not record the pack slips as printed. Try again, or use Mark as printed on the viewed slips.', 'error', null, false, markPrinted);
                    }

                    Livewire.dispatch('pack-slips-printed');
                },
            };

            // Kept on screen: the reprint hint is the point of the message.
            showStatus(parts.join(' '), type, null, false, markPrinted);

            if (recordedCount > 0) {
                Livewire.dispatch('pack-slips-printed');
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
                if (event.customsForm && !await printCustomsForm(event.customsForm, event.customsFormFormat || 'pdf', event.dpi || null)) {
                    warn('The label printed but its customs form did not. Reprint the package before the parcel leaves.');
                }

                if (event.redirectTo) {
                    window.location.href = event.redirectTo;
                }
            });

            Livewire.on('print-report', (event) => {
                printReport(event.data);
            });

            Livewire.on('print-pack-slips', (event) => {
                printPackSlipJobs(event.jobs || []);
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
                        if (item.customsForm && !await printCustomsForm(item.customsForm, item.customsFormFormat || 'pdf', item.dpi || null)) {
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
