<?php

namespace App\Services;

use RuntimeException;

class SshTunnel
{
    private ?int $localPort = null;

    /** @var resource|null */
    private $process = null;

    private ?string $knownHostsFile = null;

    private bool $deleteKnownHostsFileOnClose = false;

    public function __construct(
        private readonly string $sshHost,
        private readonly string $sshUser,
        private readonly string $sshKey,
        private readonly string $remoteHost,
        private readonly int $remotePort,
        private readonly int $sshPort = 22,
        private readonly ?string $knownHostsEntry = null,
        ?string $knownHostsFile = null,
    ) {
        $this->knownHostsFile = $knownHostsFile;
    }

    /**
     * Create a tunnel from config array.
     *
     * @param  array{ssh_host: string, ssh_user: string, ssh_key: string, ssh_port?: int, remote_host: string, remote_port: int, known_hosts_entry?: string|null, known_hosts_file?: string|null}  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            sshHost: $config['ssh_host'],
            sshUser: $config['ssh_user'],
            sshKey: $config['ssh_key'],
            remoteHost: $config['remote_host'],
            remotePort: $config['remote_port'],
            sshPort: $config['ssh_port'] ?? 22,
            knownHostsEntry: $config['known_hosts_entry'] ?? null,
            knownHostsFile: $config['known_hosts_file'] ?? null,
        );
    }

    /**
     * Open the SSH tunnel and return the local port.
     */
    public function open(): int
    {
        if ($this->process !== null) {
            return $this->localPort;
        }

        $this->localPort = $this->findAvailablePort();
        $knownHostsFile = $this->prepareKnownHostsFile();

        $command = sprintf(
            'ssh -N -L %d:%s:%d %s@%s -p %d -i %s -o StrictHostKeyChecking=yes -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=2 -o ExitOnForwardFailure=yes',
            $this->localPort,
            escapeshellarg($this->remoteHost),
            $this->remotePort,
            escapeshellarg($this->sshUser),
            escapeshellarg($this->sshHost),
            $this->sshPort,
            escapeshellarg($this->sshKey),
            escapeshellarg($knownHostsFile),
        );

        $this->process = proc_open(
            $command,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($this->process)) {
            throw new RuntimeException('Failed to open SSH tunnel process.');
        }

        // Wait for the tunnel to be ready (port accepting connections)
        $this->waitForTunnel($pipes[2]);

        return $this->localPort;
    }

    /**
     * Close the SSH tunnel.
     */
    public function close(): void
    {
        if ($this->process === null) {
            $this->cleanupKnownHostsFile();

            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            posix_kill($status['pid'], 15); // SIGTERM
        }

        proc_close($this->process);
        $this->process = null;
        $this->localPort = null;
        $this->cleanupKnownHostsFile();
    }

    public function getLocalPort(): ?int
    {
        return $this->localPort;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    private function prepareKnownHostsFile(): string
    {
        if (filled($this->knownHostsEntry)) {
            $usingTemporaryFile = $this->knownHostsFile === null;
            $path = $this->knownHostsFile ?? tempnam(sys_get_temp_dir(), 'polybag-known-hosts-');

            if ($path === false) {
                throw new RuntimeException('Failed to create known_hosts file for SSH tunnel.');
            }

            $this->knownHostsFile = $path;
            $this->deleteKnownHostsFileOnClose = $usingTemporaryFile;

            $directory = dirname($path);
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }

            file_put_contents($path, trim($this->knownHostsEntry).PHP_EOL);
            chmod($path, 0600);

            return $path;
        }

        if ($this->knownHostsFile && file_exists($this->knownHostsFile)) {
            return $this->knownHostsFile;
        }

        throw new RuntimeException('SSH server host key is required. Paste the SSH Server Host Key in Settings to continue.');
    }

    private function cleanupKnownHostsFile(): void
    {
        if ($this->deleteKnownHostsFileOnClose && $this->knownHostsFile && file_exists($this->knownHostsFile)) {
            @unlink($this->knownHostsFile);
        }

        $this->deleteKnownHostsFileOnClose = false;
    }

    /**
     * Wait for the tunnel's local port to accept connections.
     *
     * @param  resource  $stderrPipe
     */
    private function waitForTunnel($stderrPipe, int $timeoutSeconds = 10): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            // Check if process died
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                $stderr = stream_get_contents($stderrPipe);
                fclose($stderrPipe);
                $this->process = null;
                throw new RuntimeException('SSH tunnel failed: '.trim($stderr));
            }

            // Try connecting to the local port
            $conn = @fsockopen('127.0.0.1', $this->localPort, $errno, $errstr, 0.5);
            if ($conn) {
                fclose($conn);
                fclose($stderrPipe);

                return;
            }

            usleep(200_000); // 200ms
        }

        // Timed out
        $stderr = stream_get_contents($stderrPipe);
        fclose($stderrPipe);
        $this->close();
        throw new RuntimeException('SSH tunnel timed out after '.$timeoutSeconds.'s: '.trim($stderr));
    }
}
