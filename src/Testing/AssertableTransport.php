<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Testing;

use LogicException;

final readonly class AssertableTransport
{
    public function __construct(private SpyTransport $spyTransport) {}

    public function assertSentCount(int $expected): void
    {
        $count = count($this->spyTransport->requests());

        if ($count !== $expected) {
            throw new LogicException(sprintf('Expected %d sent requests, got %d.', $expected, $count));
        }
    }
}
