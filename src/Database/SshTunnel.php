<?php

namespace Myneid\LaravelDbTui\Database;

class SshTunnel
{
    private mixed $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $localPort;

    public function __construct(
        string $sshUser,
        string $sshHost,
        int    $sshPort,
        string $remoteHost,
        int    $remotePort,
        bool   $usePrivateKey
    ) {
        $this->localPort = $this->findFreePort();

        $cmd = [
            'ssh',
            '-N',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=30',
            '-p', (string) $sshPort,
            '-L', "{$this->localPort}:{$remoteHost}:{$remotePort}",
            "{$sshUser}@{$sshHost}",
        ];

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->process = proc_open($cmd, $descriptors, $this->pipes);

        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start SSH tunnel process.');
        }

        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->waitForPort();
    }

    public function getLocalPort(): int
    {
        return $this->localPort;
    }

    public function __destruct()
    {
        if ($this->process !== null && is_resource($this->process)) {
            proc_terminate($this->process, 9);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function findFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Could not bind to a free local port: {$errstr}");
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int) explode(':', $name)[1];
    }

    private function waitForPort(): void
    {
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $errno  = 0;
            $errstr = '';
            $sock   = @stream_socket_client(
                "tcp://127.0.0.1:{$this->localPort}",
                $errno,
                $errstr,
                0.2
            );

            if ($sock !== false) {
                fclose($sock);
                return;
            }

            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $stderr = stream_get_contents($this->pipes[2]) ?: '';
                throw new \RuntimeException(
                    'SSH tunnel exited (code ' . $status['exitcode'] . ')'
                    . ($stderr ? ': ' . trim($stderr) : '. Check credentials and host reachability.')
                );
            }

            usleep(200_000);
        }

        throw new \RuntimeException('SSH tunnel did not become ready within 15 seconds.');
    }
}
