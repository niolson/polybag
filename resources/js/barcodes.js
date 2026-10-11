import JsBarcode from 'jsbarcode';

window.JsBarcode = JsBarcode;
document.dispatchEvent(new CustomEvent('barcodes:ready'));
