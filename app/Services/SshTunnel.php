<?php

namespace App\Services;

use RuntimeException;

class SshTunnel
{
    private ?int $localPort = null;

    /** @var resource|null */
    private $process = null;

    public function __construct(
        private readonly string $sshHost,
        private readonly string $sshUser,
        private readonly string $sshKey,
        private readonly string $remoteHost,
        private readonly int $remotePort,
        private readonly int $sshPort = 22,
    ) {}

    /**
     * Create a tunnel from config array.
     *
     * @param  array{ssh_host: string, ssh_user: string, ssh_key: string, ssh_port?: int, remote_host: string, remote_port: int}  $config
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

        $command = sprintf(
            'ssh -N -L %d:%s:%d %s@%s -p %d -i %s -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=2 -o ExitOnForwardFailure=yes',
            $this->localPort,
            escapeshellarg($this->remoteHost),
            $this->remotePort,
            escapeshellarg($this->sshUser),
            escapeshellarg($this->sshHost),
            $this->sshPort,
            escapeshellarg($this->sshKey),
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
            return;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            posix_kill($status['pid'], SIGTERM);
        }

        proc_close($this->process);
        $this->process = null;
        $this->localPort = null;
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
