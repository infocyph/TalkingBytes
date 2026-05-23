<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\System\HeaderValueGuard;
use InvalidArgumentException;

final readonly class EmailAddress
{
    public function __construct(
        public string $email,
        public ?string $name = null,
    ) {
        HeaderValueGuard::assertNoCrlf($this->email, 'Email');
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $this->email));
        }

        if ($this->name !== null && $this->name !== '') {
            HeaderValueGuard::assertNoCrlf($this->name, 'Display name');
        }
    }

    public static function fromMailbox(string $mailbox): self
    {
        HeaderValueGuard::assertNoCrlf($mailbox, 'Mailbox');

        $pattern = '/^\s*(?:(?<name>[^<]+?)\s*)?<(?<email>[^>]+)>\s*$/';
        if (preg_match($pattern, $mailbox, $matches) === 1) {
            $displayName = trim($matches['name']);
            $displayName = trim($displayName, "\"'");
            $email = trim($matches['email']);

            return new self($email, $displayName !== '' ? $displayName : null);
        }

        return new self(trim($mailbox));
    }
}
