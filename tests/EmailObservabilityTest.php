<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Event\CallableEmailEventDispatcher;
use Infocyph\TalkingBytes\Email\Event\EmailEventBus;
use Infocyph\TalkingBytes\Email\Event\NullEmailEventDispatcher;
use Infocyph\TalkingBytes\Email\Logging\Psr3LoggerAdapter;

final class DummyPsrLogger
{
    /**
     * @var list<array{level:string,message:string,context:array<string,mixed>}>
     */
    public array $entries = [];

    /**
     * @param  array<string,mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

it('dispatches global email lifecycle events', function (): void {
    $events = [];
    Email::events(static function (string $event, array $payload) use (&$events): void {
        $events[] = ['event' => $event, 'payload' => $payload];
    });

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.com')
        ->subject('Event')
        ->text('body');

    $result = Emailer::usingNull()->send($message);

    Email::events(null);

    expect($result->successful)->toBeTrue();
    expect($events)->toHaveCount(2);
    expect($events[0]['event'])->toBe('email.send.start');
    expect($events[1]['event'])->toBe('email.send.finish');
});

it('adapts psr-style logger object to callable logger transport', function (): void {
    $logger = new DummyPsrLogger;
    $callable = (new Psr3LoggerAdapter($logger))->toCallable('notice');

    $callable('email.send.start', ['transport' => 'null-email']);

    expect($logger->entries)->toHaveCount(1);
    expect($logger->entries[0]['level'])->toBe('notice');
    expect($logger->entries[0]['message'])->toBe('email.send.start');
});

it('resets event listener and prevents cross-test leakage', function (): void {
    $events = [];
    EmailEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        $events[] = ['event' => $event, 'payload' => $payload];
    });

    EmailEventBus::dispatch('email.send.start', ['transport' => 'null-email']);
    EmailEventBus::listen(null);
    EmailEventBus::dispatch('email.send.finish', ['transport' => 'null-email']);

    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('email.send.start');
});

it('bubbles listener exceptions by design', function (): void {
    EmailEventBus::listen(static function (): void {
        throw new RuntimeException('listener failed');
    });

    expect(fn () => EmailEventBus::dispatch('email.send.start', []))
        ->toThrow(RuntimeException::class, 'listener failed');

    EmailEventBus::listen(null);
});

it('supports dispatcher swapping while keeping static facade convenience', function (): void {
    $events = [];
    EmailEventBus::useDispatcher(new CallableEmailEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        $events[] = ['event' => $event, 'payload' => $payload];
    }));

    EmailEventBus::dispatch('mailbox.command.start', ['command' => 'NOOP']);
    EmailEventBus::useDispatcher(new NullEmailEventDispatcher);
    EmailEventBus::dispatch('mailbox.command.finish', ['command' => 'NOOP']);

    expect($events)->toHaveCount(1);
    expect($events[0]['event'])->toBe('mailbox.command.start');
});
