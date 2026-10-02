<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\HttpRequest;

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
}
