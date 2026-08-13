<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpPipeline;
use Infocyph\TalkingBytes\Http\HttpRequest;

it('runs the typed HTTP middleware pipeline in order', function (): void {
    $events = [];
    $transport = new class($events) implements HttpTransport {
        /** @param list<string> $events */
        public function __construct(private array &$events) {}
        public function send(HttpRequest $request): CommunicationResult
        {
            $this->events[] = 'transport';

            return CommunicationResult::success(response: $request);
        }
    };
    $middleware = static function (string $name, array &$events): HttpMiddleware {
        return new class($name, $events) implements HttpMiddleware {
            /** @param list<string> $events */
            public function __construct(private string $name, private array &$events) {}
            public function handle(HttpRequest $request, Closure $next): CommunicationResult
            {
                $this->events[] = $this->name . '.before';
                $result = $next($request);
                $this->events[] = $this->name . '.after';

                return $result;
            }
        };
    };

    $pipeline = new HttpPipeline($transport, [
        $middleware('first', $events),
        $middleware('second', $events),
    ]);
    $result = $pipeline->send(HttpRequest::get('https://example.com'));

    expect($result->successful)->toBeTrue()
        ->and($events)->toBe([
            'first.before',
            'second.before',
            'transport',
            'second.after',
            'first.after',
        ]);
});
