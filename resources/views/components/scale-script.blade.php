<script>
    /**
     * Dual-backend scale utilities (WebHID + QZ Tray).
     *
     * USB HID scale report format:
     *   WebHID (5 bytes via DataView):
     *     Byte 0: Status, Byte 1: Unit, Byte 2: Scale factor, Bytes 3-4: Weight (LE uint16)
     *   QZ Tray (6-byte hex array):
     *     Byte 0: Report ID, Byte 1: Status, Byte 2: Unit, Byte 3: Scale factor, Bytes 4-5: Weight (LE uint16)
     *
     * Backend selection: localStorage 'scaleBackend' = 'webhid' | 'qztray' | 'auto' (default).
     * Auto: WebHID if navigator.hid exists, else QZ Tray.
     */
    window.ScaleUtils = {
        /** @type {'webhid'|'qztray'|'none'} Resolved backend */
        backend: 'none',

        /** @type {HIDDevice|null} Open WebHID device reference */
        _webHidDevice: null,

        /** @type {Function|null} Bound WebHID inputreport listener (for removal) */
        _webHidListener: null,

        /**
         * Detect and set the active backend.
         * Call this once at page load (after DOM ready).
         */
        initBackend() {
            this.backend = this.getBackend();
        },

        /**
         * Resolve which backend to use.
         * @returns {'webhid'|'qztray'|'none'}
         */
        getBackend() {
            const override = localStorage.getItem('scaleBackend');
            if (override === 'webhid') {
                return ('hid' in navigator) ? 'webhid' : 'none';
            }
            if (override === 'qztray') {
                return (typeof qz !== 'undefined') ? 'qztray' : 'none';
            }
            // auto
            if ('hid' in navigator) return 'webhid';
            if (typeof qz !== 'undefined') return 'qztray';
            return 'none';
        },

        /**
         * Read scale vendor/product IDs from localStorage as hex strings.
         */
        getScaleIds() {
            return {
                vendorId: localStorage.getItem('scaleVendorId') || null,
                productId: localStorage.getItem('scaleProductId') || null
            };
        },

        /**
         * Build device info object from stored IDs.
         */
        getScaleDeviceInfo() {
            const { vendorId, productId } = this.getScaleIds();
            if (!vendorId || !productId) return null;
            return { vendorId, productId };
        },

        /**
         * Parse a QZ Tray HID hex array into a scale reading.
         * QZ Tray returns arrays like ["03", "04", "0B", "FF", "2A", "01"].
         */
        parseHexScaleData(hexArray) {
            if (!hexArray || hexArray.length < 6) return null;

            // QZ Tray includes the report ID as byte 0; scale data starts at byte 1
            const status = parseInt(hexArray[1], 16);
            const unit = parseInt(hexArray[2], 16);
            const scaleFactor = parseInt(hexArray[3], 16);
            const signedScaleFactor = scaleFactor > 127 ? scaleFactor - 256 : scaleFactor;
            const weightLow = parseInt(hexArray[4], 16);
            const weightHigh = parseInt(hexArray[5], 16);
            const weightRaw = weightLow | (weightHigh << 8);

            return this._computeWeight(status, unit, signedScaleFactor, weightRaw);
        },

        /**
         * Parse a WebHID DataView (5 bytes, no report ID) into a scale reading.
         */
        parseWebHidData(dataView) {
            if (!dataView || dataView.byteLength < 5) return null;

            const status = dataView.getUint8(0);
            const unit = dataView.getUint8(1);
            const scaleFactor = dataView.getUint8(2);
            const signedScaleFactor = scaleFactor > 127 ? scaleFactor - 256 : scaleFactor;
            const weightRaw = dataView.getUint16(3, true); // little-endian

            return this._computeWeight(status, unit, signedScaleFactor, weightRaw);
        },

        /**
         * Shared weight computation from parsed bytes.
         * @private
         */
        _computeWeight(status, unit, signedScaleFactor, weightRaw) {
            let weight = weightRaw * Math.pow(10, signedScaleFactor);

            // Convert to pounds
            if (unit === 2) weight = weight / 453.592;       // grams
            else if (unit === 11) weight = weight / 16;       // ounces

            let statusText = 'Unknown';
            let isStable = false;
            switch (status) {
                case 2: statusText = 'Zero'; isStable = true; break;
                case 3: statusText = 'In motion...'; break;
                case 4: statusText = 'Stable'; isStable = true; break;
                case 5: statusText = 'Fault'; break;
            }

            return { weight, status: statusText, isStable };
        },

        /**
         * Claim the scale device.
         * WebHID: finds previously authorized device and opens it.
         * QZ Tray: claims the device via qz.hid.claimDevice().
         */
        async claimScale() {
            const deviceInfo = this.getScaleDeviceInfo();
            if (!deviceInfo) throw new Error('No scale configured');

            if (this.backend === 'webhid') {
                const vendorInt = parseInt(deviceInfo.vendorId, 16);
                const productInt = parseInt(deviceInfo.productId, 16);

                const devices = await navigator.hid.getDevices();
                const device = devices.find(d => d.vendorId === vendorInt && d.productId === productInt);

                if (!device) {
                    throw new Error('Scale not found. Re-pair the scale in Device Settings.');
                }

                if (!device.opened) {
                    await device.open();
                }

                this._webHidDevice = device;
            } else if (this.backend === 'qztray') {
                await qz.hid.claimDevice(deviceInfo);
            } else {
                throw new Error('No scale backend available');
            }
        },

        /**
         * Start streaming scale data.
         * Calls callback with { weight, status, isStable } on each reading.
         */
        async startScaleStream(callback) {
            const deviceInfo = this.getScaleDeviceInfo();
            if (!deviceInfo) throw new Error('No scale configured');

            if (this.backend === 'webhid') {
                if (!this._webHidDevice) throw new Error('Scale not claimed. Call claimScale() first.');

                this._webHidListener = (event) => {
                    const result = ScaleUtils.parseWebHidData(event.data);
                    if (result) callback(result);
                };

                this._webHidDevice.addEventListener('inputreport', this._webHidListener);
            } else if (this.backend === 'qztray') {
                qz.hid.setHidCallbacks(function(streamEvent) {
                    if (streamEvent.type === 'RECEIVE') {
                        const result = ScaleUtils.parseHexScaleData(streamEvent.output);
                        if (result) callback(result);
                    }
                });

                await qz.hid.openStream({
                    vendorId: deviceInfo.vendorId,
                    productId: deviceInfo.productId,
                    responseSize: 6,
                    interval: 50
                });
            } else {
                throw new Error('No scale backend available');
            }
        },

        /**
         * Stop the scale stream and release the device.
         */
        async stopScaleStream() {
            if (this.backend === 'webhid') {
                if (this._webHidDevice) {
                    if (this._webHidListener) {
                        this._webHidDevice.removeEventListener('inputreport', this._webHidListener);
                        this._webHidListener = null;
                    }
                    try {
                        await this._webHidDevice.close();
                    } catch (e) {
                        console.warn('Error closing WebHID device:', e);
                    }
                    this._webHidDevice = null;
                }
            } else if (this.backend === 'qztray') {
                const deviceInfo = this.getScaleDeviceInfo();
                if (!deviceInfo) return;

                try {
                    await qz.hid.closeStream(deviceInfo);
                } catch (e) {
                    console.warn('Error closing scale stream:', e);
                }
                try {
                    await qz.hid.releaseDevice(deviceInfo);
                } catch (e) {
                    console.warn('Error releasing scale device:', e);
                }
            }
        }
    };

    // Auto-detect backend on load
    ScaleUtils.initBackend();
</script>
