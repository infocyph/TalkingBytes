<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class SmtpCapabilityParser
{
    /**
     * @param list<string> $responseLines
     */
    public function parse(array $responseLines): SmtpCapabilities
    {
        $values = [];
        $authMechanisms = [];

        foreach ($responseLines as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$name, $tail] = $parsed;

            $values[$name] = $tail;

            if ($name === 'AUTH') {
                $authMechanisms = $this->parseAuthMechanisms($tail);
            }
        }

        return new SmtpCapabilities($values, $authMechanisms);
    }

    /**
     * @return list<string>
     */
    private function parseAuthMechanisms(string $tail): array
    {
        if ($tail === '') {
            return [];
        }

        return preg_split('/\s+/', strtoupper($tail)) ?: [];
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function parseLine(string $line): ?array
    {
        if (preg_match('/^\d{3}[\s-](.+)$/', trim($line), $matches) !== 1) {
            return null;
        }

        $capability = trim($matches[1]);
        if ($capability === '' || str_starts_with(strtoupper($capability), 'HELLO ')) {
            return null;
        }

        [$name, $tail] = $this->splitCapability($capability);
        if (preg_match('/^[A-Z0-9-]+$/', $name) !== 1) {
            return null;
        }

        return [$name, $tail];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCapability(string $line): array
    {
        $parts = preg_split('/\s+/', $line, 2) ?: [];
        $name = strtoupper($parts[0] ?? '');
        $tail = $parts[1] ?? '';

        return [$name, strtoupper(trim($tail))];
    }
}
