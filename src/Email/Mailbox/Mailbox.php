<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

final readonly class Mailbox
{
    public function __construct(
        private MailboxTransport $transport,
        private EmailParser $parser = new RawEmailParser(),
    ) {}

    public static function usingImap(ImapConfig $config): self
    {
        return new self(new ImapSocketTransport($config));
    }

    public function archive(string $sourceFolder, int $uid, ?string $archiveFolder = null): void
    {
        MailboxFolderNameGuard::assertValid($sourceFolder);
        $target = $archiveFolder;

        if ($target === null || trim($target) === '') {
            foreach (['Archive', 'Archives', '[Gmail]/All Mail'] as $candidate) {
                if ($this->folderExists($candidate)) {
                    $target = $candidate;

                    break;
                }
            }
        }

        $target ??= 'Archive';
        MailboxFolderNameGuard::assertValid($target);

        if (!$this->folderExists($target)) {
            $this->createFolder($target);
        }

        $this->transport->move($sourceFolder, $uid, $target);
    }

    /**
     * @param array{archive?:string,trash?:string,all_mail?:string}|null $strategy
     */
    public function archiveWithProviderStrategy(string $sourceFolder, int $uid, ?array $strategy = null): void
    {
        $strategy ??= [];
        $target = $strategy['archive'] ?? $strategy['all_mail'] ?? null;
        $this->archive($sourceFolder, $uid, $target);
    }

    public function createFolder(string $name): void
    {
        MailboxFolderNameGuard::assertValid($name);
        $this->transport->createFolder($name);
    }

    public function deleteFolder(string $name): void
    {
        MailboxFolderNameGuard::assertValid($name);
        $this->transport->deleteFolder($name);
    }

    public function folder(string $name): MailboxFolder
    {
        MailboxFolderNameGuard::assertValid($name);

        return new MailboxFolder($name, $this->transport, $this->parser);
    }

    /**
     * @return list<MailboxFolderInfo>
     */
    public function folderDetails(): array
    {
        return $this->transport->folderDetails();
    }

    public function folderExists(string $name): bool
    {
        MailboxFolderNameGuard::assertValid($name);

        return $this->transport->folderExists($name);
    }

    /**
     * @return list<string>
     */
    public function folders(): array
    {
        return $this->transport->folders();
    }

    public function noop(): void
    {
        $this->transport->noop();
    }

    public function renameFolder(string $name, string $targetName): void
    {
        MailboxFolderNameGuard::assertValid($name);
        MailboxFolderNameGuard::assertValid($targetName);
        $this->transport->renameFolder($name, $targetName);
    }

    public function status(string $name): MailboxStatus
    {
        MailboxFolderNameGuard::assertValid($name);

        return $this->transport->status($name);
    }

    public function subscribeFolder(string $name): void
    {
        MailboxFolderNameGuard::assertValid($name);
        $this->transport->subscribeFolder($name);
    }

    public function transport(): MailboxTransport
    {
        return $this->transport;
    }

    public function unsubscribeFolder(string $name): void
    {
        MailboxFolderNameGuard::assertValid($name);
        $this->transport->unsubscribeFolder($name);
    }

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(string $folder, callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void
    {
        MailboxFolderNameGuard::assertValid($folder);
        $this->folder($folder)->watch($onEvent, $timeoutSeconds, $shouldStop);
    }
}
