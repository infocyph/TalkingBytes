<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\HttpRequest;

final class UploadHandleManager
{
    public static function cleanup(HttpRequest $request): void
    {
        $openedByConfigurator = $request->metadata['_upload_opened_by_configurator'] ?? false;
        $resource = $request->metadata['_upload_handle'] ?? null;

        if ($openedByConfigurator !== true || !is_resource($resource)) {
            return;
        }

        fclose($resource);
    }
}
