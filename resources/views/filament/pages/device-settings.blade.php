<x-filament-panels::page>
    <!-- QZ Tray Status -->
    <div id="qz-status" class="mb-6 p-4 rounded-lg bg-gray-100 dark:bg-gray-800">
        <div class="flex items-center gap-2">
            <div id="qz-indicator" class="w-3 h-3 rounded-full bg-gray-400"></div>
            <span id="qz-status-text" class="text-sm">Checking QZ Tray connection...</span>
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

        <x-filament::button
            style="margin-top: 16px;"
            type="button"
            id="refresh-printers"
            color="gray"
            disabled
        >
            Refresh Printers
        </x-filament::button>

    </x-filament::section>

    <!-- Scale Section -->
    <x-filament::section>
        <x-slot name="heading">Scale</x-slot>
        <x-slot name="description">Configure USB scale for weighing packages. Click "Pair Scale" and select your scale from the browser prompt.</x-slot>

        <div class="space-y-4">
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

            <div id="scale-name" class="text-sm text-gray-600 dark:text-gray-400 hidden">
                Detected: <span id="scale-name-text"></span>
            </div>

            <div id="scale-reading" class="p-4 bg-gray-50 dark:bg-gray-900 rounded-lg hidden">
                <div class="text-sm text-gray-500 dark:text-gray-400 mb-1">Current Reading</div>
                <div class="text-3xl font-mono font-bold text-gray-950 dark:text-white">
                    <span id="scale-weight">0.00</span> <span class="text-lg">lbs</span>
                </div>
                <div id="scale-status" class="text-xs text-gray-500 dark:text-gray-400 mt-1">Waiting for stable reading...</div>
            </div>

            <div class="flex gap-2">
                <x-filament::button
                    type="button"
                    id="pair-scale"
                    color="gray"
                >
                    Pair Scale
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
            const pairScaleBtn = document.getElementById('pair-scale');
            const saveSettingsBtn = document.getElementById('save-settings');
            const scaleVendorInput = document.getElementById('scale-vendor-id');
            const scaleProductInput = document.getElementById('scale-product-id');
            const scaleNameDiv = document.getElementById('scale-name');
            const scaleNameText = document.getElementById('scale-name-text');
            const qzIndicator = document.getElementById('qz-indicator');
            const qzStatusText = document.getElementById('qz-status-text');
            const saveStatus = document.getElementById('save-status');

            // Load saved settings from localStorage
            function loadSettings() {
                const labelPrinter = localStorage.getItem('labelPrinter') || '';
                const reportPrinter = localStorage.getItem('reportPrinter') || '';
                const scaleVendorId = localStorage.getItem('scaleVendorId') || '';
                const scaleProductId = localStorage.getItem('scaleProductId') || '';
                const scaleName = localStorage.getItem('scaleName') || '';

                scaleVendorInput.value = scaleVendorId;
                scaleProductInput.value = scaleProductId;

                if (scaleName) {
                    scaleNameText.textContent = scaleName;
                    scaleNameDiv.classList.remove('hidden');
                }

                return { labelPrinter, reportPrinter };
            }

            // Save settings to localStorage
            function saveSettings() {
                localStorage.setItem('labelPrinter', labelPrinterSelect.value);
                localStorage.setItem('reportPrinter', reportPrinterSelect.value);
                localStorage.setItem('scaleVendorId', scaleVendorInput.value);
                localStorage.setItem('scaleProductId', scaleProductInput.value);

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
                qzIndicator.classList.remove('bg-gray-400', 'bg-green-500', 'bg-red-500');
                qzIndicator.classList.add(connected ? 'bg-green-500' : 'bg-red-500');
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

                refreshPrintersBtn.removeAttribute('disabled');
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
                    refreshPrintersBtn.setAttribute('disabled', 'disabled');
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
                    refreshPrintersBtn.removeAttribute('disabled');
                    refreshPrintersBtn.classList.remove('fi-disabled');
                    refreshPrintersBtn.textContent = 'Refresh Printers';
                }
            }

            // Detect USB scale via WebHID
            async function detectScale() {
                try {
                    const devices = await navigator.hid.requestDevice({ filters: [] });
                    if (devices.length > 0) {
                        const device = devices[0];
                        const vendorId = '0x' + device.vendorId.toString(16).padStart(4, '0');
                        const productId = '0x' + device.productId.toString(16).padStart(4, '0');

                        scaleVendorInput.value = vendorId;
                        scaleProductInput.value = productId;
                        scaleNameText.textContent = device.productName || 'Unknown Scale';
                        scaleNameDiv.classList.remove('hidden');

                        localStorage.setItem('scaleName', device.productName || 'Unknown Scale');

                        new FilamentNotification()
                            .title('Scale Detected')
                            .body(`${device.productName} (Vendor: ${vendorId}, Product: ${productId})`)
                            .success()
                            .send();

                        autoConnectScale();
                    }
                } catch (error) {
                    console.error('Failed to detect scale:', error);
                    new FilamentNotification()
                        .title('Scale Detection Failed')
                        .body('Could not detect scale. Make sure your scale is connected.')
                        .danger()
                        .send();
                }
            }

            // Scale connection and reading
            let scaleDevice = null;
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

            async function connectToScale() {
                const vendorId = scaleVendorInput.value;
                const productId = scaleProductInput.value;

                if (!vendorId || !productId) {
                    new FilamentNotification()
                        .title('No Scale Configured')
                        .body('Please detect a scale first.')
                        .warning()
                        .send();
                    return;
                }

                try {
                    // Get previously authorized devices
                    const devices = await navigator.hid.getDevices();
                    const vid = vendorId.startsWith('0x') ? parseInt(vendorId, 16) : parseInt(vendorId);
                    const pid = productId.startsWith('0x') ? parseInt(productId, 16) : parseInt(productId);

                    let device = devices.find(d => d.vendorId === vid && d.productId === pid);

                    // If not found in authorized devices, request permission
                    if (!device) {
                        const requestedDevices = await navigator.hid.requestDevice({
                            filters: [{ vendorId: vid, productId: pid }]
                        });
                        if (requestedDevices.length > 0) {
                            device = requestedDevices[0];
                        }
                    }

                    if (!device) {
                        throw new Error('Scale not found');
                    }

                    if (!device.opened) {
                        await device.open();
                    }

                    scaleDevice = device;

                    device.addEventListener('inputreport', (event) => {
                        const result = ScaleUtils.parseScaleData(event.data);
                        updateScaleDisplay(result);
                    });

                    scaleReadingDiv.classList.remove('hidden');
                    disconnectScaleBtn.style.display = '';

                    new FilamentNotification()
                        .title('Scale Connected')
                        .body(`Reading from ${device.productName || 'scale'}`)
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
                if (scaleDevice) {
                    try {
                        await scaleDevice.close();
                        await scaleDevice.forget();
                    } catch (e) {
                        console.error('Error disconnecting scale:', e);
                    }
                    scaleDevice = null;
                }

                // Clear saved scale settings
                localStorage.removeItem('scaleVendorId');
                localStorage.removeItem('scaleProductId');
                localStorage.removeItem('scaleName');
                scaleVendorInput.value = '';
                scaleProductInput.value = '';
                scaleNameDiv.classList.add('hidden');

                scaleReadingDiv.classList.add('hidden');
                disconnectScaleBtn.style.display = 'none';
                scaleWeightSpan.textContent = '0.00';
                scaleStatusDiv.textContent = 'Waiting for stable reading...';

                new FilamentNotification()
                    .title('Scale Disconnected')
                    .body('Scale has been unpaired from this browser.')
                    .success()
                    .send();
            }

            // Auto-connect to scale if configured
            async function autoConnectScale() {
                if (!('hid' in navigator)) {
                    return;
                }

                try {
                    const devices = await navigator.hid.getDevices();

                    if (devices.length === 0) {
                        return;
                    }

                    const { vendorId: vid, productId: pid } = ScaleUtils.getScaleIds();
                    let device = null;

                    if (vid && pid) {
                        device = devices.find(d => d.vendorId === vid && d.productId === pid);

                        if (!device) {
                            // Saved scale not found, clear stale localStorage
                            localStorage.removeItem('scaleVendorId');
                            localStorage.removeItem('scaleProductId');
                            localStorage.removeItem('scaleName');
                        }
                    }

                    // If no saved device or saved device not found, use first authorized device
                    if (!device && devices.length > 0) {
                        device = devices[0];
                        const newVendorId = '0x' + device.vendorId.toString(16).padStart(4, '0');
                        const newProductId = '0x' + device.productId.toString(16).padStart(4, '0');

                        // Update localStorage and UI
                        localStorage.setItem('scaleVendorId', newVendorId);
                        localStorage.setItem('scaleProductId', newProductId);
                        localStorage.setItem('scaleName', device.productName || 'Unknown Scale');

                        scaleVendorInput.value = newVendorId;
                        scaleProductInput.value = newProductId;
                        scaleNameText.textContent = device.productName || 'Unknown Scale';
                        scaleNameDiv.classList.remove('hidden');
                    }

                    if (!device) {
                        return;
                    }

                    if (!device.opened) {
                        await device.open();
                    }

                    scaleDevice = device;

                    device.addEventListener('inputreport', (event) => {
                        const result = ScaleUtils.parseScaleData(event.data);
                        updateScaleDisplay(result);
                    });

                    scaleReadingDiv.classList.remove('hidden');
                    disconnectScaleBtn.style.display = '';
                } catch (error) {
                    console.error('Failed to auto-connect scale:', error);
                }
            }

            // Event listeners
            refreshPrintersBtn.addEventListener('click', refreshPrinters);
            pairScaleBtn.addEventListener('click', detectScale);
            disconnectScaleBtn.addEventListener('click', disconnectScale);
            saveSettingsBtn.addEventListener('click', saveSettings);

            // Initialize
            initQZTray();
            autoConnectScale();
        });
    </script>
</x-filament-panels::page>
