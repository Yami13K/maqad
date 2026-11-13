<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A real multi-process HTTP server for the concurrency tests (T1, T2, parallel T3).
 *
 * In-process test requests run one at a time inside one PHP process and one DB transaction,
 * so they can never race. Here PHP's built-in server forks PHP_CLI_SERVER_WORKERS processes,
 * each with its own Postgres connection, so requests really run at the same time and the
 * row locks are what keep them apart. It serves the same public/index.php as nginx + php-fpm.
 */
final class ParallelServer
{
    private function __construct(
        private readonly Process $process,
        public readonly string $baseUrl,
    ) {}

    /** @param array<string, string> $env */
    public static function start(int $workers, array $env): self
    {
        $port = self::freePort();
        $root = dirname(__DIR__, 2);

        $process = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
            $root.'/public',
            ['PHP_CLI_SERVER_WORKERS' => (string) $workers, ...$env],
        );
        $process->start();

        $server = new self($process, "http://127.0.0.1:{$port}");
        $server->waitUntilReady();

        return $server;
    }

    public function stop(): void
    {
        $this->process->stop(3);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', (int) parse_url($this->baseUrl, PHP_URL_PORT), $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('Parallel test server did not start: '.$this->process->getErrorOutput());
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("No free port: {$errstr}");
        }
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        return $port;
    }
}
