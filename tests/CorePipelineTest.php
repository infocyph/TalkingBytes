<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

it('runs middleware pipeline in order', function (): void {
    $events = [];

    $transport = new class($events) implements TransportInterface {
        /**
         * @param array<int, string> $events
         */
        public function __construct(private array &$events) {}

        public function send(CommunicationRequest $request): CommunicationResult
        {
            unset($request);
            $this->events[] = 'transport';

            return CommunicationResult::success(200);
        }
    };

    $first = new class($events) implements MiddlewareInterface {
        /**
         * @param array<int, string> $events
         */
        public function __construct(private array &$events) {}

        public function handle(CommunicationRequest $request, \Closure $next): CommunicationResult
        {
            $this->events[] = 'first.before';
            $result = $next($request);
            $this->events[] = 'first.after';

            return $result;
        }
    };

    $second = new class($events) implements MiddlewareInterface {
        /**
         * @param array<int, string> $events
         */
        public function __construct(private array &$events) {}

        public function handle(CommunicationRequest $request, \Closure $next): CommunicationResult
        {
            $this->events[] = 'second.before';
            $result = $next($request);
            $this->events[] = 'second.after';

            return $result;
        }
    };

    $pipeline = new MiddlewarePipeline($transport, [$first, $second]);

    $result = $pipeline->send(new CommunicationRequest('test', ['hello' => 'world']));

    expect($result->successful)->toBeTrue();
    expect($events)->toBe([
        'first.before',
        'second.before',
        'transport',
        'second.after',
        'first.after',
    ]);
});
