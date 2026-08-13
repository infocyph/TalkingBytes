<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class ResilienceBench
{
    private CircuitBreaker $breaker;

    private RateLimiter $limiter;

    public function setUp(): void
    {
        $clock = Clock::fixed(1000.0);
        $this->breaker = new CircuitBreaker(clock: $clock);
        $this->limiter = new RateLimiter(100_000, 1, $clock);
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchClosedCircuit(): void
    {
        $this->breaker->assertCanProceed();
    }

    #[Iterations(5)]
    #[Revs(1000)]
    public function benchTokenBucket(): void
    {
        $this->limiter->assertCanProceed();
    }
}
