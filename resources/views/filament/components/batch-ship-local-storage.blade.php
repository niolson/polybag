<div
    x-init="
        $wire.set('mountedActions.0.data.label_format', localStorage.getItem('labelFormat') || 'pdf');
        $wire.set('mountedActions.0.data.label_dpi', parseInt(localStorage.getItem('labelDpi') || '203') || null);
        $wire.set('mountedActions.0.data.has_report_printer', !!localStorage.getItem('reportPrinter'));
    "
></div>
