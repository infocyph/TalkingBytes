<?php

declare(strict_types=1);

/**
 * @phpstan-type TrialResult array{elapsed_seconds:float}
 * @phpstan-type LevelResult array{
 *     concurrency:int,
 *     median_rpm:float,
 *     p50_batch_latency_ms:float,
 *     p95_batch_latency_ms:float,
 *     p99_batch_latency_ms:float,
 *     errors:int,
 *     timeouts:int,
 *     warmup_errors:int,
 *     warmup_timeouts:int,
 *     max_cpu_percent:float,
 *     max_memory_bytes:int,
 *     max_resource_count:int,
 *     max_resource_delta:int,
 *     trial_results:list<TrialResult>
 * }
 * @phpstan-type PerformanceReport array{
 *     warmup_seconds:float,
 *     steady_state_seconds:float,
 *     trials:int,
 *     levels:list<LevelResult>
 * }
 */
final class SustainedPerformanceComparison
{
    /**
     * @param PerformanceReport $report
     * @return array<int, LevelResult>
     */
    private static function indexLevels(array $report): array
    {
        $indexed = [];
        foreach ($report['levels'] as $level) {
            $indexed[$level['concurrency']] = $level;
        }

        ksort($indexed);

        return $indexed;
    }

    /** @return PerformanceReport */
    private static function loadReport(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid sustained performance report: %s', $path));
        }

        /** @var array<string, mixed> $decoded */
        return self::normalizeReport($decoded);
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function floatValue(array $source, string $key): float
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new RuntimeException(sprintf('Performance field "%s" must be numeric.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function intValue(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('Performance field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @return LevelResult
     */
    private static function normalizeLevel(array $source): array
    {
        $rawTrials = $source['trial_results'] ?? null;
        if (!is_array($rawTrials)) {
            throw new RuntimeException('Performance level is missing trial results.');
        }

        /** @var list<TrialResult> $trials */
        $trials = [];
        foreach ($rawTrials as $rawTrial) {
            if (!is_array($rawTrial)) {
                throw new RuntimeException('Performance trial result must be an object.');
            }

            /** @var array<string, mixed> $rawTrial */
            $trials[] = [
                'elapsed_seconds' => self::floatValue($rawTrial, 'elapsed_seconds'),
            ];
        }

        return [
            'concurrency' => self::intValue($source, 'concurrency'),
            'median_rpm' => self::floatValue($source, 'median_rpm'),
            'p50_batch_latency_ms' => self::floatValue($source, 'p50_batch_latency_ms'),
            'p95_batch_latency_ms' => self::floatValue($source, 'p95_batch_latency_ms'),
            'p99_batch_latency_ms' => self::floatValue($source, 'p99_batch_latency_ms'),
            'errors' => self::intValue($source, 'errors'),
            'timeouts' => self::intValue($source, 'timeouts'),
            'warmup_errors' => self::intValue($source, 'warmup_errors'),
            'warmup_timeouts' => self::intValue($source, 'warmup_timeouts'),
            'max_cpu_percent' => self::floatValue($source, 'max_cpu_percent'),
            'max_memory_bytes' => self::intValue($source, 'max_memory_bytes'),
            'max_resource_count' => self::intValue($source, 'max_resource_count'),
            'max_resource_delta' => self::intValue($source, 'max_resource_delta'),
            'trial_results' => $trials,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return PerformanceReport
     */
    private static function normalizeReport(array $source): array
    {
        $rawLevels = $source['levels'] ?? null;
        if (!is_array($rawLevels)) {
            throw new RuntimeException('Sustained performance report has no concurrency levels.');
        }

        /** @var list<LevelResult> $levels */
        $levels = [];
        foreach ($rawLevels as $rawLevel) {
            if (!is_array($rawLevel)) {
                throw new RuntimeException('Sustained performance level must be an object.');
            }

            /** @var array<string, mixed> $rawLevel */
            $levels[] = self::normalizeLevel($rawLevel);
        }

        return [
            'warmup_seconds' => self::floatValue($source, 'warmup_seconds'),
            'steady_state_seconds' => self::floatValue($source, 'steady_state_seconds'),
            'trials' => self::intValue($source, 'trials'),
            'levels' => $levels,
        ];
    }

    /**
     * @param PerformanceReport $report
     */
    private static function validateMeasurementShape(array $report): void
    {
        if ($report['warmup_seconds'] < 1.0) {
            throw new RuntimeException('Sustained performance warm-up must be at least one second.');
        }
        if ($report['steady_state_seconds'] < 5.0) {
            throw new RuntimeException('Sustained performance steady state must be at least five seconds.');
        }
        if ($report['trials'] < 3) {
            throw new RuntimeException('Sustained performance requires at least three trials per concurrency level.');
        }

        $levels = self::indexLevels($report);
        if (array_keys($levels) !== [5, 20, 50]) {
            throw new RuntimeException('Sustained performance must exercise concurrency levels 5, 20, and 50.');
        }

        foreach ($levels as $level) {
            if (count($level['trial_results']) < 3) {
                throw new RuntimeException('Sustained performance level is missing repeated trial evidence.');
            }

            foreach ($level['trial_results'] as $trial) {
                if ($trial['elapsed_seconds'] < $report['steady_state_seconds']) {
                    throw new RuntimeException(
                        'Sustained performance trial did not complete the full steady-state window.',
                    );
                }
            }
        }
    }

    public static function run(
        string $baselinePath,
        string $candidatePath,
        string $runwirePath,
        float $maxRegression,
    ): void {
        $baseline = self::loadReport($baselinePath);
        $candidate = self::loadReport($candidatePath);
        $runwire = self::loadReport($runwirePath);

        self::validateMeasurementShape($baseline);
        self::validateMeasurementShape($candidate);
        self::validateMeasurementShape($runwire);

        $baselineLevels = self::indexLevels($baseline);
        $candidateLevels = self::indexLevels($candidate);
        $runwireLevels = self::indexLevels($runwire);

        /** @var list<string> $failures */
        $failures = [];
        /** @var list<string> $summaryRows */
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

            $baselineRpm = $baselineLevel['median_rpm'];
            $candidateRpm = $candidateLevel['median_rpm'];
            $runwireRpm = $runwireLevel['median_rpm'];
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
                $errors = $level['errors'] + $level['warmup_errors'];
                $timeouts = $level['timeouts'] + $level['warmup_timeouts'];
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
                    $level['median_rpm'],
                    $level['p50_batch_latency_ms'],
                    $level['p95_batch_latency_ms'],
                    $level['p99_batch_latency_ms'],
                    $errors,
                    $timeouts,
                    $level['max_cpu_percent'],
                    $level['max_memory_bytes'],
                    $level['max_resource_count'],
                    $level['max_resource_delta'],
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

        self::writeSummary($candidate, $summaryRows, $maxRegression);

        if ($failures !== []) {
            throw new RuntimeException(
                "Sustained HTTP performance acceptance failed:\n- " . implode("\n- ", $failures),
            );
        }
    }

    /**
     * @param PerformanceReport $candidate
     * @param list<string> $summaryRows
     */
    private static function writeSummary(array $candidate, array $summaryRows, float $maxRegression): void
    {
        $summaryPath = getenv('GITHUB_STEP_SUMMARY');
        if (!is_string($summaryPath) || $summaryPath === '') {
            return;
        }

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
                $candidate['warmup_seconds'],
                $candidate['steady_state_seconds'],
                $candidate['trials'],
                implode("\n", $summaryRows),
                $maxRegression,
            ),
            FILE_APPEND,
        );
    }
}

$baselinePath = $argv[1] ?? '';
$candidatePath = $argv[2] ?? '';
$runwirePath = $argv[3] ?? '';
$maxRegression = isset($argv[4]) ? (float) $argv[4] : 2.0;

if ($baselinePath === '' || $candidatePath === '' || $runwirePath === '') {
    throw new InvalidArgumentException(
        'Usage: php compare.php <baseline> <candidate-unbound> <candidate-runwire> [max-regression-percent]',
    );
}

SustainedPerformanceComparison::run(
    $baselinePath,
    $candidatePath,
    $runwirePath,
    $maxRegression,
);
