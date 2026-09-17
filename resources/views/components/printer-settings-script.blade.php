{{--
    Workstation printer preferences, read from this browser's localStorage.

    Three printers: an image label printer (4x6, PDF/PNG/GIF through the pixel
    path), a raw label printer (4x6, ZPL bytes straight through), and a document
    printer (8.5x11 — pack slips, customs forms, pick lists). The two label
    printers may be the same physical device; they are separate settings because
    a raw queue is easy to create in Windows and a driver that accepts raw is not.

    Included by <x-qz-tray-script> and by the few pages that read printer state
    without printing. Safe to include more than once.
--}}
<script>
    if (typeof window.PrinterSettings === 'undefined') {
        window.PrinterSettings = (function () {
            const KEYS = {
                image: 'imageLabelPrinter',
                raw: 'rawLabelPrinter',
                document: 'reportPrinter',
                format: 'labelFormat',
                dpi: 'labelDpi',
            };

            // Before 2026-09 there was one `labelPrinter` and the format toggle said
            // what it was fed. Move it under the key that toggle implies, once, so
            // nobody has to revisit Device Settings after the upgrade.
            function migrateLegacyLabelPrinter() {
                const legacy = localStorage.getItem('labelPrinter');
                if (legacy === null) return;

                if (legacy && !localStorage.getItem(KEYS.image) && !localStorage.getItem(KEYS.raw)) {
                    const format = localStorage.getItem(KEYS.format) || 'pdf';
                    localStorage.setItem(format === 'zpl' ? KEYS.raw : KEYS.image, legacy);
                }

                localStorage.removeItem('labelPrinter');
            }

            try {
                migrateLegacyLabelPrinter();
            } catch (error) {
                console.error('Could not migrate label printer setting:', error);
            }

            const read = (key) => localStorage.getItem(key) || null;

            return {
                keys: KEYS,

                imageLabelPrinter: () => read(KEYS.image),
                rawLabelPrinter: () => read(KEYS.raw),
                documentPrinter: () => read(KEYS.document),

                hasImageLabelPrinter() { return this.imageLabelPrinter() !== null; },
                hasRawLabelPrinter() { return this.rawLabelPrinter() !== null; },
                hasDocumentPrinter() { return this.documentPrinter() !== null; },
                hasLabelPrinter() { return this.hasImageLabelPrinter() || this.hasRawLabelPrinter(); },

                // The printer a label of this format goes to. ZPL is the only raw
                // format; pdf, png, gif and image all print through the pixel path.
                labelPrinterFor(format) {
                    return format === 'zpl' ? this.rawLabelPrinter() : this.imageLabelPrinter();
                },

                // The format to ask carriers for. The saved preference, unless its
                // printer has since been cleared — then whichever label printer is
                // left, so a purchase never returns bytes this workstation cannot print.
                labelFormat() {
                    const preferred = read(KEYS.format) || 'pdf';

                    if (this.labelPrinterFor(preferred)) return preferred;
                    if (this.hasRawLabelPrinter()) return 'zpl';

                    return 'pdf';
                },

                labelDpi() {
                    return parseInt(read(KEYS.dpi) || '203') || 203;
                },
            };
        })();
    }
</script>
