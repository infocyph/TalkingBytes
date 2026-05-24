<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;
use Infocyph\TalkingBytes\Email\Parser\HeaderParser;

final class FakeMailboxTransport implements BodyStructureMailboxTransport, EnvelopeSummaryMailboxTransport, MailboxTransport, RawHeadersMailboxTransport, WatchableMailboxTransport
{
    /**
     * @var array<string, array<int, list<string>>>
     */
    private array $flags = ['INBOX' => []];

    /**
     * @var array<string, array<int, string>>
     */
    private array $messages = ['INBOX' => []];

    /**
     * @var array<string, array<int, bool>>
     */
    private array $seen = ['INBOX' => []];

    public function addFlag(string $folder, int $uid, string $flag): void
    {
        $this->ensureMessageExists($folder, $uid);
        $normalized = $this->normalizeFlag($flag);
        $current = $this->flags[$folder][$uid] ?? [];
        if (!in_array($normalized, $current, true)) {
            $current[] = $normalized;
        }

        $this->flags[$folder][$uid] = $current;
    }

    public function bodyStructure(string $folder, int $uid): string
    {
        $headers = $this->rawHeaders($folder, $uid);
        $contentType = $headers['content-type'][0] ?? 'text/plain';
        $disposition = $headers['content-disposition'][0] ?? '';
        $upperDisposition = strtoupper($disposition);

        $normalizedDisposition = '';
        if (str_contains($upperDisposition, 'ATTACHMENT')) {
            $normalizedDisposition = 'ATTACHMENT';
        } elseif (str_contains($upperDisposition, 'INLINE')) {
            $normalizedDisposition = 'INLINE';
        }

        return sprintf('("CONTENT-TYPE" "%s" "CONTENT-DISPOSITION" "%s")', $contentType, $normalizedDisposition);
    }

    public function close(string $folder): void
    {
        if (!array_key_exists($folder, $this->messages)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" does not exist.', $folder));
        }
    }

    public function connect(): void
    {
        // no-op for fake transport
    }

    public function copy(string $folder, int $uid, string $targetFolder): void
    {
        $raw = $this->rawMessage($folder, $uid);
        $this->append($targetFolder, $uid, $raw, $this->seen[$folder][$uid] ?? false);
        $this->flags[$targetFolder][$uid] = $this->flags[$folder][$uid] ?? [];
    }

    public function createFolder(string $folder): void
    {
        if (array_key_exists($folder, $this->messages)) {
            return;
        }

        $this->messages[$folder] = [];
        $this->seen[$folder] = [];
        $this->flags[$folder] = [];
    }

    public function delete(string $folder, int $uid): void
    {
        unset($this->messages[$folder][$uid], $this->seen[$folder][$uid], $this->flags[$folder][$uid]);
    }

    public function deleteFolder(string $folder): void
    {
        unset($this->messages[$folder], $this->seen[$folder], $this->flags[$folder]);
    }

    public function envelopeSummary(string $folder, int $uid): MailboxMessageRef
    {
        $headers = $this->rawHeaders($folder, $uid);
        $date = null;
        if (($headers['date'][0] ?? null) !== null) {
            $date = new HeaderParser()->parseDate($headers['date'][0]);
        }

        return new MailboxMessageRef(
            uid: $uid,
            subject: $headers['subject'][0] ?? null,
            date: $date,
            from: $headers['from'][0] ?? null,
            sizeBytes: strlen($this->rawMessage($folder, $uid)),
            messageId: $headers['message-id'][0] ?? null,
        );
    }

    public function expunge(string $folder): void
    {
        if (!array_key_exists($folder, $this->messages)) {
            return;
        }

        ksort($this->messages[$folder]);
    }

    /**
     * @return list<MailboxFolderInfo>
     */
    public function folderDetails(): array
    {
        $details = [];
        foreach ($this->messages as $folder => $_messages) {
            $details[] = new MailboxFolderInfo($folder, $folder, '/', ['\\HASNOCHILDREN']);
        }

        return $details;
    }

    public function folderExists(string $folder): bool
    {
        return array_key_exists($folder, $this->messages);
    }

    /**
     * @return list<string>
     */
    public function folders(): array
    {
        return array_keys($this->messages);
    }

    public function logout(): void
    {
        // no-op for fake transport
    }

    public function markSeen(string $folder, int $uid): void
    {
        $this->ensureMessageExists($folder, $uid);
        $this->seen[$folder][$uid] = true;
    }

    public function markUnread(string $folder, int $uid): void
    {
        $this->ensureMessageExists($folder, $uid);
        $this->seen[$folder][$uid] = false;
    }

    public function move(string $folder, int $uid, string $targetFolder): void
    {
        $this->copy($folder, $uid, $targetFolder);
        $this->delete($folder, $uid);
    }

    public function noop(): void
    {
        // no-op
    }

    /**
     * @return array<string, list<string>>
     */
    public function rawHeaders(string $folder, int $uid): array
    {
        return MailboxRawHeaderMap::fromRawMessage($this->rawMessage($folder, $uid));
    }

    public function rawMessage(string $folder, int $uid): string
    {
        $this->ensureMessageExists($folder, $uid);

        return $this->messages[$folder][$uid];
    }

    public function removeFlag(string $folder, int $uid, string $flag): void
    {
        $this->ensureMessageExists($folder, $uid);
        $normalized = $this->normalizeFlag($flag);
        $current = $this->flags[$folder][$uid] ?? [];
        $this->flags[$folder][$uid] = array_values(
            array_filter($current, static fn(string $item): bool => $item !== $normalized),
        );
    }

    public function renameFolder(string $folder, string $targetFolder): void
    {
        if (!$this->folderExists($folder)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" does not exist.', $folder));
        }

        if ($this->folderExists($targetFolder)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" already exists.', $targetFolder));
        }

        $this->messages[$targetFolder] = $this->messages[$folder];
        $this->seen[$targetFolder] = $this->seen[$folder];
        $this->flags[$targetFolder] = $this->flags[$folder];
        unset($this->messages[$folder], $this->seen[$folder], $this->flags[$folder]);
    }

    /**
     * @return list<int>
     */
    public function search(string $folder, MailboxSearch $search): array
    {
        if (!array_key_exists($folder, $this->messages)) {
            return [];
        }

        $criteria = strtoupper($search->toCriteriaString());
        $tokens = preg_split('/\s+/', $criteria) ?: [];
        $requiresUnseen = in_array('UNSEEN', $tokens, true);
        $requiresSeen = in_array('SEEN', $tokens, true);
        $uids = [];

        foreach ($this->messages[$folder] as $uid => $raw) {
            $isSeen = $this->seen[$folder][$uid] ?? false;

            if ($requiresUnseen && $isSeen) {
                continue;
            }

            if ($requiresSeen && !$isSeen) {
                continue;
            }

            if (!$this->matchesHeaderContains($criteria, 'SUBJECT', $raw)) {
                continue;
            }

            if (!$this->matchesHeaderContains($criteria, 'FROM', $raw)) {
                continue;
            }

            $uids[] = $uid;
        }

        sort($uids);

        if ($search->limit !== null) {
            return array_slice($uids, 0, $search->limit);
        }

        return $uids;
    }

    public function status(string $folder): MailboxStatus
    {
        $messages = array_key_exists($folder, $this->messages) ? count($this->messages[$folder]) : 0;
        $unseen = 0;

        if (array_key_exists($folder, $this->seen)) {
            foreach ($this->seen[$folder] as $isSeen) {
                if (!$isSeen) {
                    $unseen++;
                }
            }
        }

        return new MailboxStatus($messages, 0, $unseen);
    }

    public function subscribeFolder(string $folder): void
    {
        if (!$this->folderExists($folder)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" does not exist.', $folder));
        }
    }

    public function unsubscribeFolder(string $folder): void
    {
        if (!$this->folderExists($folder)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" does not exist.', $folder));
        }
    }

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(string $folder, callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void
    {
        if (!array_key_exists($folder, $this->messages)) {
            throw new MailboxProtocolException(sprintf('Folder "%s" does not exist.', $folder));
        }

        $stop = $shouldStop ?? static fn(): bool => false;
        $deadline = time() + max(1, $timeoutSeconds);

        while (time() < $deadline && !$stop()) {
            $onEvent(sprintf('* %d EXISTS', count($this->messages[$folder])));
            usleep(250000);
        }
    }

    public function withMessage(string $folder, int $uid, string $raw, bool $seen = false): self
    {
        $clone = clone $this;
        $clone->append($folder, $uid, $raw, $seen);

        return $clone;
    }

    private function append(string $folder, int $uid, string $raw, bool $seen): void
    {
        if (!array_key_exists($folder, $this->messages)) {
            $this->messages[$folder] = [];
            $this->seen[$folder] = [];
            $this->flags[$folder] = [];
        }

        $this->messages[$folder][$uid] = $raw;
        $this->seen[$folder][$uid] = $seen;
        $this->flags[$folder][$uid] ??= [];
    }

    private function ensureMessageExists(string $folder, int $uid): void
    {
        if (array_key_exists($folder, $this->messages) && array_key_exists($uid, $this->messages[$folder])) {
            return;
        }

        throw new MailboxProtocolException(sprintf('Message UID %d not found in folder "%s".', $uid, $folder));
    }

    private function matchesHeaderContains(string $criteria, string $header, string $raw): bool
    {
        if (!preg_match('/' . $header . '\s+"([^"]+)"/', $criteria, $matches)) {
            return true;
        }

        $needle = strtolower($matches[1]);
        if ($needle === '') {
            return true;
        }

        if (preg_match('/^' . $header . ':\s*(.+)$/mi', $raw, $headerMatch) !== 1) {
            return false;
        }

        return str_contains(strtolower($headerMatch[1]), $needle);
    }

    private function normalizeFlag(string $flag): string
    {
        $trimmed = trim($flag);
        MailboxFlagGuard::assertValid($trimmed);

        return str_starts_with($trimmed, '\\') ? strtoupper($trimmed) : $trimmed;
    }
}
