<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class HeaderMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(private array $headers) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $headers = array_merge($request->headers, $this->headers);
        $normalizedHeaders = $this->normalizeHeaders($this->headers);

        if ($request->payload instanceof HttpRequest) {
            return $next(new CommunicationRequest(
                $request->transport,
                $request->payload->headers($normalizedHeaders),
                $headers,
                $request->options,
                $request->metadata,
            ));
        }

        return $next($request->withHeaders($headers));
    }

    /**
     * @param array<string, string|string[]> $headers
     *
     * @return array<string, string|list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[$name] = is_array($value) ? array_values($value) : $value;
        }

        return $normalized;
    }
}
