<?php

declare(strict_types=1);

/** @return array<string, mixed> */
function loadReport(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Invalid sustained performance report: %s', $path));
    }

    return $decoded;
}

$baseline = loadReport($argv[1] ?? '');
$candidate = loadReport($argv[2] ?? '');
$runwire = loadReport($argv[3] ?? '');
$maxRegression = (float) ($argv[4] ?? 2.0);

$baselineRpm = (float) ($baseline['median_rpm'] ?? 0.0);
$candidateRpm = (float) ($candidate['median_rpm'] ?? 0.0);
$runwireRpm = (float) ($runwire['median_rpm'] ?? 0.0);
if ($baselineRpm <= 0.0 || $candidateRpm <= 0.0 || $runwireRpm <= 0.0) {
    throw new RuntimeException('Sustained performance reports must contain positive median RPM values.');
}

$regressionPercent = (($baselineRpm - $candidateRpm) / $baselineRpm) * 100;
$runwireDeltaPercent = (($runwireRpm - $candidateRpm) / $candidateRpm) * 100;
$errors = (int) ($baseline['errors'] ?? 0)
    + (int) ($candidate['errors'] ?? 0)
    + (int) ($runwire['errors'] ?? 0);
$timeouts = (int) ($baseline['timeouts'] ?? 0)
    + (int) ($candidate['timeouts'] ?? 0)
    + (int) ($runwire['timeouts'] ?? 0);

printf(
    "2.2 baseline: %.2f RPM; candidate unbound: %.2f RPM (%+.2f%% vs baseline); candidate Runwire: %.2f RPM (%+.2f%% vs candidate unbound)\n",
    $baselineRpm,
    $candidateRpm,
    -$regressionPercent,
    $runwireRpm,
    $runwireDeltaPercent,
);
printf(
    "Candidate latency p50/p95/p99: %.2f/%.2f/%.2f ms; errors=%d; timeouts=%d\n",
    (float) ($candidate['p50_batch_latency_ms'] ?? 0.0),
    (float) ($candidate['p95_batch_latency_ms'] ?? 0.0),
    (float) ($candidate['p99_batch_latency_ms'] ?? 0.0),
    $errors,
    $timeouts,
);

$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
    file_put_contents(
        $summaryPath,
        sprintf(
            "### Sustained HTTP performance\n\n"
            . "| Mode | Median RPM | p50 batch ms | p95 batch ms | p99 batch ms | Errors | Timeouts | Max CPU %% | Max memory bytes | Max resource delta |\n"
            . "| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |\n"
            . "| 2.2 baseline | %.2f | %.2f | %.2f | %.2f | %d | %d | %.2f | %d | %d |\n"
            . "| 2.3 unbound | %.2f | %.2f | %.2f | %.2f | %d | %d | %.2f | %d | %d |\n"
            . "| 2.3 Runwire | %.2f | %.2f | %.2f | %.2f | %d | %d | %.2f | %d | %d |\n\n"
            . "Comparable 2.2 → 2.3 unbound RPM regression: **%.2f%%** (limit %.2f%%). "
            . "Runwire-bound RPM delta versus 2.3 unbound: **%+.2f%%**.\n",
            $baselineRpm,
            $baseline['p50_batch_latency_ms'],
            $baseline['p95_batch_latency_ms'],
            $baseline['p99_batch_latency_ms'],
            $baseline['errors'],
            $baseline['timeouts'],
            $baseline['max_cpu_percent'],
            $baseline['max_memory_bytes'],
            $baseline['max_resource_delta'],
            $candidateRpm,
            $candidate['p50_batch_latency_ms'],
            $candidate['p95_batch_latency_ms'],
            $candidate['p99_batch_latency_ms'],
            $candidate['errors'],
            $candidate['timeouts'],
            $candidate['max_cpu_percent'],
            $candidate['max_memory_bytes'],
            $candidate['max_resource_delta'],
            $runwireRpm,
            $runwire['p50_batch_latency_ms'],
            $runwire['p95_batch_latency_ms'],
            $runwire['p99_batch_latency_ms'],
            $runwire['errors'],
            $runwire['timeouts'],
            $runwire['max_cpu_percent'],
            $runwire['max_memory_bytes'],
            $runwire['max_resource_delta'],
            $regressionPercent,
            $maxRegression,
            $runwireDeltaPercent,
        ),
        FILE_APPEND,
    );
}

if ($errors > 0 || $timeouts > 0) {
    fwrite(STDERR, "Sustained HTTP performance run contained failures or timeouts.\n");
    exit(1);
}
if ($regressionPercent > $maxRegression) {
    fwrite(STDERR, sprintf(
        "Sustained HTTP RPM regression %.2f%% exceeds %.2f%% limit.\n",
        $regressionPercent,
        $maxRegression,
    ));
    exit(1);
}
