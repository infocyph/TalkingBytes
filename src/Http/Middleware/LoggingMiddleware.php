<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use Throwable;

final readonly class LoggingMiddleware implements HttpMiddleware
{
    /** @param Closure(string, array<string, mixed>): void $logger */
    public function __construct(private Closure $logger) {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        $this->log('http.request.start', [
            'method' => $request->method->value,
            'url' => HttpRedactor::redactUrl($request->buildUrl()),
        ]);

        try {
            $result = $next($request);
        } catch (Throwable $throwable) {
            $this->log('http.request.finish', ['successful' => false, 'exception' => $throwable::class]);

            throw $throwable;
        }

        $this->log('http.request.finish', [
            'successful' => $result->successful,
            'status_code' => $result->statusCode,
        ]);

        return $result;
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context): void
    {
        try {
            ($this->logger)($event, $context);
        } catch (Throwable) {
            // Observability is best-effort and must not alter communication outcomes.
        }
    }
}
