<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface MailboxTransport
{
    public function addFlag(string $folder, int $uid, string $flag): void;

    public function close(string $folder): void;

    public function connect(): void;

    public function copy(string $folder, int $uid, string $targetFolder): void;

    public function createFolder(string $folder): void;

    public function delete(string $folder, int $uid): void;

    public function deleteFolder(string $folder): void;

    public function expunge(string $folder): void;

    /**
     * @return list<MailboxFolderInfo>
     */
    public function folderDetails(): array;

    public function folderExists(string $folder): bool;

    /**
     * @return list<string>
     */
    public function folders(): array;

    public function logout(): void;

    public function markSeen(string $folder, int $uid): void;

    public function markUnread(string $folder, int $uid): void;

    public function move(string $folder, int $uid, string $targetFolder): void;

    public function noop(): void;

    public function rawMessage(string $folder, int $uid): string;

    public function removeFlag(string $folder, int $uid, string $flag): void;

    public function renameFolder(string $folder, string $targetFolder): void;

    /**
     * @return list<int>
     */
    public function search(string $folder, MailboxSearch $search): array;

    public function status(string $folder): MailboxStatus;

    public function subscribeFolder(string $folder): void;

    public function unsubscribeFolder(string $folder): void;
}
