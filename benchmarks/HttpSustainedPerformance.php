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
    private const BATCH_SIZE = 40;
    private const CONCURRENCY = 20;
    private const DELAY_MICROSECONDS = 10_000;
    private const REQUESTS_PER_TRIAL = 1_200;
    private const TRIALS = 5;

    public function __construct(
        private readonly string $serverScript,
        private readonly string $mode,
        private readonly string $revision,
    ) {}

    /** @return array<string, mixed> */
    public function run(): array
    {
        $trials = [];
        $batchLatencies = [];

        for ($trial = 0; $trial < self::TRIALS; $trial++) {
            $result = $this->runTrial($trial);
            $trials[] = $result;
            array_push($batchLatencies, ...$result['batch_latency_ms']);
        }

        $rpms = array_column($trials, 'rpm');
        sort($rpms, SORT_NUMERIC);

        return [
            'revision' => $this->revision,
            'mode' => $this->mode,
            'trials' => self::TRIALS,
            'requests_per_trial' => self::REQUESTS_PER_TRIAL,
            'batch_size' => self::BATCH_SIZE,
            'concurrency' => self::CONCURRENCY,
            'server_delay_us' => self::DELAY_MICROSECONDS,
            'median_rpm' => self::percentile($rpms, 50),
            'p50_batch_latency_ms' => self::percentile($batchLatencies, 50),
            'p95_batch_latency_ms' => self::percentile($batchLatencies, 95),
            'p99_batch_latency_ms' => self::percentile($batchLatencies, 99),
            'errors' => array_sum(array_column($trials, 'errors')),
            'timeouts' => array_sum(array_column($trials, 'timeouts')),
            'max_cpu_percent' => max(array_column($trials, 'cpu_percent')),
            'max_memory_bytes' => max(array_column($trials, 'memory_bytes')),
            'max_resource_delta' => max(array_column($trials, 'resource_delta')),
            'trial_results' => $trials,
        ];
    }

    /** @return array<string, mixed> */
    private function executeBatches(RequestPool $pool, int $port): array
    {
        $latencies = [];
        $errors = 0;
        $timeouts = 0;
        $requestNumber = 0;

        while ($requestNumber < self::REQUESTS_PER_TRIAL) {
            $requests = [];
            for ($offset = 0; $offset < self::BATCH_SIZE; $offset++) {
                $requestNumber++;
                $requests[$requestNumber] = HttpRequest::get(sprintf(
                    'http://127.0.0.1:%d/bench/%d',
                    $port,
                    $requestNumber,
                ));
            }

            $startedAt = hrtime(true);
            $result = $pool->sendMany($requests);
            $latencies[] = (hrtime(true) - $startedAt) / 1_000_000;
            $errors += $result->failedCount();

            foreach ($result->failed() as $failure) {
                $error = strtolower((string) $failure->error);
                if (str_contains($error, 'timeout') || ($failure->metadata['deadline_exceeded'] ?? false) === true) {
                    $timeouts++;
                }
            }
        }

        return [
            'batch_latency_ms' => $latencies,
            'errors' => $errors,
            'timeouts' => $timeouts,
        ];
    }

    /** @return array<string, mixed> */
    private function executeMode(int $port): array
    {
        if ($this->mode === 'unbound') {
            return $this->executeBatches(HttpClient::multi(self::CONCURRENCY), $port);
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
            function (CoroutineScope $scope) use ($runtime, $port): array {
                $pool = HttpClient::multi(self::CONCURRENCY)
                    ->withRunwire($runtime, scope: $scope);

                return $this->executeBatches($pool, $port);
            },
        );
    }

    /** @return array<string, float|int|list<float>> */
    private function runTrial(int $trial): array
    {
        [$process, $pipes, $port, $readyPath] = $this->startServer($trial);
        $resourcesBefore = count(get_resources());
        $usageBefore = getrusage();
        $startedAt = hrtime(true);

        try {
            $result = $this->executeMode($port);
        } finally {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
            $usageAfter = getrusage();
            $resourcesAfter = count(get_resources());
            $this->stopServer($process, $pipes, $readyPath);
        }

        $cpuSeconds = self::cpuSeconds($usageAfter) - self::cpuSeconds($usageBefore);

        return [
            ...$result,
            'elapsed_seconds' => $elapsedSeconds,
            'rpm' => self::REQUESTS_PER_TRIAL / $elapsedSeconds * 60,
            'cpu_percent' => $elapsedSeconds > 0.0 ? ($cpuSeconds / $elapsedSeconds) * 100 : 0.0,
            'memory_bytes' => memory_get_peak_usage(true),
            'resource_delta' => $resourcesAfter - $resourcesBefore,
        ];
    }

    /** @return array{0:resource,1:array<int,resource>,2:int,3:string} */
    private function startServer(int $trial): array
    {
        $readyPath = sys_get_temp_dir() . '/tb-throughput-' . getmypid() . '-' . $trial . '.json';
        @unlink($readyPath);

        $process = proc_open(
            [
                PHP_BINARY,
                $this->serverScript,
                (string) self::REQUESTS_PER_TRIAL,
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
        $deadline = microtime(true) + 2.0;
        while (is_resource($process) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                break;
            }

            usleep(10_000);
        }

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

        @unlink($readyPath);
    }

    /** @param array<string, int> $usage */
    private static function cpuSeconds(array $usage): float
    {
        return (($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_sec'] ?? 0))
            + (($usage['ru_utime.tv_usec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0)) / 1_000_000;
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
}

$autoloadPath = $argv[1] ?? '';
$serverScript = $argv[2] ?? '';
$outputPath = $argv[3] ?? '';
$mode = $argv[4] ?? '';
$revision = $argv[5] ?? '';

if ($autoloadPath === '' || $serverScript === '' || $outputPath === '' || $revision === '') {
    fwrite(STDERR, "Usage: php HttpSustainedPerformance.php <autoload> <server> <output> <mode> <revision>\n");
    exit(2);
}

require $autoloadPath;

$report = (new SustainedHttpPerformance($serverScript, $mode, $revision))->run();
file_put_contents(
    $outputPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL,
);
printf(
    "%s %s: %.2f RPM, p95 %.2f ms, errors %d, timeouts %d\n",
    $revision,
    $mode,
    $report['median_rpm'],
    $report['p95_batch_latency_ms'],
    $report['errors'],
    $report['timeouts'],
);
