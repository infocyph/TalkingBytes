<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, InboundEmailAddress>
 */
final readonly class EmailAddressList implements Countable, IteratorAggregate
{
    /**
     * @param list<InboundEmailAddress> $addresses
     */
    public function __construct(private array $addresses = []) {}

    /**
     * @return list<InboundEmailAddress>
     */
    public function all(): array
    {
        return $this->addresses;
    }

    public function count(): int
    {
        return count($this->addresses);
    }

    /**
     * @return Traversable<int, InboundEmailAddress>
     */
    public function getIterator(): Traversable
    {
        yield from $this->addresses;
    }
}
