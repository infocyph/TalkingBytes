<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use RuntimeException;

/** Publishes claimed spool files without overwriting existing deliveries. */
final class SpoolFilePublisher
{
    public static function move(
        string $source,
        string $directory,
        bool $ensureUnique = false,
        ?string $targetBasename = null,
    ): string {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $directory));
        }

        $handle = self::openLockedSource($source);
        try {
            return self::publish($source, $directory, $targetBasename ?? basename($source), $ensureUnique);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private static function openLockedSource(string $source): mixed
    {
        $handle = fopen($source, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to read spool source file: %s', $source));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(sprintf('Spool source is already being processed: %s', $source));
        }

        $opened = fstat($handle);
        $current = stat($source);
        if (!is_array($opened) || !is_array($current)
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
            || is_link($source)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new RuntimeException('Spool source changed while acquiring the processing claim.');
        }

        return $handle;
    }

    private static function publish(string $source, string $directory, string $basename, bool $ensureUnique): string
    {
        $target = rtrim($directory, '/\\') . '/' . $basename;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $ensureUnique ? self::uniqueTarget($directory, $basename) : $target;
            $published = self::tryLink($source, $candidate);
            if (!$published && !self::isSameFilesystem($source, $directory) && !file_exists($candidate)) {
                $published = self::publishAcrossFilesystems($source, $directory, $candidate);
            }
            if ($published) {
                self::removePublishedSource($source, $candidate);

                return $candidate;
            }
            if (!$ensureUnique || !file_exists($candidate)) {
                break;
            }
        }

        throw new RuntimeException(sprintf('Unable to move file "%s" without overwriting a destination.', $source));
    }

    private static function isSameFilesystem(string $file, string $directory): bool
    {
        $source = stat($file);
        $target = stat($directory);

        return is_array($source) && is_array($target) && $source['dev'] === $target['dev'];
    }

    private static function publishAcrossFilesystems(string $source, string $directory, string $target): bool
    {
        $staging = tempnam($directory, '.tb-spool-');
        if ($staging === false) {
            throw new RuntimeException('Unable to allocate spool destination staging file.');
        }

        try {
            if (!self::tryCopy($source, $staging) || !chmod($staging, 0600)) {
                throw new RuntimeException('Unable to safely stage spool message on destination filesystem.');
            }

            return self::tryLink($staging, $target);
        } finally {
            unlink($staging);
        }
    }

    private static function removePublishedSource(string $source, string $destination): void
    {
        if (unlink($source)) {
            return;
        }

        unlink($destination);
        throw new RuntimeException(sprintf('Unable to remove spool source after publication: %s', $source));
    }

    private static function tryCopy(string $source, string $target): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return copy($source, $target);
        } finally {
            restore_error_handler();
        }
    }

    private static function tryLink(string $source, string $target): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return link($source, $target);
        } finally {
            restore_error_handler();
        }
    }

    private static function uniqueTarget(string $directory, string $basename): string
    {
        $target = rtrim($directory, '/\\') . '/' . $basename;
        if (!file_exists($target)) {
            return $target;
        }

        $pathInfo = pathinfo($basename);
        $name = $pathInfo['filename'];
        $extension = isset($pathInfo['extension']) ? ('.' . $pathInfo['extension']) : '';

        return sprintf('%s/%s-%s%s', rtrim($directory, '/\\'), $name, bin2hex(random_bytes(4)), $extension);
    }
}
