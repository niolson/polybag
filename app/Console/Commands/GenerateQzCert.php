<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class GenerateQzCert extends Command
{
    protected $signature = 'app:generate-qz-cert';

    protected $description = 'Generate a QZ Tray signing certificate and private key';

    public function handle(): int
    {
        $domain = text(
            label: 'Domain (used as certificate CN)',
            placeholder: 'e.g. shipping.example.com',
            required: true,
        );

        $privateKeyPath = storage_path('app/private/qz-private-key.pem');
        $publicCertPath = public_path('qz-certificate.pem');

        if (file_exists($privateKeyPath) || file_exists($publicCertPath)) {
            if (! confirm('Existing QZ Tray certificate files found. Overwrite?', default: false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key) {
            $this->error('Failed to generate private key.');

            return self::FAILURE;
        }

        $isIp = filter_var($domain, FILTER_VALIDATE_IP) !== false;
        $san = $isIp ? "IP:{$domain}" : "DNS:{$domain}";

        $opensslConfig = tmpfile();
        $opensslConfigPath = stream_get_meta_data($opensslConfig)['uri'];
        fwrite($opensslConfig, implode("\n", [
            '[req]',
            'distinguished_name = req_distinguished_name',
            'req_extensions = v3_req',
            '[req_distinguished_name]',
            '[v3_req]',
            "subjectAltName = {$san}",
        ]));

        $csr = openssl_csr_new(
            ['commonName' => $domain],
            $key,
            ['config' => $opensslConfigPath, 'req_extensions' => 'v3_req'],
        );
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['config' => $opensslConfigPath, 'x509_extensions' => 'v3_req']);

        openssl_pkey_export($key, $privateKeyPem);
        openssl_x509_export($cert, $certPem);

        @mkdir(dirname($privateKeyPath), 0775, recursive: true);

        file_put_contents($privateKeyPath, $privateKeyPem);
        file_put_contents($publicCertPath, $certPem);

        chmod($privateKeyPath, 0600);

        $this->info("Private key: {$privateKeyPath}");
        $this->info("Public cert: {$publicCertPath}");
        $this->info("Certificate generated for CN={$domain}, valid for 10 years.");

        return self::SUCCESS;
    }
}
