<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Exception\MissingMessageDataException;

final readonly class EmailEnvelope
{
    /**
     * @param list<EmailAddress> $to
     * @param list<EmailAddress> $cc
     * @param list<EmailAddress> $bcc
     */
    public function __construct(
        public ?EmailAddress $from = null,
        public ?EmailAddress $returnPath = null,
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
    ) {}

    public function assertCanSend(): void
    {
        if ($this->from === null) {
            throw new MissingMessageDataException('Envelope sender is required before sending.');
        }

        if (count($this->recipients()) === 0) {
            throw new MissingMessageDataException('At least one envelope recipient is required before sending.');
        }
    }

    public function envelopeSender(): ?EmailAddress
    {
        return $this->returnPath ?? $this->from;
    }

    /**
     * @return list<EmailAddress>
     */
    public function recipients(): array
    {
        $emails = [];
        $recipients = [];

        foreach (array_merge($this->to, $this->cc, $this->bcc) as $address) {
            $emailKey = strtolower($address->email);

            if (isset($emails[$emailKey])) {
                continue;
            }

            $emails[$emailKey] = true;
            $recipients[] = $address;
        }

        return $recipients;
    }

    /**
     * @param list<EmailAddress> $bcc
     */
    public function withBcc(array $bcc): self
    {
        return new self($this->from, $this->returnPath, $this->to, $this->cc, $bcc);
    }

    /**
     * @param list<EmailAddress> $cc
     */
    public function withCc(array $cc): self
    {
        return new self($this->from, $this->returnPath, $this->to, $cc, $this->bcc);
    }

    public function withFrom(EmailAddress $from): self
    {
        return new self($from, $this->returnPath, $this->to, $this->cc, $this->bcc);
    }

    public function withReturnPath(?EmailAddress $returnPath): self
    {
        return new self($this->from, $returnPath, $this->to, $this->cc, $this->bcc);
    }

    /**
     * @param list<EmailAddress> $to
     */
    public function withTo(array $to): self
    {
        return new self($this->from, $this->returnPath, $to, $this->cc, $this->bcc);
    }
}
