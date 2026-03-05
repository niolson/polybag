<x-filament-panels::page>
    <!-- QZ Tray Status -->
    <div id="qz-status" class="mb-6 px-4 py-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
        <div class="flex items-center gap-3">
            <div id="qz-indicator" class="w-2.5 h-2.5 rounded-full bg-gray-400 ring-4 ring-gray-400/20"></div>
            <span id="qz-status-text" class="text-sm font-medium text-gray-600 dark:text-gray-300">Checking QZ Tray connection...</span>
        </div>
    </div>

    <!-- Printers Section -->
    <x-filament::section>
        <x-slot name="heading">Printers</x-slot>
        <x-slot name="description">Configure label and report printers for this workstation. Printers are discovered via QZ Tray.</x-slot>
        
        <x-filament::fieldset>
            <x-slot name="label">
                Label Printer (for 4x6 shipping labels)
            </x-slot>
            
            <x-filament::input.wrapper>
                <x-filament::input.select id="label-printer" label="Label Printer">
                    <option value="">Loading printers...</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-filament::fieldset>
        <x-filament::fieldset
            style="margin-top: 16px;">
            <x-slot name="label">
                Report Printer (for packing slips, customs forms)
            </x-slot>
            
            <x-filament::input.wrapper>
                <x-filament::input.select id="report-printer" label="Report Printer">
                    <option value="">Loading printers...</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-filament::fieldset>

        <x-filament::fieldset
            style="margin-top: 16px;">
            <x-slot name="label">
                Label Format
            </x-slot>

            <x-filament::input.wrapper>
                <x-filament::input.select id="label-format">
                    <option value="pdf">PDF (pixel)</option>
                    <option value="zpl">ZPL (thermal/raw)</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-filament::fieldset>
        <x-filament::fieldset
            id="label-dpi-fieldset"
            style="margin-top: 16px;">
            <x-slot name="label">
                Label DPI
            </x-slot>

            <x-filament::input.wrapper>
                <x-filament::input.select id="label-dpi">
                    <option value="203">203 DPI</option>
                    <option value="300">300 DPI</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </x-filament::fieldset>

        <x-filament::button
            style="margin-top: 16px;"
            type="button"
            id="refresh-printers"
            color="gray"
        >
            Refresh Printers
        </x-filament::button>

    </x-filament::section>

    <!-- Scale Section -->
    <x-filament::section>
        <x-slot name="heading">Scale</x-slot>
        <x-slot name="description">Configure USB scale for weighing packages.</x-slot>

        <div class="space-y-4">
            <x-filament::fieldset>
                <x-slot name="label">Scale Backend</x-slot>

                <x-filament::input.wrapper>
                    <x-filament::input.select id="scale-backend-select">
                        <option value="auto">Auto (recommended)</option>
                        <option value="webhid">WebHID (Chrome/Edge only)</option>
                        <option value="qztray">QZ Tray (all browsers)</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>

                <p id="scale-backend-info" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
            </x-filament::fieldset>

            <!-- WebHID pairing UI -->
            <div id="webhid-pairing" style="display: none">
                <x-filament::fieldset>
                    <x-slot name="label">Scale Device</x-slot>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Click "Pair Scale" to select your USB scale from the browser device picker.</p>
                </x-filament::fieldset>
            </div>

            <!-- QZ Tray pairing UI -->
            <div id="qztray-pairing" style="display: none">
                <x-filament::fieldset>
                    <x-slot name="label">Scale Device</x-slot>

                    <x-filament::input.wrapper>
                        <x-filament::input.select id="scale-device-select">
                            <option value="">-- Click "Detect Scales" to find devices --</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </x-filament::fieldset>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="scale-vendor-id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Vendor ID
                    </label>
                    <input
                        type="text"
                        id="scale-vendor-id"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500"
                        placeholder="e.g., 0x0922"
                        readonly
                    >
                </div>
                <div>
                    <label for="scale-product-id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Product ID
                    </label>
                    <input
                        type="text"
                        id="scale-product-id"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-primary-500 focus:ring-primary-500"
                        placeholder="e.g., 0x8003"
                        readonly
                    >
                </div>
            </div>

            <div id="scale-reading" class="p-5 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 hidden">
                <div class="text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Current Reading</div>
                <div class="text-4xl font-mono font-bold text-gray-950 dark:text-white tabular-nums">
                    <span id="scale-weight">0.00</span> <span class="text-lg font-medium text-gray-400">lbs</span>
                </div>
                <div id="scale-status" class="text-xs text-gray-500 dark:text-gray-400 mt-2">Waiting for stable reading...</div>
            </div>

            <div class="flex gap-2">
                <!-- WebHID: Pair Scale button -->
                <x-filament::button
                    type="button"
                    id="pair-scale-webhid"
                    color="gray"
                    style="display: none"
                >
                    Pair Scale
                </x-filament::button>

                <!-- QZ Tray: Detect Scales button -->
                <x-filament::button
                    type="button"
                    id="detect-scales"
                    color="gray"
                    style="display: none"
                >
                    Detect Scales
                </x-filament::button>

                <x-filament::button
                    type="button"
                    id="disconnect-scale"
                    color="danger"
                    style="display: none"
                >
                    Disconnect
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <!-- Save Button -->
    <div class="flex items-center gap-4">
        <x-filament::button
            type="button"
            id="save-settings"
        >
            Save Settings
        </x-filament::button>

        <span id="save-status" class="text-sm text-gray-500 hidden"></span>
    </div>

    <x-qz-tray-script />
    <x-scale-script />

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const labelPrinterSelect = document.getElementById('label-printer');
            const reportPrinterSelect = document.getElementById('report-printer');
            const refreshPrintersBtn = document.getElementById('refresh-printers');
            const labelFormatSelect = document.getElementById('label-format');
            const labelDpiSelect = document.getElementById('label-dpi');
            const labelDpiFieldset = document.getElementById('label-dpi-fieldset');
            const detectScalesBtn = document.getElementById('detect-scales');
            const scaleDeviceSelect = document.getElementById('scale-device-select');
            const saveSettingsBtn = document.getElementById('save-settings');
            const scaleVendorInput = document.getElementById('scale-vendor-id');
            const scaleProductInput = document.getElementById('scale-product-id');
            const qzIndicator = document.getElementById('qz-indicator');
            const qzStatusText = document.getElementById('qz-status-text');
            const saveStatus = document.getElementById('save-status');

            const scaleBackendSelect = document.getElementById('scale-backend-select');
            const scaleBackendInfo = document.getElementById('scale-backend-info');
            const webhidPairing = document.getElementById('webhid-pairing');
            const qztrayPairing = document.getElementById('qztray-pairing');
            const pairScaleWebhidBtn = document.getElementById('pair-scale-webhid');

            // Load saved settings from localStorage
            function loadSettings() {
                const labelPrinter = localStorage.getItem('labelPrinter') || '';
                const reportPrinter = localStorage.getItem('reportPrinter') || '';
                const scaleVendorId = localStorage.getItem('scaleVendorId') || '';
                const scaleProductId = localStorage.getItem('scaleProductId') || '';
                const labelFormat = localStorage.getItem('labelFormat') || 'pdf';
                const labelDpi = localStorage.getItem('labelDpi') || '203';
                const scaleBackend = localStorage.getItem('scaleBackend') || 'auto';

                scaleVendorInput.value = scaleVendorId;
                scaleProductInput.value = scaleProductId;
                labelFormatSelect.value = labelFormat;
                labelDpiSelect.value = labelDpi;
                scaleBackendSelect.value = scaleBackend;
                updateDpiVisibility();
                updateScaleBackendUI();

                return { labelPrinter, reportPrinter };
            }

            // Show/hide DPI selector based on label format
            function updateDpiVisibility() {
                labelDpiFieldset.style.display = labelFormatSelect.value === 'zpl' ? '' : 'none';
            }

            // Show/hide scale pairing UI based on resolved backend
            function updateScaleBackendUI() {
                // Re-init ScaleUtils backend based on current selection
                const setting = scaleBackendSelect.value;
                localStorage.setItem('scaleBackend', setting);
                ScaleUtils.initBackend();
                const resolved = ScaleUtils.backend;

                // Show backend info
                const labels = { webhid: 'WebHID (low latency)', qztray: 'QZ Tray (cross-browser)', none: 'No backend available' };
                scaleBackendInfo.textContent = `Active: ${labels[resolved] || resolved}`;

                // Toggle pairing UIs
                const isWebHid = resolved === 'webhid';
                const isQzTray = resolved === 'qztray';

                webhidPairing.style.display = isWebHid ? '' : 'none';
                pairScaleWebhidBtn.style.display = isWebHid ? '' : 'none';
                qztrayPairing.style.display = isQzTray ? '' : 'none';
                detectScalesBtn.style.display = isQzTray ? '' : 'none';
            }

            // WebHID pairing via browser device picker
            async function pairScaleWebhid() {
                try {
                    pairScaleWebhidBtn.setAttribute('aria-disabled', 'true');
                    pairScaleWebhidBtn.classList.add('fi-disabled');

                    const [device] = await navigator.hid.requestDevice({
                        filters: [] // show all HID devices
                    });

                    if (!device) return;

                    // Convert integer IDs to hex strings for storage compatibility
                    const vendorId = '0x' + device.vendorId.toString(16).padStart(4, '0');
                    const productId = '0x' + device.productId.toString(16).padStart(4, '0');

                    scaleVendorInput.value = vendorId;
                    scaleProductInput.value = productId;
                    localStorage.setItem('scaleVendorId', vendorId);
                    localStorage.setItem('scaleProductId', productId);

                    new FilamentNotification()
                        .title('Scale Paired')
                        .body(`${device.productName || 'HID Device'} (${vendorId}:${productId})`)
                        .success()
                        .send();

                    // Auto-connect to show live reading
                    await connectScale();

                } catch (error) {
                    if (error.name !== 'NotAllowedError') {
                        console.error('WebHID pairing failed:', error);
                        new FilamentNotification()
                            .title('Pairing Failed')
                            .body(error.message || 'Could not pair scale.')
                            .danger()
                            .send();
                    }
                } finally {
                    pairScaleWebhidBtn.removeAttribute('aria-disabled');
                    pairScaleWebhidBtn.classList.remove('fi-disabled');
                }
            }

            // Save settings to localStorage
            function saveSettings() {
                localStorage.setItem('labelPrinter', labelPrinterSelect.value);
                localStorage.setItem('reportPrinter', reportPrinterSelect.value);
                localStorage.setItem('labelFormat', labelFormatSelect.value);
                localStorage.setItem('labelDpi', labelDpiSelect.value);
                localStorage.setItem('scaleVendorId', scaleVendorInput.value);
                localStorage.setItem('scaleProductId', scaleProductInput.value);
                localStorage.setItem('scaleBackend', scaleBackendSelect.value);

                saveStatus.textContent = 'Settings saved!';
                saveStatus.classList.remove('hidden');
                setTimeout(() => saveStatus.classList.add('hidden'), 3000);

                new FilamentNotification()
                    .title('Settings Saved')
                    .body('Device settings have been saved to this browser.')
                    .success()
                    .send();
            }

            // Update QZ Tray status indicator
            function updateQzStatus(connected, message) {
                qzIndicator.classList.remove('bg-gray-400', 'bg-green-500', 'bg-red-500', 'ring-gray-400/20', 'ring-green-500/20', 'ring-red-500/20');
                qzIndicator.classList.add(connected ? 'bg-green-500' : 'bg-red-500');
                qzIndicator.classList.add(connected ? 'ring-green-500/20' : 'ring-red-500/20');
                qzStatusText.textContent = message;
            }

            // Populate printer dropdowns
            function populatePrinters(printers, savedLabelPrinter, savedReportPrinter) {
                [labelPrinterSelect, reportPrinterSelect].forEach((select, index) => {
                    const savedValue = index === 0 ? savedLabelPrinter : savedReportPrinter;
                    select.innerHTML = '<option value="">-- Select a printer --</option>';

                    printers.forEach(printer => {
                        const option = document.createElement('option');
                        option.value = printer;
                        option.textContent = printer;
                        if (printer === savedValue) {
                            option.selected = true;
                        }
                        select.appendChild(option);
                    });

                    select.removeAttribute('disabled');
                });

                refreshPrintersBtn.removeAttribute('aria-disabled');
                refreshPrintersBtn.classList.remove('fi-disabled');
            }

            // Initialize QZ Tray connection
            async function initQZTray() {
                const saved = loadSettings();

                if (typeof qz === 'undefined') {
                    updateQzStatus(false, 'QZ Tray library failed to load');
                    return;
                }

                // Set up certificate authentication
                setupQzSecurity();

                try {
                    updateQzStatus(false, 'Connecting to QZ Tray...');

                    if (!qz.websocket.isActive()) {
                        await qz.websocket.connect();
                    }

                    updateQzStatus(true, 'Connected to QZ Tray');
                    document.dispatchEvent(new CustomEvent('qz-tray:connected'));

                    // Get available printers
                    const printers = await qz.printers.find();
                    populatePrinters(printers, saved.labelPrinter, saved.reportPrinter);

                } catch (error) {
                    console.error('QZ Tray error:', error);

                    if (error.message && error.message.includes('Unable to connect')) {
                        updateQzStatus(false, 'QZ Tray not running. Please install and start QZ Tray.');
                    } else {
                        updateQzStatus(false, `QZ Tray error: ${error.message || 'Connection failed'}`);
                    }

                    // Disable printer selects
                    labelPrinterSelect.innerHTML = '<option value="">QZ Tray not available</option>';
                    reportPrinterSelect.innerHTML = '<option value="">QZ Tray not available</option>';
                }
            }

            // Refresh printers list
            async function refreshPrinters() {
                try {
                    refreshPrintersBtn.setAttribute('aria-disabled', 'true');
                    refreshPrintersBtn.classList.add('fi-disabled');
                    refreshPrintersBtn.textContent = 'Refreshing...';

                    if (!qz.websocket.isActive()) {
                        await qz.websocket.connect();
                    }

                    const printers = await qz.printers.find();
                    const savedLabel = localStorage.getItem('labelPrinter') || '';
                    const savedReport = localStorage.getItem('reportPrinter') || '';
                    populatePrinters(printers, savedLabel, savedReport);

                    new FilamentNotification()
                        .title('Printers Refreshed')
                        .body(`Found ${printers.length} printer(s)`)
                        .success()
                        .send();

                } catch (error) {
                    console.error('Failed to refresh printers:', error);
                    new FilamentNotification()
                        .title('Refresh Failed')
                        .body('Could not refresh printer list')
                        .danger()
                        .send();
                } finally {
                    refreshPrintersBtn.removeAttribute('aria-disabled');
                    refreshPrintersBtn.classList.remove('fi-disabled');
                    refreshPrintersBtn.textContent = 'Refresh Printers';
                }
            }

            // Detect USB scales via QZ Tray HID
            let scaleStreamActive = false;
            const scaleReadingDiv = document.getElementById('scale-reading');
            const scaleWeightSpan = document.getElementById('scale-weight');
            const scaleStatusDiv = document.getElementById('scale-status');
            const disconnectScaleBtn = document.getElementById('disconnect-scale');

            function updateScaleDisplay(result) {
                if (result) {
                    scaleWeightSpan.textContent = result.weight.toFixed(2);
                    scaleStatusDiv.textContent = result.status;
                    scaleStatusDiv.classList.toggle('text-success-500', result.isStable);
                    scaleStatusDiv.classList.toggle('text-warning-500', !result.isStable);
                }
            }

            async function detectScales() {
                try {
                    detectScalesBtn.setAttribute('aria-disabled', 'true');
                    detectScalesBtn.classList.add('fi-disabled');
                    detectScalesBtn.textContent = 'Scanning...';

                    const devices = await qz.hid.listDevices();

                    scaleDeviceSelect.innerHTML = '<option value="">-- Select a scale --</option>';

                    if (devices.length === 0) {
                        scaleDeviceSelect.innerHTML = '<option value="">No HID devices found</option>';
                        new FilamentNotification()
                            .title('No Devices Found')
                            .body('No USB HID devices detected. Make sure your scale is connected.')
                            .warning()
                            .send();
                        return;
                    }

                    const savedVendorId = scaleVendorInput.value;
                    const savedProductId = scaleProductInput.value;

                    // Deduplicate by vendor:product (devices can appear multiple times for different usage pages)
                    const seen = new Set();
                    const uniqueDevices = devices.filter(device => {
                        const key = device.vendorId + ':' + device.productId;
                        if (seen.has(key)) return false;
                        seen.add(key);
                        return true;
                    });

                    uniqueDevices.forEach(device => {
                        const option = document.createElement('option');
                        const vendorId = device.vendorId;
                        const productId = device.productId;
                        option.value = vendorId + ':' + productId;
                        option.textContent = (device.product || 'Unknown Device') + ' (' + vendorId + ':' + productId + ')';

                        if (vendorId === savedVendorId && productId === savedProductId) {
                            option.selected = true;
                        }

                        scaleDeviceSelect.appendChild(option);
                    });

                    new FilamentNotification()
                        .title('Devices Found')
                        .body(`Found ${uniqueDevices.length} HID device(s). Select your scale from the dropdown.`)
                        .success()
                        .send();

                } catch (error) {
                    console.error('Failed to detect scales:', error);
                    new FilamentNotification()
                        .title('Detection Failed')
                        .body('Could not scan for HID devices. Make sure QZ Tray is running.')
                        .danger()
                        .send();
                } finally {
                    detectScalesBtn.removeAttribute('aria-disabled');
                    detectScalesBtn.classList.remove('fi-disabled');
                    detectScalesBtn.textContent = 'Detect Scales';
                }
            }

            // When a scale is selected from the dropdown, update the vendor/product ID fields
            scaleDeviceSelect.addEventListener('change', async function() {
                const value = this.value;
                if (!value) {
                    scaleVendorInput.value = '';
                    scaleProductInput.value = '';
                    return;
                }

                const [vendorId, productId] = value.split(':');
                scaleVendorInput.value = vendorId;
                scaleProductInput.value = productId;

                // Save immediately so ScaleUtils can find the device
                localStorage.setItem('scaleVendorId', vendorId);
                localStorage.setItem('scaleProductId', productId);

                // Auto-connect to show live reading
                await connectScale();
            });

            async function connectScale() {
                const vendorId = scaleVendorInput.value;
                const productId = scaleProductInput.value;

                if (!vendorId || !productId) return;

                try {
                    await ScaleUtils.claimScale();
                    await ScaleUtils.startScaleStream(updateScaleDisplay);
                    scaleStreamActive = true;

                    scaleReadingDiv.classList.remove('hidden');
                    disconnectScaleBtn.style.display = '';

                    new FilamentNotification()
                        .title('Scale Connected')
                        .body('Reading live weight data from scale.')
                        .success()
                        .send();

                } catch (error) {
                    console.error('Failed to connect to scale:', error);
                    new FilamentNotification()
                        .title('Connection Failed')
                        .body(error.message || 'Could not connect to scale.')
                        .danger()
                        .send();
                }
            }

            async function disconnectScale() {
                if (scaleStreamActive) {
                    try {
                        await ScaleUtils.stopScaleStream();
                    } catch (e) {
                        console.error('Error disconnecting scale:', e);
                    }
                    scaleStreamActive = false;
                }

                // Clear saved scale settings
                localStorage.removeItem('scaleVendorId');
                localStorage.removeItem('scaleProductId');
                scaleVendorInput.value = '';
                scaleProductInput.value = '';
                scaleDeviceSelect.value = '';

                scaleReadingDiv.classList.add('hidden');
                disconnectScaleBtn.style.display = 'none';
                scaleWeightSpan.textContent = '0.00';
                scaleStatusDiv.textContent = 'Waiting for stable reading...';

                new FilamentNotification()
                    .title('Scale Disconnected')
                    .body('Scale has been unpaired from this workstation.')
                    .success()
                    .send();
            }

            // Auto-connect to scale if configured (after QZ Tray is ready)
            async function autoConnectScale() {
                const { vendorId, productId } = ScaleUtils.getScaleIds();
                if (!vendorId || !productId) return;

                try {
                    await connectScale();
                } catch (error) {
                    console.error('Failed to auto-connect scale:', error);
                }
            }

            // Event listeners
            refreshPrintersBtn.addEventListener('click', refreshPrinters);
            labelFormatSelect.addEventListener('change', updateDpiVisibility);
            detectScalesBtn.addEventListener('click', detectScales);
            disconnectScaleBtn.addEventListener('click', disconnectScale);
            saveSettingsBtn.addEventListener('click', saveSettings);
            scaleBackendSelect.addEventListener('change', updateScaleBackendUI);
            pairScaleWebhidBtn.addEventListener('click', pairScaleWebhid);

            // Auto-connect scale based on backend
            if (ScaleUtils.backend === 'webhid') {
                autoConnectScale();
            } else {
                document.addEventListener('qz-tray:connected', function() {
                    autoConnectScale();
                });
            }

            // Initialize
            initQZTray();
        });
    </script>
</x-filament-panels::page>
