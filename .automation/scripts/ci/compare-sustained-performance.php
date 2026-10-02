<?php

declare(strict_types=1);

/** @return array<int, array<string, mixed>> */
function indexLevels(array $report): array
{
    $indexed = [];
    $levels = $report['levels'] ?? null;
    if (!is_array($levels)) {
        throw new RuntimeException('Sustained performance report has no concurrency levels.');
    }

    foreach ($levels as $level) {
        if (!is_array($level) || !is_int($level['concurrency'] ?? null)) {
            throw new RuntimeException('Sustained performance report contains an invalid concurrency level.');
        }

        $indexed[$level['concurrency']] = $level;
    }

    ksort($indexed);

    return $indexed;
}

/** @return array<string, mixed> */
function loadReport(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Invalid sustained performance report: %s', $path));
    }

    return $decoded;
}

/** @param array<string, mixed> $report */
function validateMeasurementShape(array $report): void
{
    if ((float) ($report['warmup_seconds'] ?? 0.0) < 1.0) {
        throw new RuntimeException('Sustained performance warm-up must be at least one second.');
    }
    if ((float) ($report['steady_state_seconds'] ?? 0.0) < 5.0) {
        throw new RuntimeException('Sustained performance steady state must be at least five seconds.');
    }
    if ((int) ($report['trials'] ?? 0) < 2) {
        throw new RuntimeException('Sustained performance requires at least two trials per concurrency level.');
    }

    $levels = indexLevels($report);
    if (array_keys($levels) !== [5, 20, 50]) {
        throw new RuntimeException('Sustained performance must exercise concurrency levels 5, 20, and 50.');
    }

    $minimumElapsed = (float) $report['steady_state_seconds'];
    foreach ($levels as $level) {
        $trials = $level['trial_results'] ?? null;
        if (!is_array($trials) || count($trials) < 2) {
            throw new RuntimeException('Sustained performance level is missing repeated trial evidence.');
        }

        foreach ($trials as $trial) {
            if (
                !is_array($trial)
                || (float) ($trial['elapsed_seconds'] ?? 0.0) < $minimumElapsed
            ) {
                throw new RuntimeException(
                    'Sustained performance trial did not complete the full steady-state window.',
                );
            }
        }
    }
}

/** @param array<string, mixed> $level */
function warmupErrors(array $level): int
{
    return (int) ($level['warmup_errors'] ?? 0);
}

/** @param array<string, mixed> $level */
function warmupTimeouts(array $level): int
{
    return (int) ($level['warmup_timeouts'] ?? 0);
}

$baseline = loadReport($argv[1] ?? '');
$candidate = loadReport($argv[2] ?? '');
$runwire = loadReport($argv[3] ?? '');
$maxRegression = (float) ($argv[4] ?? 2.0);

validateMeasurementShape($baseline);
validateMeasurementShape($candidate);
validateMeasurementShape($runwire);

$baselineLevels = indexLevels($baseline);
$candidateLevels = indexLevels($candidate);
$runwireLevels = indexLevels($runwire);
$failures = [];
$summaryRows = [];

foreach ($candidateLevels as $concurrency => $candidateLevel) {
    $baselineLevel = $baselineLevels[$concurrency] ?? null;
    $runwireLevel = $runwireLevels[$concurrency] ?? null;
    if ($baselineLevel === null || $runwireLevel === null) {
        throw new RuntimeException(sprintf(
            'Missing sustained performance evidence at concurrency %d.',
            $concurrency,
        ));
    }

    $baselineRpm = (float) ($baselineLevel['median_rpm'] ?? 0.0);
    $candidateRpm = (float) ($candidateLevel['median_rpm'] ?? 0.0);
    $runwireRpm = (float) ($runwireLevel['median_rpm'] ?? 0.0);
    if ($baselineRpm <= 0.0 || $candidateRpm <= 0.0 || $runwireRpm <= 0.0) {
        throw new RuntimeException(sprintf(
            'Sustained performance RPM must be positive at concurrency %d.',
            $concurrency,
        ));
    }

    $regressionPercent = (($baselineRpm - $candidateRpm) / $baselineRpm) * 100;
    $runwireDeltaPercent = (($runwireRpm - $candidateRpm) / $candidateRpm) * 100;

    printf(
        "c=%d: 2.2 %.2f RPM; 2.3 unbound %.2f RPM (%+.2f%%); Runwire %.2f RPM (%+.2f%% vs unbound)\n",
        $concurrency,
        $baselineRpm,
        $candidateRpm,
        -$regressionPercent,
        $runwireRpm,
        $runwireDeltaPercent,
    );

    foreach ([
        '2.2 baseline' => $baselineLevel,
        '2.3 unbound' => $candidateLevel,
        '2.3 Runwire' => $runwireLevel,
    ] as $label => $level) {
        $errors = (int) ($level['errors'] ?? 0) + warmupErrors($level);
        $timeouts = (int) ($level['timeouts'] ?? 0) + warmupTimeouts($level);
        if ($errors > 0 || $timeouts > 0) {
            $failures[] = sprintf(
                '%s c=%d had %d errors and %d timeouts including warm-up',
                $label,
                $concurrency,
                $errors,
                $timeouts,
            );
        }

        $summaryRows[] = sprintf(
            '| %d | %s | %.2f | %.2f | %.2f | %.2f | %d | %d | %.2f | %d | %d | %d |',
            $concurrency,
            $label,
            (float) ($level['median_rpm'] ?? 0.0),
            (float) ($level['p50_batch_latency_ms'] ?? 0.0),
            (float) ($level['p95_batch_latency_ms'] ?? 0.0),
            (float) ($level['p99_batch_latency_ms'] ?? 0.0),
            $errors,
            $timeouts,
            (float) ($level['max_cpu_percent'] ?? 0.0),
            (int) ($level['max_memory_bytes'] ?? 0),
            (int) ($level['max_resource_count'] ?? 0),
            (int) ($level['max_resource_delta'] ?? 0),
        );
    }

    if ($regressionPercent > $maxRegression) {
        $failures[] = sprintf(
            'c=%d RPM regression %.2f%% exceeds %.2f%% limit',
            $concurrency,
            $regressionPercent,
            $maxRegression,
        );
    }

    $summaryRows[] = sprintf(
        '| %d | comparison | %+.2f%% vs 2.2 | - | - | - | - | - | - | - | - | - |',
        $concurrency,
        -$regressionPercent,
    );
    $summaryRows[] = sprintf(
        '| %d | Runwire delta | %+.2f%% vs 2.3 unbound | - | - | - | - | - | - | - | - | - |',
        $concurrency,
        $runwireDeltaPercent,
    );
}

$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
    file_put_contents(
        $summaryPath,
        sprintf(
            "### Sustained HTTP performance\n\n"
            . "Warm-up: %.1f s; measured steady state: %.1f s; trials per level: %d.\n\n"
            . "| Concurrency | Mode | Median RPM | p50 batch ms | p95 batch ms | p99 batch ms | "
            . "Errors | Timeouts | Max CPU %% | Max memory bytes | Max resources | Resource delta |\n"
            . "| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n"
            . "%s\n\n"
            . "The %.2f%% regression limit is enforced independently for 2.2 → 2.3 unbound "
            . "at every concurrency level. Runwire is characterized separately.\n",
            (float) $candidate['warmup_seconds'],
            (float) $candidate['steady_state_seconds'],
            (int) $candidate['trials'],
            implode("\n", $summaryRows),
            $maxRegression,
        ),
        FILE_APPEND,
    );
}

if ($failures !== []) {
    throw new RuntimeException(
        "Sustained HTTP performance acceptance failed:\n- " . implode("\n- ", $failures),
    );
}
