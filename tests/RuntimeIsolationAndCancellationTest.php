<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\RetryExecutor;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Enum\BounceType;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

function runtimeIsolationBounceEmail(): \Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail
{
    $raw = implode("\r\n", [
        'From: postmaster@example.com',
        'To: sender@example.com',
        'Subject: Undelivered Mail Returned to Sender',
        '',
        'Diagnostic-Code: smtp; 552 5.2.2 Mailbox full',
        'Failed recipient: fullbox@example.com',
    ]);

    return (new RawEmailParser())->parse($raw);
}

it('keeps injected runtime events independent from the compatibility static bus', function (): void {
    $globalEvents = [];
    $localEvents = [];

    CommunicationEventBus::listen(static function (string $event) use (&$globalEvents): void {
        $globalEvents[] = $event;
    });

    try {
        $parser = new BounceParser(events: new CallableEventDispatcher(
            static function (string $event) use (&$localEvents): void {
                $localEvents[] = $event;
            },
        ));

        $report = $parser->parse(runtimeIsolationBounceEmail());

        expect($report?->type)->toBe(BounceType::MailboxFull)
            ->and($localEvents)->toBe(['bounce.detected'])
            ->and($globalEvents)->toBe([]);
    } finally {
        CommunicationEventBus::listen(null);
    }
});

it('keeps injected event graphs isolated when Fibers interleave', function (): void {
    $eventsA = [];
    $eventsB = [];
    $email = runtimeIsolationBounceEmail();

    $parserA = new BounceParser(events: new CallableEventDispatcher(
        static function (string $event) use (&$eventsA): void {
            $eventsA[] = $event;
            \Fiber::suspend();
        },
    ));
    $parserB = new BounceParser(events: new CallableEventDispatcher(
        static function (string $event) use (&$eventsB): void {
            $eventsB[] = $event;
        },
    ));

    $fiber = new \Fiber(static fn() => $parserA->parse($email));
    $fiber->start();

    expect($fiber->isSuspended())->toBeTrue();

    $reportB = $parserB->parse($email);
    $fiber->resume();

    expect($reportB?->type)->toBe(BounceType::MailboxFull)
        ->and($fiber->isTerminated())->toBeTrue()
        ->and($eventsA)->toBe(['bounce.detected'])
        ->and($eventsB)->toBe(['bounce.detected']);
});

it('interrupts retry waiting when cancellation is requested', function (): void {
    $attempts = 0;
    $sleptMicroseconds = [];
    $sleeper = new Sleeper(static function (int $microseconds) use (&$sleptMicroseconds): void {
        $sleptMicroseconds[] = $microseconds;
    });
    $cancellation = new CancellationSignal(static function () use (&$attempts): bool {
        return $attempts >= 1;
    });

    $result = RetryExecutor::run(
        new FixedDelayRetryPolicy(3, 250),
        static function () use (&$attempts): CommunicationResult {
            $attempts++;

            return CommunicationResult::failure('temporary', 503);
        },
        $sleeper,
        $cancellation,
    );

    expect($result->successful)->toBeFalse()
        ->and($result->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($result->metadata['attempts'] ?? null)->toBe(1)
        ->and($attempts)->toBe(1)
        ->and($sleptMicroseconds)->toBe([]);
});

it('checks cancellation between bounded sleep slices', function (): void {
    $checks = 0;
    $slices = [];
    $sleeper = new Sleeper(static function (int $microseconds) use (&$slices): void {
        $slices[] = $microseconds;
    });
    $cancellation = new CancellationSignal(static function () use (&$checks): bool {
        $checks++;

        return $checks >= 3;
    });

    $completed = $sleeper->millisecondsInterruptibly(500, $cancellation, 50);

    expect($completed)->toBeFalse()
        ->and($slices)->toBe([50_000, 50_000]);
});
