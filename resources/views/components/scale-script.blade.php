<script>
    /**
     * Shared WebHID scale utilities.
     *
     * USB HID scale report format (reportId is provided separately by WebHID):
     *   Byte 0: Status (2 = zero, 3 = in motion, 4 = stable, 5 = fault)
     *   Byte 1: Unit (2 = grams, 11 = ounces, 12 = pounds)
     *   Byte 2: Scale factor (signed power of 10)
     *   Bytes 3-4: Weight value (little-endian unsigned 16-bit)
     */
    window.ScaleUtils = {
        /**
         * Read scale vendor/product IDs from localStorage.
         */
        getScaleIds() {
            const vendorId = localStorage.getItem('scaleVendorId');
            const productId = localStorage.getItem('scaleProductId');
            return {
                vendorId: vendorId ? (vendorId.startsWith('0x') ? parseInt(vendorId, 16) : parseInt(vendorId)) : null,
                productId: productId ? (productId.startsWith('0x') ? parseInt(productId, 16) : parseInt(productId)) : null
            };
        },

        /**
         * Build WebHID filter array from stored IDs.
         */
        getScaleFilters() {
            const { vendorId, productId } = this.getScaleIds();
            if (vendorId && productId) {
                return [{ vendorId, productId }];
            }
            return [];
        },

        /**
         * Parse a USB HID scale input report.
         * Returns { weight (in lbs), status (string), isStable (bool) } or null.
         */
        parseScaleData(data) {
            const view = new DataView(data.buffer);
            if (data.byteLength < 5) return null;

            const status = view.getUint8(0);
            const unit = view.getUint8(1);
            const scaleFactor = view.getInt8(2);
            const weightRaw = view.getUint16(3, true);

            let weight = weightRaw * Math.pow(10, scaleFactor);

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
        }
    };
</script>
