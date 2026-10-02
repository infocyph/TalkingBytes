<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Http\Concurrent\RequestPool;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;

final class SustainedHttpPerformance
{
    private const BATCH_MULTIPLIER = 2;

    /** @var list<int> */
    private const CONCURRENCY_LEVELS = [5, 20, 50];

    private const DELAY_MICROSECONDS = 10_000;

    private const STEADY_STATE_SECONDS = 5.0;

    private const TRIALS = 2;

    private const WARMUP_SECONDS = 1.0;

    public function __construct(
        private readonly string $serverScript,
        private readonly string $mode,
        private readonly string $revision,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $levels = [];
        foreach (self::CONCURRENCY_LEVELS as $concurrency) {
            $levels[] = $this->runConcurrency($concurrency);
        }

        return [
            'revision' => $this->revision,
            'mode' => $this->mode,
            'trials' => self::TRIALS,
            'warmup_seconds' => self::WARMUP_SECONDS,
            'steady_state_seconds' => self::STEADY_STATE_SECONDS,
            'server_delay_us' => self::DELAY_MICROSECONDS,
            'levels' => $levels,
        ];
    }

    /** @param array<string, int> $usage */
    private static function cpuSeconds(array $usage): float
    {
        return (($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0))
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
    }

    /** @return array<string, mixed> */
    private function executePoolTrial(RequestPool $pool, int $port, int $concurrency): array
    {
        $warmup = $this->executeWindow($pool, $port, $concurrency, self::WARMUP_SECONDS, false);

        $resourcesBefore = count(get_resources());
        $usageBefore = getrusage();
        $startedAt = hrtime(true);
        $steady = $this->executeWindow($pool, $port, $concurrency, self::STEADY_STATE_SECONDS, true);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $usageAfter = getrusage();
        $resourcesAfter = count(get_resources());

        $cpuSeconds = self::cpuSeconds($usageAfter) - self::cpuSeconds($usageBefore);
        $successfulRequests = max(0, $steady['requests'] - $steady['errors']);

        return [
            ...$steady,
            'warmup_requests' => $warmup['requests'],
            'warmup_errors' => $warmup['errors'],
            'warmup_timeouts' => $warmup['timeouts'],
            'elapsed_seconds' => $elapsedSeconds,
            'rpm' => $elapsedSeconds > 0.0
                ? $successfulRequests / $elapsedSeconds * 60
                : 0.0,
            'cpu_percent' => $elapsedSeconds > 0.0
                ? ($cpuSeconds / $elapsedSeconds) * 100
                : 0.0,
            'memory_bytes' => memory_get_peak_usage(true),
            'resource_delta' => $resourcesAfter - $resourcesBefore,
        ];
    }

    /** @return array<string, mixed> */
    private function executeTrial(int $port, int $concurrency): array
    {
        if ($this->mode === 'unbound') {
            return $this->executePoolTrial(
                HttpClient::multi($concurrency),
                $port,
                $concurrency,
            );
        }
        if ($this->mode !== 'runwire') {
            throw new InvalidArgumentException('Unsupported sustained HTTP performance mode.');
        }

        $runtime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(
                driver: RuntimeDriver::NATIVE,
                persistentProcess: true,
                persistentApplication: true,
                ownsEventLoop: true,
                runwireLoopAvailable: true,
                supportsAsyncIo: true,
                supportsRunwireCoroutines: true,
            ),
            mode: 'native',
            concurrent: true,
        );

        return (new CoroutineRuntime())->run(
            function (CoroutineScope $scope) use ($runtime, $port, $concurrency): array {
                return $this->executePoolTrial(
                    HttpClient::multi($concurrency)->withRunwire($runtime, scope: $scope),
                    $port,
                    $concurrency,
                );
            },
        );
    }

    /**
     * @return array{
     *     requests:int,
     *     errors:int,
     *     timeouts:int,
     *     batch_latency_ms:list<float>,
     *     max_resource_count:int
     * }
     */
    private function executeWindow(
        RequestPool $pool,
        int $port,
        int $concurrency,
        float $durationSeconds,
        bool $recordLatency,
    ): array {
        $latencies = [];
        $errors = 0;
        $timeouts = 0;
        $requestsCompleted = 0;
        $requestNumber = 0;
        $maxResourceCount = count(get_resources());
        $batchSize = max(1, $concurrency * self::BATCH_MULTIPLIER);
        $deadline = hrtime(true) + (int) round($durationSeconds * 1_000_000_000);

        do {
            $requests = [];
            for ($offset = 0; $offset < $batchSize; $offset++) {
                $requestNumber++;
                $requests[$requestNumber] = HttpRequest::get(sprintf(
                    'http://127.0.0.1:%d/bench/%d',
                    $port,
                    $requestNumber,
                ));
            }

            $startedAt = hrtime(true);
            $result = $pool->sendMany($requests);
            if ($recordLatency) {
                $latencies[] = (hrtime(true) - $startedAt) / 1_000_000;
            }

            $requestsCompleted += count($requests);
            $errors += $result->failedCount();
            $maxResourceCount = max($maxResourceCount, count(get_resources()));

            foreach ($result->failed() as $failure) {
                $error = strtolower((string) $failure->error);
                if (
                    str_contains($error, 'timeout')
                    || ($failure->metadata['deadline_exceeded'] ?? false) === true
                ) {
                    $timeouts++;
                }
            }
        } while (hrtime(true) < $deadline);

        return [
            'requests' => $requestsCompleted,
            'errors' => $errors,
            'timeouts' => $timeouts,
            'batch_latency_ms' => $latencies,
            'max_resource_count' => $maxResourceCount,
        ];
    }

    /** @param list<float|int> $values */
    private static function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (float) $values[max(0, min(count($values) - 1, $index))];
    }

    /** @return array<string, mixed> */
    private function runConcurrency(int $concurrency): array
    {
        $trials = [];
        $batchLatencies = [];

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $result = $this->runTrial($trial, $concurrency);
            $trials[] = $result;
            array_push($batchLatencies, ...$result['batch_latency_ms']);
        }

        $rpms = array_column($trials, 'rpm');
        sort($rpms, SORT_NUMERIC);

        return [
            'concurrency' => $concurrency,
            'batch_size' => $concurrency * self::BATCH_MULTIPLIER,
            'median_rpm' => self::percentile($rpms, 50),
            'p50_batch_latency_ms' => self::percentile($batchLatencies, 50),
            'p95_batch_latency_ms' => self::percentile($batchLatencies, 95),
            'p99_batch_latency_ms' => self::percentile($batchLatencies, 99),
            'requests' => array_sum(array_column($trials, 'requests')),
            'errors' => array_sum(array_column($trials, 'errors')),
            'timeouts' => array_sum(array_column($trials, 'timeouts')),
            'warmup_requests' => array_sum(array_column($trials, 'warmup_requests')),
            'warmup_errors' => array_sum(array_column($trials, 'warmup_errors')),
            'warmup_timeouts' => array_sum(array_column($trials, 'warmup_timeouts')),
            'max_cpu_percent' => max(array_column($trials, 'cpu_percent')),
            'max_memory_bytes' => max(array_column($trials, 'memory_bytes')),
            'max_resource_count' => max(array_column($trials, 'max_resource_count')),
            'max_resource_delta' => max(array_column($trials, 'resource_delta')),
            'trial_results' => $trials,
        ];
    }

    /** @return array<string, mixed> */
    private function runTrial(int $trial, int $concurrency): array
    {
        [$process, $pipes, $port, $readyPath] = $this->startServer($trial, $concurrency);

        try {
            return $this->executeTrial($port, $concurrency);
        } finally {
            $this->stopServer($process, $pipes, $readyPath);
        }
    }

    /** @return array{0:resource,1:array<int,resource>,2:int,3:string} */
    private function startServer(int $trial, int $concurrency): array
    {
        $readyPath = sprintf(
            '%s/tb-throughput-%d-%d-%d.json',
            sys_get_temp_dir(),
            getmypid(),
            $concurrency,
            $trial,
        );
        if (is_file($readyPath)) {
            unlink($readyPath);
        }

        $process = proc_open(
            [
                PHP_BINARY,
                $this->serverScript,
                '0',
                (string) self::DELAY_MICROSECONDS,
                $readyPath,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start sustained HTTP performance server.');
        }

        fclose($pipes[0]);
        unset($pipes[0]);

        $deadline = microtime(true) + 3.0;
        while (!is_file($readyPath) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $ready = is_file($readyPath)
            ? json_decode((string) file_get_contents($readyPath), true)
            : null;
        $port = is_array($ready) ? ($ready['port'] ?? null) : null;
        if (!is_int($port) || $port < 1) {
            $this->stopServer($process, $pipes, $readyPath);

            throw new RuntimeException('Sustained HTTP performance server did not become ready.');
        }

        return [$process, $pipes, $port, $readyPath];
    }

    /** @param array<int, resource> $pipes */
    private function stopServer(mixed $process, array $pipes, string $readyPath): void
    {
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($process);
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($process);
        }

        if (is_file($readyPath)) {
            unlink($readyPath);
        }
    }
}

$autoloadPath = $argv[1] ?? '';
$serverScript = $argv[2] ?? '';
$outputPath = $argv[3] ?? '';
$mode = $argv[4] ?? '';
$revision = $argv[5] ?? '';

if ($autoloadPath === '' || $serverScript === '' || $outputPath === '' || $revision === '') {
    throw new InvalidArgumentException(
        'Usage: php http-sustained-performance.php <autoload> <server> <output> <mode> <revision>',
    );
}

require $autoloadPath;

$report = (new SustainedHttpPerformance($serverScript, $mode, $revision))->run();
file_put_contents(
    $outputPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
);

foreach ($report['levels'] as $level) {
    printf(
        "%s %s c=%d: %.2f RPM, p95 %.2f ms, errors %d, timeouts %d\n",
        $revision,
        $mode,
        $level['concurrency'],
        $level['median_rpm'],
        $level['p95_batch_latency_ms'],
        $level['errors'],
        $level['timeouts'],
    );
}
