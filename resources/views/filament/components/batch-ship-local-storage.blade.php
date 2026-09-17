<x-printer-settings-script />

<div
    x-init="
        $wire.set('mountedActions.0.data.label_format', PrinterSettings.labelFormat());
        $wire.set('mountedActions.0.data.label_dpi', PrinterSettings.labelDpi());
        $wire.set('mountedActions.0.data.has_report_printer', PrinterSettings.hasDocumentPrinter());
    "
></div>
