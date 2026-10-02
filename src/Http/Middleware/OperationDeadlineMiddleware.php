<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final readonly class OperationDeadlineMiddleware implements HttpMiddleware
{
    private Clock $clock;

    public function __construct(
        private float $timeoutSeconds,
        ?Clock $clock = null,
    ) {
        if (!is_finite($this->timeoutSeconds) || $this->timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('HTTP operation timeout must be finite and greater than zero.');
        }

        $this->clock = $clock ?? Clock::system();
    }

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        $deadline = $request->operationDeadline()
            ?? OperationDeadline::after($this->timeoutSeconds, $this->clock);
        if ($deadline->expired()) {
            return $this->deadlineExceeded();
        }

        $result = $next($request->withOperationDeadline($deadline));
        if ($deadline->expired() && ($result->metadata['deadline_exceeded'] ?? false) !== true) {
            return $this->deadlineExceeded();
        }

        return $result;
    }

    private function deadlineExceeded(): CommunicationResult
    {
        return CommunicationResult::failure(
            'HTTP operation deadline exceeded.',
            metadata: [
                'deadline_exceeded' => true,
                'transport' => 'http',
            ],
        );
    }
}
