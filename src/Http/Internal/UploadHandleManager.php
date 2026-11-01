<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;
use Throwable;

final class UploadHandleManager
{
    public static function cleanup(HttpRequest $request): void
    {
        $paths = $request->metadata['_multipart_temp_paths'] ?? [];
        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        }

        $openedByConfigurator = $request->metadata['_upload_opened_by_configurator'] ?? false;
        $ownedByRequest = $request->metadata['_upload_handle_owned'] ?? false;
        $resource = $request->metadata['_upload_handle'] ?? null;

        if (($openedByConfigurator !== true && $ownedByRequest !== true) || !is_resource($resource)) {
            return;
        }

        fclose($resource);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function prepareRedirectMetadata(array $metadata): array
    {
        $sourceOffset = $metadata['_upload_source_offset'] ?? null;
        if (is_int($sourceOffset) && is_resource($metadata['upload_stream'] ?? null)) {
            $metadata['upload_offset'] = $sourceOffset;
        }

        unset(
            $metadata['_transport_prepared'],
            $metadata['_upload_handle'],
            $metadata['_upload_handle_owned'],
            $metadata['_upload_opened_by_configurator'],
            $metadata['_upload_source_offset'],
        );

        return $metadata;
    }

    public static function prepareSignedSnapshot(HttpRequest $request): HttpRequest
    {
        $metadata = $request->metadata;
        $rawUploadPath = $metadata['upload_file_path'] ?? null;
        $rawUploadStream = $metadata['upload_stream'] ?? null;
        $uploadPath = is_string($rawUploadPath) ? $rawUploadPath : null;
        $uploadStream = is_resource($rawUploadStream) ? $rawUploadStream : null;
        $signed = array_any(
            $request->authenticators,
            static fn(AuthenticatorInterface $authenticator): bool => $authenticator instanceof SignedRequestAuth,
        );
        if (($uploadPath === null && $uploadStream === null) || !$signed) {
            return $request;
        }

        $size = $metadata['upload_size'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new InvalidArgumentException('Upload size metadata is missing or invalid.');
        }

        $snapshot = tmpfile();
        if ($snapshot === false) {
            throw new InvalidArgumentException('Unable to create a temporary HTTP upload snapshot.');
        }

        try {
            self::copyUploadToSnapshot($request, $snapshot, $uploadPath, $uploadStream, $size);
            rewind($snapshot);

            return $request->metadata(self::prepareSnapshotMetadata($metadata, $snapshot));
        } catch (Throwable $throwable) {
            fclose($snapshot);

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param resource $snapshot
     * @return array<string, mixed>
     */
    public static function prepareSnapshotMetadata(array $metadata, mixed $snapshot): array
    {
        $prepared = [
            ...$metadata,
            '_upload_handle' => $snapshot,
            '_upload_handle_owned' => true,
            'upload_offset' => 0,
        ];

        if (is_resource($metadata['upload_stream'] ?? null)) {
            $prepared['_upload_source_offset'] = $metadata['_upload_source_offset']
                ?? $metadata['upload_offset']
                ?? 0;
        }

        return $prepared;
    }

    /**
     * @param resource $source
     * @param resource $snapshot
     */
    private static function copyUploadBytes(mixed $source, mixed $snapshot, int $size): void
    {
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = fread($source, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new InvalidArgumentException('HTTP upload ended before the declared upload size.');
            }
            if (fwrite($snapshot, $chunk) !== strlen($chunk)) {
                throw new InvalidArgumentException('Unable to snapshot HTTP upload for signing.');
            }

            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param resource $snapshot
     * @param resource|null $uploadStream
     */
    private static function copyUploadToSnapshot(
        HttpRequest $request,
        mixed $snapshot,
        ?string $uploadPath,
        mixed $uploadStream,
        int $size,
    ): void {
        [$source, $closeSource, $offset] = self::openUploadSource($request, $uploadPath, $uploadStream);
        $originalOffset = ftell($source);
        if (!is_int($originalOffset) || fseek($source, $offset) !== 0) {
            if ($closeSource) {
                fclose($source);
            }

            throw new InvalidArgumentException('Unable to position HTTP upload stream for signing.');
        }

        try {
            self::copyUploadBytes($source, $snapshot, $size);
        } finally {
            self::restoreUploadSource($source, $closeSource, $originalOffset);
        }
    }

    /**
     * @param resource|null $uploadStream
     * @return array{0: resource, 1: bool, 2: int}
     */
    private static function openUploadSource(HttpRequest $request, ?string $uploadPath, mixed $uploadStream): array
    {
        if ($uploadPath !== null) {
            $source = fopen($uploadPath, 'rb');
            if ($source === false) {
                throw new InvalidArgumentException(sprintf('Failed to open upload file: %s', $uploadPath));
            }

            return [$source, true, 0];
        }

        if (!is_resource($uploadStream)) {
            throw new InvalidArgumentException('Prepared HTTP upload source is invalid.');
        }

        $offset = $request->metadata['upload_offset'] ?? null;
        if (!is_int($offset) || $offset < 0) {
            throw new InvalidArgumentException('Prepared HTTP upload offset is invalid.');
        }

        return [$uploadStream, false, $offset];
    }

    /**
     * @param resource $source
     */
    private static function restoreUploadSource(mixed $source, bool $closeSource, int $originalOffset): void
    {
        if ($closeSource) {
            fclose($source);

            return;
        }

        if (fseek($source, $originalOffset) !== 0) {
            throw new InvalidArgumentException('Unable to restore HTTP upload stream after signing.');
        }
    }
}
