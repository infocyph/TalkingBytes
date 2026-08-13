<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class DkimVerificationReport
{
    /** @param list<DkimVerificationResult> $results */
    public function __construct(public array $results) {}

    public function allValid(): bool
    {
        return $this->results !== []
            && array_all($this->results, static fn(DkimVerificationResult $result): bool => $result->valid);
    }

    public function anyValid(): bool
    {
        return array_any($this->results, static fn(DkimVerificationResult $result): bool => $result->valid);
    }
}
