<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

final readonly class Pop3Mailbox
{
    public function __construct(
        private Pop3Transport $transport,
        private EmailParser $parser = new RawEmailParser(),
    ) {}

    public static function usingConfig(Pop3Config $config): self
    {
        return new self(new Pop3SocketTransport($config));
    }

    public function delete(int $messageNumber): void
    {
        Pop3MessageNumberGuard::assertValid($messageNumber);
        $this->transport->delete($messageNumber);
    }

    public function fetchParsed(int $messageNumber): ParsedEmail
    {
        Pop3MessageNumberGuard::assertValid($messageNumber);
        $raw = $this->fetchRaw($messageNumber);

        return $this->parser->parse($raw, [
            'source' => 'pop3',
            'folder' => 'INBOX',
            'uid' => $messageNumber,
        ]);
    }

    public function fetchRaw(int $messageNumber): string
    {
        Pop3MessageNumberGuard::assertValid($messageNumber);

        return $this->transport->rawMessage($messageNumber);
    }

    /**
     * @return list<MailboxMessageRef>
     */
    public function listMessageRefs(?int $limit = null): array
    {
        $sizes = $this->transport->list();
        $uidls = $this->transport->uidl();

        $refs = [];
        foreach ($sizes as $messageNumber => $size) {
            $refs[] = new MailboxMessageRef(
                uid: $messageNumber,
                sizeBytes: $size,
                externalId: $uidls[$messageNumber] ?? null,
            );
        }

        if ($limit !== null && $limit > 0) {
            return array_slice($refs, 0, $limit);
        }

        return $refs;
    }

    public function logout(): void
    {
        $this->transport->logout();
    }

    public function receiveNewest(): ?ParsedEmail
    {
        $uids = $this->transport->search(MailboxSearch::new()->all());
        if ($uids === []) {
            return null;
        }

        rsort($uids);

        return $this->fetchParsed($uids[0]);
    }

    public function receiveOldest(): ?ParsedEmail
    {
        $uids = $this->transport->search(MailboxSearch::new()->all()->limit(1));
        if ($uids === []) {
            return null;
        }

        return $this->fetchParsed($uids[0]);
    }

    public function reset(): void
    {
        $this->transport->reset();
    }

    public function status(): MailboxStatus
    {
        return $this->transport->status();
    }

    public function transport(): Pop3Transport
    {
        return $this->transport;
    }
}
