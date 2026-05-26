<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

interface AttachmentContentResolver
{
    public function contents(): string;

    /**
     * @param resource $stream
     */
    public function streamTo(mixed $stream): void;
}
