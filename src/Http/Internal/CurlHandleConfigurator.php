<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;
use Throwable;

final class CurlHandleConfigurator
{
    public function configure(
        \CurlHandle $handle,
        HttpRequest $request,
        ResponseHeaderCollector $headers,
        ResponseBodyCollector $bodyCollector,
        ?string $pinnedResolution = null,
    ): HttpRequest {
        $resolvedRequest = $request;

        try {
            $resolvedRequest = $this->applyBodyAndContentType($resolvedRequest, $handle);
            $resolvedRequest = $this->applyUpload($resolvedRequest, $handle);

            $this->setRequiredStringOption($handle, CURLOPT_URL, $resolvedRequest->buildUrl(), 'Request URL must not be empty.');
            $this->setRequiredStringOption($handle, CURLOPT_CUSTOMREQUEST, $resolvedRequest->method->value, 'HTTP method must not be empty.');

            $this->setOption($handle, CURLOPT_RETURNTRANSFER, true, 'Unable to configure cURL response handling.');
            $this->setOption($handle, CURLOPT_FOLLOWLOCATION, false, 'Unable to disable automatic cURL redirects.');
            $this->setOption($handle, CURLOPT_TIMEOUT, $resolvedRequest->options->timeoutSeconds, 'Unable to configure cURL timeout.');
            $this->setOption($handle, CURLOPT_CONNECTTIMEOUT, $resolvedRequest->options->connectTimeoutSeconds, 'Unable to configure cURL connection timeout.');
            $this->setOption($handle, CURLOPT_SSL_VERIFYPEER, $resolvedRequest->options->verifyPeer, 'Unable to configure cURL TLS peer verification.');
            $this->setOption($handle, CURLOPT_SSL_VERIFYHOST, $resolvedRequest->options->verifyHost ? 2 : 0, 'Unable to configure cURL TLS host verification.');
            if ($pinnedResolution !== null) {
                $this->setOption($handle, CURLOPT_RESOLVE, [$pinnedResolution], 'Unable to pin the validated cURL DNS resolution.');
            }

            $this->setOptionalStringOption($handle, CURLOPT_PROXY, $resolvedRequest->options->proxy);
            $this->setOptionalStringOption($handle, CURLOPT_PROXYUSERPWD, $resolvedRequest->options->proxyAuth);
            $this->setOptionalStringOption($handle, CURLOPT_CAINFO, $resolvedRequest->options->caBundle);
            $this->setOptionalStringOption($handle, CURLOPT_SSLCERT, $resolvedRequest->options->clientCertificate);
            $this->setOptionalStringOption($handle, CURLOPT_SSLKEY, $resolvedRequest->options->clientKey);
            $this->setOptionalStringOption($handle, CURLOPT_KEYPASSWD, $resolvedRequest->options->clientKeyPassphrase);
            $this->setOptionalStringOption($handle, CURLOPT_USERAGENT, $resolvedRequest->options->userAgent);

            if ($resolvedRequest->options->httpVersion !== null) {
                $this->setOption($handle, CURLOPT_HTTP_VERSION, $resolvedRequest->options->httpVersion, 'Unable to configure the cURL HTTP version.');
            }

            if ($resolvedRequest->method === HttpMethod::Head) {
                $this->setOption($handle, CURLOPT_NOBODY, true, 'Unable to configure the cURL HEAD request.');
            }

            $curlHeaders = $resolvedRequest->headers->toCurlHeaders();
            if ($curlHeaders !== []) {
                $this->setOption($handle, CURLOPT_HTTPHEADER, $curlHeaders, 'Unable to configure cURL request headers.');
            }

            $this->setOption(
                $handle,
                CURLOPT_HEADERFUNCTION,
                static function (\CurlHandle $curlHandle, string $line) use ($headers): int {
                    // cURL requires the handle parameter in this callback signature.
                    unset($curlHandle);

                    return $headers->collect($line);
                },
                'Unable to configure cURL response header collection.',
            );
            $this->setOption(
                $handle,
                CURLOPT_WRITEFUNCTION,
                static function (\CurlHandle $curlHandle, string $chunk) use ($bodyCollector): int {
                    // cURL requires the handle parameter in this callback signature.
                    unset($curlHandle);

                    return $bodyCollector->collect($chunk);
                },
                'Unable to configure cURL response body collection.',
            );

            return $resolvedRequest;
        } catch (Throwable $throwable) {
            UploadHandleManager::cleanup($resolvedRequest);

            throw $throwable;
        }
    }

    /** @param list<string> $paths */
    private static function cleanupTemporaryPaths(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function applyBodyAndContentType(HttpRequest $request, \CurlHandle $handle): HttpRequest
    {
        if (isset($request->metadata['upload_file_path']) || isset($request->metadata['upload_stream'])) {
            if ($request->body !== null) {
                throw new InvalidArgumentException('HTTP request cannot combine uploadFromFile/uploadFromStream with a request body.');
            }

            return $request;
        }

        if ($request->body === null) {
            return $request;
        }

        $resolvedRequest = $request;
        if ($request->headers->get('Content-Type') === null) {
            $resolvedRequest = $resolvedRequest->header('Content-Type', $request->body->contentType());
        }

        $temporaryPaths = [];
        if ($request->body instanceof MultipartBody) {
            $prepared = $request->body->prepareCurlPayload($request->options->maxUploadBytes);
            $payload = $prepared['payload'];
            $temporaryPaths = $prepared['temporaryPaths'];
        } else {
            $payload = $request->body->toCurlPayload();
        }
        if (is_string($payload) && $request->options->maxUploadBytes !== null && strlen($payload) > $request->options->maxUploadBytes) {
            throw new InvalidArgumentException(sprintf('HTTP request body exceeded max upload bytes (%d).', $request->options->maxUploadBytes));
        }

        try {
            $this->setOption($handle, CURLOPT_POSTFIELDS, $payload, 'Unable to configure the cURL request body.');
        } catch (Throwable $throwable) {
            self::cleanupTemporaryPaths($temporaryPaths);

            throw $throwable;
        }

        if ($temporaryPaths === []) {
            return $resolvedRequest;
        }

        return $resolvedRequest->metadata([
            ...$resolvedRequest->metadata,
            '_multipart_temp_paths' => $temporaryPaths,
        ]);
    }

    private function applyUpload(HttpRequest $request, \CurlHandle $handle): HttpRequest
    {
        $uploadPath = $request->metadata['upload_file_path'] ?? null;
        $uploadStream = $request->metadata['upload_stream'] ?? null;

        if (!is_string($uploadPath) && !is_resource($uploadStream)) {
            return $request;
        }

        $size = $request->metadata['upload_size'] ?? null;
        if (!is_int($size) || $size < 0) {
            throw new InvalidArgumentException('Upload size metadata is missing or invalid.');
        }

        if ($request->options->maxUploadBytes !== null && $size > $request->options->maxUploadBytes) {
            throw new InvalidArgumentException(sprintf('HTTP upload exceeded max upload bytes (%d).', $request->options->maxUploadBytes));
        }

        $resolvedRequest = $request;
        if ($resolvedRequest->headers->get('Content-Type') === null) {
            $resolvedRequest = $resolvedRequest->header('Content-Type', 'application/octet-stream');
        }

        $resource = $this->openUploadResource($request, $uploadPath, $uploadStream);

        try {
            $this->setOption($handle, CURLOPT_UPLOAD, true, 'Unable to configure cURL upload mode.');
            $this->setOption($handle, CURLOPT_INFILE, $resource, 'Unable to configure the cURL upload source.');
            $this->setOption($handle, CURLOPT_INFILESIZE, $size, 'Unable to configure the cURL upload size.');
        } catch (Throwable $throwable) {
            if (is_string($uploadPath)) {
                fclose($resource);
            }

            throw $throwable;
        }

        return $resolvedRequest->metadata([
            ...$resolvedRequest->metadata,
            '_upload_handle' => $resource,
            '_upload_opened_by_configurator' => is_string($uploadPath),
        ]);
    }

    /** @return resource */
    private function openUploadResource(HttpRequest $request, mixed $uploadPath, mixed $uploadStream): mixed
    {
        if (is_string($uploadPath)) {
            $resource = fopen($uploadPath, 'rb');
            if ($resource === false) {
                throw new InvalidArgumentException(sprintf('Failed to open upload file: %s', $uploadPath));
            }

            return $resource;
        }

        if (!is_resource($uploadStream)) {
            throw new InvalidArgumentException('Upload source must be a file path or stream resource.');
        }

        $offset = $request->metadata['upload_offset'] ?? null;
        if (!is_int($offset) || fseek($uploadStream, $offset) !== 0) {
            throw new InvalidArgumentException('Unable to rewind HTTP upload stream to its starting position.');
        }

        return $uploadStream;
    }

    private function setOption(\CurlHandle $handle, int $option, mixed $value, string $error): void
    {
        if (!curl_setopt($handle, $option, $value)) {
            throw new InvalidArgumentException($error);
        }
    }

    private function setOptionalStringOption(\CurlHandle $handle, int $option, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->setOption($handle, $option, $value, 'Unable to configure an optional cURL string option.');
    }

    private function setRequiredStringOption(\CurlHandle $handle, int $option, string $value, string $error): void
    {
        if ($value === '') {
            throw new InvalidArgumentException($error);
        }

        $this->setOption($handle, $option, $value, $error);
    }
}
