@vite('resources/js/qz.js')

<x-printer-settings-script />

<script>
    /**
     * qz-tray internally SHA-256-hashes the {call, params, timestamp} object
     * before ever handing anything to setSignaturePromise, so the signing
     * endpoint only ever sees a digest and can't tell which call it belongs to
     * from that alone. This shim hooks the library's optional global `Sha256`
     * override to also stash the pre-hash plaintext (keyed by its own digest,
     * so concurrent signing calls can't clobber each other), so it can be sent
     * to the server alongside the digest for verification.
     */
    const qzSignPlaintexts = new Map();

    async function sha256Hex(data) {
        const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(data));
        return Array.from(new Uint8Array(digest)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    window.Sha256 = {
        hash: function(data) {
            return sha256Hex(data).then(function(hex) {
                qzSignPlaintexts.set(hex, data);
                return hex;
            });
        }
    };

    /**
     * Configure QZ Tray certificate-based authentication.
     * Eliminates the "Untrusted website" popup.
     */
    function setupQzSecurity() {
        if (typeof qz === 'undefined') return;

        qz.security.setCertificatePromise(function(resolve, reject) {
            fetch('/qz-certificate.pem')
                .then(response => response.ok ? response.text() : reject(response.statusText))
                .then(resolve)
                .catch(reject);
        });

        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(function(toSign) {
            return function(resolve, reject) {
                const payload = qzSignPlaintexts.get(toSign);
                qzSignPlaintexts.delete(toSign);

                if (!payload) {
                    reject(new Error('Missing QZ signing payload'));
                    return;
                }

                fetch('/qz/sign', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                    },
                    body: JSON.stringify({ request: toSign, payload: payload })
                })
                .then(response => response.ok ? response.text() : reject(response.statusText))
                .then(resolve)
                .catch(reject);
            };
        });
    }
</script>
