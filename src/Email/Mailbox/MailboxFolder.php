<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;
use Infocyph\TalkingBytes\Email\Parser\AttachmentExtractor;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\HeaderParser as MessageHeaderParser;
use Infocyph\TalkingBytes\Email\Parser\MimeParser;
use Infocyph\TalkingBytes\Email\Parser\MimePartParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\ValueObject\ImapPartAttachmentContentResolver;
use Infocyph\TalkingBytes\Email\ValueObject\InMemoryAttachmentContentResolver;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmailPart;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedAttachment;

final readonly class MailboxFolder
{
    public function __construct(
        private string $name,
        private MailboxTransport $transport,
        private EmailParser $parser,
        private EmailLimits $limits = new EmailLimits(),
    ) {
        MailboxFolderNameGuard::assertValid($name);
    }

    public function addFlag(int $uid, string $flag): void
    {
        MailboxUidGuard::assertValid($uid);
        $this->transport->addFlag($this->name, $uid, $flag);
    }

    /**
     * @param list<int> $uids
     */
    public function addFlagMany(array $uids, string $flag): void
    {
        foreach ($uids as $uid) {
            $this->addFlag($uid, $flag);
        }
    }

    public function close(): void
    {
        $this->transport->close($this->name);
    }

    public function copy(int $uid, string $targetFolder): void
    {
        MailboxUidGuard::assertValid($uid);
        MailboxFolderNameGuard::assertValid($targetFolder);
        $this->transport->copy($this->name, $uid, $targetFolder);
    }

    /**
     * @param list<int> $uids
     */
    public function copyMany(array $uids, string $targetFolder): void
    {
        foreach ($uids as $uid) {
            $this->copy($uid, $targetFolder);
        }
    }

    public function delete(int $uid): void
    {
        MailboxUidGuard::assertValid($uid);
        $this->transport->delete($this->name, $uid);
    }

    /**
     * @param list<int> $uids
     */
    public function deleteMany(array $uids): void
    {
        foreach ($uids as $uid) {
            $this->delete($uid);
        }
    }

    public function expunge(): void
    {
        $this->transport->expunge($this->name);
    }

    /**
     * @return list<ReceivedAttachment>
     */
    public function fetchAttachments(int $uid): array
    {
        MailboxUidGuard::assertValid($uid);
        if ($this->transport instanceof BodyStructureMailboxTransport) {
            $structure = $this->transport->bodyStructure($this->name, $uid);
            if ($structure !== '' && !str_contains(strtoupper($structure), '"ATTACHMENT"') && !str_contains(strtoupper($structure), '"INLINE"')) {
                return [];
            }
        }

        if (!$this->transport instanceof RawPartMailboxTransport) {
            return $this->fetchParsed($uid)->attachments;
        }

        $resolverFactory = function (ParsedEmailPart $part) use ($uid) {
            if ($part->partNumber === null || $part->partNumber === '') {
                return new InMemoryAttachmentContentResolver($part->body);
            }

            $encoding = $part->headers['content-transfer-encoding'][0] ?? null;

            return new ImapPartAttachmentContentResolver(
                $part->partNumber,
                $encoding,
                function () use ($uid, $part): string {
                    /** @var string $content */
                    $content = $this->transport->rawPart($this->name, $uid, $part->partNumber);

                    return $content;
                },
            );
        };

        $parser = new RawEmailParser(
            headerParser: new MessageHeaderParser(),
            mimeParser: new MimeParser(new MimePartParser()),
            attachmentExtractor: new AttachmentExtractor($resolverFactory),
            limits: $this->limits,
        );

        return $parser->parse($this->fetchRaw($uid), [
            'source' => 'imap',
            'folder' => $this->name,
            'uid' => $uid,
        ])->attachments;
    }

    public function fetchBodyStructure(int $uid): string
    {
        MailboxUidGuard::assertValid($uid);
        if (!$this->transport instanceof BodyStructureMailboxTransport) {
            return '';
        }

        return $this->transport->bodyStructure($this->name, $uid);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fetchHeaders(int $uid): array
    {
        MailboxUidGuard::assertValid($uid);
        if ($this->transport instanceof RawHeadersMailboxTransport) {
            return $this->transport->rawHeaders($this->name, $uid);
        }

        return MailboxRawHeaderMap::fromRawMessage($this->fetchRaw($uid));
    }

    public function fetchParsed(int $uid): ParsedEmail
    {
        MailboxUidGuard::assertValid($uid);
        $raw = $this->fetchRaw($uid);

        return $this->parser->parse($raw, [
            'source' => 'imap',
            'folder' => $this->name,
            'uid' => $uid,
        ]);
    }

    public function fetchRaw(int $uid): string
    {
        MailboxUidGuard::assertValid($uid);
        $raw = $this->transport->rawMessage($this->name, $uid);
        if (strlen($raw) > $this->limits->maxMessageBytes) {
            throw new MailboxProtocolException(sprintf(
                'Mailbox message size exceeds limit (%d bytes) for UID %d.',
                $this->limits->maxMessageBytes,
                $uid,
            ));
        }

        return $raw;
    }

    public function fetchSummary(int $uid): MailboxMessageRef
    {
        MailboxUidGuard::assertValid($uid);
        if ($this->transport instanceof EnvelopeSummaryMailboxTransport) {
            return $this->transport->envelopeSummary($this->name, $uid);
        }

        $parsed = $this->fetchParsed($uid);

        return new MailboxMessageRef(
            uid: $uid,
            subject: $parsed->subject,
            date: $parsed->date,
            from: $parsed->fromEmail(),
            sizeBytes: strlen($parsed->raw),
            messageId: $parsed->messageId,
        );
    }

    public function markSeen(int $uid): void
    {
        MailboxUidGuard::assertValid($uid);
        $this->transport->markSeen($this->name, $uid);
    }

    /**
     * @param list<int> $uids
     */
    public function markSeenMany(array $uids): void
    {
        foreach ($uids as $uid) {
            $this->markSeen($uid);
        }
    }

    public function markUnread(int $uid): void
    {
        MailboxUidGuard::assertValid($uid);
        $this->transport->markUnread($this->name, $uid);
    }

    /**
     * @param list<int> $uids
     */
    public function markUnreadMany(array $uids): void
    {
        foreach ($uids as $uid) {
            $this->markUnread($uid);
        }
    }

    public function move(int $uid, string $targetFolder): void
    {
        MailboxUidGuard::assertValid($uid);
        MailboxFolderNameGuard::assertValid($targetFolder);
        $this->transport->move($this->name, $uid, $targetFolder);
    }

    /**
     * @param list<int> $uids
     */
    public function moveMany(array $uids, string $targetFolder): void
    {
        foreach ($uids as $uid) {
            $this->move($uid, $targetFolder);
        }
    }

    /**
     * @return list<MailboxMessageRef>
     */
    public function query(?MailboxSearch $search = null): array
    {
        $search ??= MailboxSearch::new();
        $uids = $this->transport->search($this->name, $search);
        $this->assertQueryCostLimits($search, count($uids));
        $uids = $this->sortUids($uids, $search->sortOrder);
        $refs = array_map(static fn(int $uid): MailboxMessageRef => new MailboxMessageRef($uid), $uids);
        $refs = $this->sortRefsByDateIfNeeded($refs, $search);

        if ($search->limit !== null) {
            return array_slice($refs, 0, $search->limit);
        }

        return $refs;
    }

    public function removeFlag(int $uid, string $flag): void
    {
        MailboxUidGuard::assertValid($uid);
        $this->transport->removeFlag($this->name, $uid, $flag);
    }

    /**
     * @param list<int> $uids
     */
    public function removeFlagMany(array $uids, string $flag): void
    {
        foreach ($uids as $uid) {
            $this->removeFlag($uid, $flag);
        }
    }

    public function status(): MailboxStatus
    {
        return $this->transport->status($this->name);
    }

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void
    {
        if (!$this->transport instanceof WatchableMailboxTransport) {
            throw new \RuntimeException('Mailbox transport does not support watch/IDLE operations.');
        }

        $this->transport->watch($this->name, $onEvent, $timeoutSeconds, $shouldStop);
    }

    private function assertQueryCostLimits(MailboxSearch $search, int $matchedCount): void
    {
        if (
            $search->requireExplicitLimitForExpensiveSearch
            && $search->limit === null
            && $this->isExpensiveSearch($search)
        ) {
            throw new MailboxProtocolException('Expensive mailbox search requires an explicit limit().');
        }

        if ($search->maxClientSideFilterFetches !== null && $matchedCount > $search->maxClientSideFilterFetches) {
            throw new MailboxProtocolException(sprintf(
                'Mailbox search matched %d messages and exceeded maxClientSideFilterFetches=%d.',
                $matchedCount,
                $search->maxClientSideFilterFetches,
            ));
        }
    }

    private function isExpensiveSearch(MailboxSearch $search): bool
    {
        if (in_array($search->sortOrder, ['date_desc', 'date_asc'], true)) {
            return true;
        }

        foreach ($search->criteria as $criterion) {
            $normalized = strtoupper($criterion);
            if (str_contains($normalized, 'CONTENT-DISPOSITION: ATTACHMENT')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<MailboxMessageRef> $refs
     * @return list<MailboxMessageRef>
     */
    private function sortRefsByDateIfNeeded(array $refs, MailboxSearch $search): array
    {
        if (!in_array($search->sortOrder, ['date_desc', 'date_asc'], true)) {
            return $refs;
        }

        if ($search->maxSummaryFetches !== null && count($refs) > $search->maxSummaryFetches) {
            throw new MailboxProtocolException(sprintf(
                'Date sorting requires %d summary fetches and exceeded maxSummaryFetches=%d.',
                count($refs),
                $search->maxSummaryFetches,
            ));
        }

        $refs = array_map(fn(MailboxMessageRef $ref): MailboxMessageRef => $this->fetchSummary($ref->uid), $refs);
        usort($refs, static function (MailboxMessageRef $left, MailboxMessageRef $right) use ($search): int {
            $leftTs = $left->date?->getTimestamp() ?? 0;
            $rightTs = $right->date?->getTimestamp() ?? 0;

            return $search->sortOrder === 'date_desc' ? ($rightTs <=> $leftTs) : ($leftTs <=> $rightTs);
        });

        return $refs;
    }

    /**
     * @param list<int> $uids
     * @return list<int>
     */
    private function sortUids(array $uids, string $sortOrder): array
    {
        if ($sortOrder === 'uid_desc') {
            rsort($uids);

            return $uids;
        }

        if ($sortOrder === 'uid_asc') {
            sort($uids);
        }

        return $uids;
    }
}
