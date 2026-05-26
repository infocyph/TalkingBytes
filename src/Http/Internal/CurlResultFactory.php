<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;

final class CurlResultFactory
{
    /**
     * @param array<string, mixed>|false $rawInfo
     * @param array<string, string|list<string>> $responseHeaders
     */
    public static function fromExecution(
        HttpRequest $request,
        string $transport,
        string $body,
        int $errno,
        string $error,
        array|false $rawInfo,
        array $responseHeaders,
    ): CommunicationResult {
        $info = is_array($rawInfo) ? $rawInfo : [];
        $statusCode = self::statusCode($info);
        $downloadPath = $request->options->downloadPath;

        if ($request->options->maxResponseBytes !== null && strlen($body) > $request->options->maxResponseBytes) {
            return CommunicationResult::failure(
                sprintf('HTTP response exceeded max allowed bytes (%d).', $request->options->maxResponseBytes),
                $statusCode,
                null,
                ['transport' => $transport],
            );
        }

        if ($request->options->maxDownloadBytes !== null && $downloadPath !== null && strlen($body) > $request->options->maxDownloadBytes) {
            return CommunicationResult::failure(
                sprintf('HTTP download exceeded max allowed bytes (%d).', $request->options->maxDownloadBytes),
                $statusCode,
                null,
                ['transport' => $transport],
            );
        }

        if ($downloadPath !== null) {
            $downloadError = self::writeDownloadBody($downloadPath, $body);

            if ($downloadError !== null) {
                return CommunicationResult::failure(
                    $downloadError,
                    $statusCode,
                    metadata: ['transport' => $transport, 'curl' => $info],
                );
            }
        }

        if ($errno !== 0) {
            return CommunicationResult::failure(
                sprintf('cURL request failed (%d): %s', $errno, $error),
                $statusCode,
                null,
                ['curl' => $info, 'transport' => $transport],
            );
        }

        $response = new HttpResponse($statusCode, $body, $responseHeaders, ['curl' => $info]);

        if ($statusCode !== null && $statusCode >= 400) {
            return CommunicationResult::failure(
                sprintf('HTTP request failed with status %d.', $statusCode),
                $statusCode,
                $response,
                ['transport' => $transport, 'curl' => $info],
            );
        }

        return CommunicationResult::success($statusCode, $response, ['transport' => $transport, 'curl' => $info]);
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function statusCode(array $info): ?int
    {
        $code = $info['http_code'] ?? null;

        if (!is_int($code) || $code < 1) {
            return null;
        }

        return $code;
    }

    private static function writeDownloadBody(string $path, string $body): ?string
    {
        if (is_dir($path)) {
            return sprintf('Download path points to a directory: %s', $path);
        }

        $directory = dirname($path);

        if (!is_dir($directory)) {
            return sprintf('Download directory does not exist: %s', $directory);
        }

        if (!is_writable($directory)) {
            return sprintf('Download directory is not writable: %s', $directory);
        }

        if (file_put_contents($path, $body) === false) {
            return sprintf('Failed to write download file: %s', $path);
        }

        return null;
    }
}
