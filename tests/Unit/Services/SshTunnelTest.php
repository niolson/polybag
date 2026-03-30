<?php

use App\Services\SshTunnel;

it('requires an ssh server host key before opening a tunnel', function (): void {
    $tunnel = new SshTunnel(
        sshHost: 'bastion.example.com',
        sshUser: 'polybag',
        sshKey: '/tmp/nonexistent-key',
        remoteHost: '127.0.0.1',
        remotePort: 3306,
    );

    $tunnel->open();
})->throws(RuntimeException::class, 'SSH server host key is required');
