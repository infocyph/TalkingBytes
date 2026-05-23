<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedEmail;

final readonly class SpoolEmailReceiver implements EmailReceiver
{
    public function __construct(
        private SpoolConfig $config,
        private bool $deleteAfterRead = false,
    ) {}

    public function receive(): ?ReceivedEmail
    {
        $directory = rtrim($this->config->directory, '/\\');

        if (!is_dir($directory)) {
            return null;
        }

        $files = glob($directory . '/*.eml');
        if ($files === false || $files === []) {
            return null;
        }

        sort($files);
        $file = $files[0];

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        [$headerBlock, $body] = $this->splitRawMessage($raw);
        $headers = $this->parseHeaders($headerBlock);

        if ($this->deleteAfterRead) {
            unlink($file);
        }

        return new ReceivedEmail(
            $headers,
            $body,
            $raw,
            [
                'source' => 'spool',
                'path' => $file,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $lines = preg_split('/\r\n/', $headerBlock) ?: [];
        $headers = [];
        $lastHeaderName = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (($line[0] === ' ' || $line[0] === "\t") && $lastHeaderName !== null) {
                $headers[$lastHeaderName] .= ' ' . trim($line);

                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            $headers[$name] = trim($value);
            $lastHeaderName = $name;
        }

        return $headers;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitRawMessage(string $raw): array
    {
        $parts = preg_split("/\r\n\r\n/", $raw, 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
