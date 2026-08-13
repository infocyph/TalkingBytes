<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

final class ResponseHeaderCollector
{
    /**
     * @var array<string, string|list<string>>
     */
    private array $activeHeaders = [];

    /**
     * @var array<string, string|list<string>>
     */
    private array $headers = [];

    private ?int $statusCode = null;

    public function collect(string $line): int
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            if ($this->activeHeaders !== []) {
                $this->headers = $this->activeHeaders;
            }

            return strlen($line);
        }

        if (str_starts_with($trimmed, 'HTTP/')) {
            $this->activeHeaders = [];
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $trimmed, $matches) === 1) {
                $this->statusCode = (int) $matches[1];
            }

            return strlen($line);
        }

        $position = strpos($line, ':');
        if ($position === false) {
            return strlen($line);
        }

        $name = strtolower(trim(substr($line, 0, $position)));
        $value = trim(substr($line, $position + 1));

        if (!isset($this->activeHeaders[$name])) {
            $this->activeHeaders[$name] = $value;

            return strlen($line);
        }

        $existing = $this->activeHeaders[$name];
        if (is_array($existing)) {
            $existing[] = $value;
            $this->activeHeaders[$name] = $existing;

            return strlen($line);
        }

        $this->activeHeaders[$name] = [$existing, $value];

        return strlen($line);
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
