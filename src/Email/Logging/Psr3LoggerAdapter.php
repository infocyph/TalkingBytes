<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Logging;

use InvalidArgumentException;

final readonly class Psr3LoggerAdapter
{
    public function __construct(private object $logger)
    {
        if (!is_callable([$logger, 'log'])) {
            throw new InvalidArgumentException('Logger must expose a PSR-3 compatible log(string $level, string $message, array $context) method.');
        }
    }

    /**
     * @return callable(string, array<string, mixed>):void
     */
    public function toCallable(string $level = 'info'): callable
    {
        $callback = [$this->logger, 'log'];
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Logger must expose a callable log method.');
        }

        $loggerCallable = \Closure::fromCallable($callback);

        return function (string $event, array $payload = []) use ($level, $loggerCallable): void {
            $loggerCallable($level, $event, $payload);
        };
    }
}
