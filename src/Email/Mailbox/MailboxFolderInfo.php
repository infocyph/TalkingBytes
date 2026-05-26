<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final readonly class MailboxFolderInfo
{
    /**
     * @param list<string> $attributes
     */
    public function __construct(
        public string $name,
        public string $path,
        public ?string $delimiter,
        public array $attributes = [],
    ) {}

    public function hasChildren(): bool
    {
        return in_array('\\HASCHILDREN', $this->attributes, true);
    }

    public function isSelectable(): bool
    {
        return !in_array('\\NOSELECT', $this->attributes, true);
    }

    public function noInferiors(): bool
    {
        return in_array('\\NOINFERIORS', $this->attributes, true);
    }
}
