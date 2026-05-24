<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\System\HeaderValueGuard;
use Infocyph\TalkingBytes\Email\System\IdnConverter;
use InvalidArgumentException;

final readonly class EmailAddress
{
    private const string DOMAIN_ASCII_PATTERN = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/';

    private const string LOCAL_PART_ASCII_PATTERN = '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/';

    public string $email;

    public function __construct(
        string $email,
        public ?string $name = null,
    ) {
        HeaderValueGuard::assertNoCrlf($email, 'Email');
        $this->email = $this->normalizeAndValidateEmail($email);

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

    private function assertValidLocalPart(string $localPart, string $originalEmail): void
    {
        if (str_starts_with($localPart, '.') || str_ends_with($localPart, '.') || str_contains($localPart, '..')) {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $originalEmail));
        }

        $isAscii = preg_match('/^[\x00-\x7F]+$/', $localPart) === 1;
        if ($isAscii) {
            if (preg_match(self::LOCAL_PART_ASCII_PATTERN, $localPart) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid email address: %s', $originalEmail));
            }

            return;
        }

        if (preg_match('/[\x00-\x1F\x7F\s<>]/u', $localPart) === 1) {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $originalEmail));
        }
    }

    private function normalizeAndValidateEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $email));
        }

        [$localPart, $domain] = $parts;
        if ($localPart === '' || $domain === '') {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $email));
        }

        $asciiDomain = new IdnConverter()->toAsciiDomain($domain);
        $this->assertValidLocalPart($localPart, $email);

        if (preg_match(self::DOMAIN_ASCII_PATTERN, $asciiDomain) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid email address: %s', $email));
        }

        return sprintf('%s@%s', $localPart, $asciiDomain);
    }
}
