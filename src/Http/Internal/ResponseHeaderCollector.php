<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use InvalidArgumentException;

final class ResponseHeaderCollector
{
    /**
     * @var array<string, string|list<string>>
     */
    private array $activeHeaders = [];

    private ?string $error = null;

    private int $fieldCount = 0;

    /**
     * @var array<string, string|list<string>>
     */
    private array $headers = [];

    private int $receivedBytes = 0;

    private ?int $statusCode = null;

    public function __construct(
        private readonly ?int $maxBytes = null,
        private readonly ?int $maxFields = null,
    ) {
        if ($this->maxBytes !== null && $this->maxBytes < 1) {
            throw new InvalidArgumentException('HTTP response header byte limit must be greater than zero.');
        }
        if ($this->maxFields !== null && $this->maxFields < 1) {
            throw new InvalidArgumentException('HTTP response header field limit must be greater than zero.');
        }
    }

    public function collect(string $line): int
    {
        $length = strlen($line);
        $this->receivedBytes += $length;
        if ($this->maxBytes !== null && $this->receivedBytes > $this->maxBytes) {
            $this->error = sprintf(
                'HTTP response headers exceeded max allowed bytes (%d).',
                $this->maxBytes,
            );

            return 0;
        }

        $trimmed = trim($line);

        if ($trimmed === '') {
            if ($this->activeHeaders !== []) {
                $this->headers = $this->activeHeaders;
            }

            return $length;
        }

        if (str_starts_with($trimmed, 'HTTP/')) {
            $this->activeHeaders = [];
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $trimmed, $matches) === 1) {
                $this->statusCode = (int) $matches[1];
            }

            return $length;
        }

        $position = strpos($line, ':');
        if ($position === false) {
            return $length;
        }

        $this->fieldCount++;
        if ($this->maxFields !== null && $this->fieldCount > $this->maxFields) {
            $this->error = sprintf(
                'HTTP response headers exceeded max allowed fields (%d).',
                $this->maxFields,
            );

            return 0;
        }

        $name = strtolower(trim(substr($line, 0, $position)));
        $value = trim(substr($line, $position + 1));

        if (!isset($this->activeHeaders[$name])) {
            $this->activeHeaders[$name] = $value;

            return $length;
        }

        $existing = $this->activeHeaders[$name];
        if (is_array($existing)) {
            $existing[] = $value;
            $this->activeHeaders[$name] = $existing;

            return $length;
        }

        $this->activeHeaders[$name] = [$existing, $value];

        return $length;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }
}
