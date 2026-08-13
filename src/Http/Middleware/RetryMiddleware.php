<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\RetryExecutor;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use InvalidArgumentException;

final readonly class RetryMiddleware implements HttpMiddleware
{
    public function __construct(private RetryPolicy $policy) {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        if (!$this->isRetrySafe($request)) {
            return $next($request);
        }

        if (!$request->hasRepeatableUploadSource()) {
            throw new InvalidArgumentException('Automatic retry requires a repeatable HTTP upload source.');
        }

        return RetryExecutor::run($this->policy, static fn(): CommunicationResult => $next($request));
    }

    private function isRetrySafe(HttpRequest $request): bool
    {
        if (in_array($request->method, [
            HttpMethod::Get,
            HttpMethod::Head,
            HttpMethod::Options,
            HttpMethod::Put,
            HttpMethod::Delete,
        ], true)) {
            return true;
        }

        return $request->headers->has('Idempotency-Key')
            || ($request->metadata['retry_unsafe_method'] ?? false) === true;
    }
}
