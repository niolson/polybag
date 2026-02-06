/**
 * QZ Tray Integration Module
 *
 * Handles connection to QZ Tray and printing of shipping labels.
 * QZ Tray must be installed on the local machine: https://qz.io/download/
 */

// QZ Tray is loaded via CDN in the blade template since it doesn't play well with bundlers
// This module provides the application-level API

/**
 * Configure QZ Tray certificate-based authentication
 * This eliminates the "Untrusted website" popup
 */
function setupQzSecurity() {
    if (typeof qz === 'undefined') return;

    // Certificate promise - fetches the public certificate
    qz.security.setCertificatePromise(function(resolve, reject) {
        fetch('/qz-certificate.pem')
            .then(response => response.ok ? response.text() : reject(response.statusText))
            .then(resolve)
            .catch(reject);
    });

    // Signature promise - signs requests with the private key via server endpoint
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

const QZTray = {
    connected: false,
    connecting: false,

    /**
     * Initialize connection to QZ Tray
     * @returns {Promise<boolean>}
     */
    async connect() {
        if (this.connected) {
            return true;
        }

        if (this.connecting) {
            // Wait for existing connection attempt
            return new Promise((resolve) => {
                const checkConnection = setInterval(() => {
                    if (!this.connecting) {
                        clearInterval(checkConnection);
                        resolve(this.connected);
                    }
                }, 100);
            });
        }

        this.connecting = true;

        try {
            if (typeof qz === 'undefined') {
                throw new Error('QZ Tray library not loaded. Make sure qz-tray.js is included.');
            }

            // Set up certificate-based authentication
            setupQzSecurity();

            if (!qz.websocket.isActive()) {
                await qz.websocket.connect();
            }

            this.connected = true;
            // Connection established
            return true;
        } catch (error) {
            console.error('QZ Tray connection failed:', error);
            this.connected = false;
            throw error;
        } finally {
            this.connecting = false;
        }
    },

    /**
     * Disconnect from QZ Tray
     */
    async disconnect() {
        if (qz.websocket.isActive()) {
            await qz.websocket.disconnect();
        }
        this.connected = false;
    },

    /**
     * Get list of available printers
     * @returns {Promise<string[]>}
     */
    async getPrinters() {
        await this.connect();
        return await qz.printers.find();
    },

    /**
     * Get the default printer
     * @returns {Promise<string>}
     */
    async getDefaultPrinter() {
        await this.connect();
        return await qz.printers.getDefault();
    },

    /**
     * Print a PDF from base64 data
     * @param {string} printerName - Name of the printer
     * @param {string} base64Data - Base64 encoded PDF data
     * @param {object} options - Print options
     * @returns {Promise<void>}
     */
    async printPdf(printerName, base64Data, options = {}) {
        await this.connect();

        const config = qz.configs.create(printerName, {
            // 4x6 label defaults
            size: options.size || { width: 4, height: 6 },
            units: options.units || 'in',
            orientation: options.orientation || 'portrait',
            ...options.config
        });

        const data = [{
            type: 'pixel',
            format: 'pdf',
            flavor: 'base64',
            data: base64Data
        }];

        await qz.print(config, data);
    },

    /**
     * Print raw commands (ZPL, EPL, etc.)
     * @param {string} printerName - Name of the printer
     * @param {string} rawData - Raw printer commands
     * @param {object} options - Print options
     * @returns {Promise<void>}
     */
    async printRaw(printerName, rawData, options = {}) {
        await this.connect();

        const config = qz.configs.create(printerName, options.config || {});

        const data = [{
            type: 'raw',
            format: 'plain',
            data: rawData
        }];

        await qz.print(config, data);
    },

    /**
     * Check if QZ Tray is available and connected
     * @returns {boolean}
     */
    isConnected() {
        return this.connected && typeof qz !== 'undefined' && qz.websocket.isActive();
    }
};

// Export for use in other modules
window.QZTray = QZTray;

export default QZTray;
