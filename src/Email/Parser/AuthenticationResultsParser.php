<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\AuthenticationCheckResult;
use Infocyph\TalkingBytes\Email\ValueObject\AuthenticationResults;

final class AuthenticationResultsParser
{
    public function parse(?string $headerValue): AuthenticationResults
    {
        $value = trim((string) $headerValue);
        if ($value === '') {
            return new AuthenticationResults([], null, ['Authentication-Results header missing.']);
        }

        $segments = preg_split('/\s*;\s*/', $value) ?: [];
        $authservId = array_shift($segments);

        $checks = [];

        foreach ($segments as $segment) {
            $parsed = $this->parseCheckSegment($segment);
            if ($parsed !== null) {
                $checks[] = $parsed;
            }
        }

        return new AuthenticationResults($checks, $authservId !== '' ? $authservId : null, raw: $value);
    }

    private function parseCheckSegment(string $segment): ?AuthenticationCheckResult
    {
        if ($segment === '' || !str_contains($segment, '=')) {
            return null;
        }

        [$method, $rest] = explode('=', $segment, 2);
        $method = strtolower(trim($method));
        if ($method === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($rest)) ?: [];
        $result = strtolower(array_shift($tokens) ?? 'none');
        $properties = $this->parseProperties($tokens);

        return new AuthenticationCheckResult(
            $method,
            $result,
            $properties['header.d'] ?? null,
            $properties['header.s'] ?? null,
            $properties['smtp.mailfrom'] ?? null,
            $properties,
        );
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function parseProperties(array $tokens): array
    {
        $properties = [];

        foreach ($tokens as $token) {
            if (!str_contains($token, '=')) {
                continue;
            }

            [$name, $tokenValue] = explode('=', $token, 2);
            $properties[strtolower(trim($name))] = trim($tokenValue, '"');
        }

        return $properties;
    }
}
