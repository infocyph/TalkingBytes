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
        bool $publishBufferedDownload = true,
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

        if ($publishBufferedDownload && $downloadPath !== null) {
            $downloadError = self::writeDownloadBody($downloadPath, $body);
            if ($downloadError !== null) {
                return CommunicationResult::failure(
                    $downloadError,
                    $statusCode,
                    $response,
                    ['transport' => $transport, 'curl' => $info],
                );
            }
        }

        return CommunicationResult::success($statusCode, $response, ['transport' => $transport, 'curl' => $info]);
    }

    public static function publishBufferedDownload(HttpRequest $request, CommunicationResult $result): CommunicationResult
    {
        $path = $request->options->downloadPath;
        if (!$result->successful || $path === null || !$result->response instanceof HttpResponse) {
            return $result;
        }

        $downloadError = self::writeDownloadBody($path, $result->response->body);
        if ($downloadError === null) {
            return $result;
        }

        return CommunicationResult::failure(
            $downloadError,
            $result->statusCode,
            $result->response,
            $result->metadata,
        );
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

        $tempPath = tempnam($directory, 'tb-http-download-');
        if ($tempPath === false) {
            return sprintf('Unable to allocate temporary download file in directory: %s', $directory);
        }

        try {
            if (file_put_contents($tempPath, $body, LOCK_EX) === false) {
                return sprintf('Failed to write download file: %s', $path);
            }

            set_error_handler(static fn(): bool => true, E_WARNING);

            try {
                $secured = chmod($tempPath, 0600);
            } finally {
                restore_error_handler();
            }

            if (!$secured) {
                return sprintf('Failed to secure temporary download file: %s', $path);
            }

            if (!rename($tempPath, $path)) {
                return sprintf('Failed to finalize download file: %s', $path);
            }

            $tempPath = null;

            return null;
        } finally {
            if (is_string($tempPath) && is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}
