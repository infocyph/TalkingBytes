<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use InvalidArgumentException;

final class ResolvedConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public static function bool(array $config, string $key, bool $default, string $context): bool
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s resolved configuration key "%s" must be a boolean.',
            $context,
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function float(array $config, string $key, float $default, string $context): float
    {
        $value = $config[$key] ?? $default;
        if (is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidArgumentException(sprintf(
            '%s resolved configuration key "%s" must be numeric.',
            $context,
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function int(array $config, string $key, int $default, string $context): int
    {
        $value = $config[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s resolved configuration key "%s" must be an integer.',
            $context,
            $key,
        ));
    }

    public static function nullableInt(mixed $value, string $key, string $context): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if (is_int($parsed)) {
                return $parsed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '%s resolved configuration key "%s" must be an integer or null.',
            $context,
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function section(
        array $config,
        string $key,
        string $context,
        bool $required = false,
    ): array {
        $value = $config[$key] ?? null;
        if ($value === null && !$required) {
            return [];
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s resolved configuration section "%s" must be an array.',
                $context,
                $key,
            ));
        }

        $section = [];
        foreach ($value as $name => $item) {
            if (is_string($name)) {
                $section[$name] = $item;
            }
        }

        if ($required && $section === []) {
            throw new InvalidArgumentException(sprintf(
                '%s resolved configuration section "%s" must not be empty.',
                $context,
                $key,
            ));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    public static function sections(array $config, string $key, string $context): array
    {
        $value = $config[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s resolved configuration section "%s" must be a list.',
                $context,
                $key,
            ));
        }

        $sections = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException(sprintf(
                    '%s resolved configuration section "%s" must contain arrays.',
                    $context,
                    $key,
                ));
            }

            $section = [];
            foreach ($item as $name => $entry) {
                if (is_string($name)) {
                    $section[$name] = $entry;
                }
            }
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function string(
        array $config,
        string $key,
        string $default,
        string $context,
        bool $required = false,
    ): string {
        $value = $config[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                '%s resolved configuration key "%s" must be a string.',
                $context,
                $key,
            ));
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArgumentException(sprintf(
                '%s resolved configuration key "%s" must be non-empty.',
                $context,
                $key,
            ));
        }

        return $value;
    }
}
