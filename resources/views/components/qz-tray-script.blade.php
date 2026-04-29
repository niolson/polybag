@vite('resources/js/qz.js')

<script>
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
</script>
