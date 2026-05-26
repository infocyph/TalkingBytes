<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final class CurlHandleConfigurator
{
    public function configure(
        \CurlHandle $handle,
        HttpRequest $request,
        ResponseHeaderCollector $headers,
        ResponseBodyCollector $bodyCollector,
    ): HttpRequest {
        $resolvedRequest = $this->applyBodyAndContentType($request, $handle);
        $resolvedRequest = $this->applyUpload($resolvedRequest, $handle);

        $this->setRequiredStringOption($handle, CURLOPT_URL, $resolvedRequest->buildUrl(), 'Request URL must not be empty.');
        $this->setRequiredStringOption($handle, CURLOPT_CUSTOMREQUEST, $resolvedRequest->method->value, 'HTTP method must not be empty.');

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, $resolvedRequest->options->followRedirects);
        curl_setopt($handle, CURLOPT_MAXREDIRS, $resolvedRequest->options->maxRedirects);
        curl_setopt($handle, CURLOPT_TIMEOUT, $resolvedRequest->options->timeoutSeconds);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $resolvedRequest->options->connectTimeoutSeconds);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $resolvedRequest->options->verifyPeer);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $resolvedRequest->options->verifyHost ? 2 : 0);

        $this->setOptionalStringOption($handle, CURLOPT_PROXY, $resolvedRequest->options->proxy);
        $this->setOptionalStringOption($handle, CURLOPT_PROXYUSERPWD, $resolvedRequest->options->proxyAuth);
        $this->setOptionalStringOption($handle, CURLOPT_CAINFO, $resolvedRequest->options->caBundle);
        $this->setOptionalStringOption($handle, CURLOPT_SSLCERT, $resolvedRequest->options->clientCertificate);
        $this->setOptionalStringOption($handle, CURLOPT_SSLKEY, $resolvedRequest->options->clientKey);
        $this->setOptionalStringOption($handle, CURLOPT_KEYPASSWD, $resolvedRequest->options->clientKeyPassphrase);
        $this->setOptionalStringOption($handle, CURLOPT_USERAGENT, $resolvedRequest->options->userAgent);

        if ($resolvedRequest->options->httpVersion !== null) {
            curl_setopt($handle, CURLOPT_HTTP_VERSION, $resolvedRequest->options->httpVersion);
        }

        if ($resolvedRequest->method === HttpMethod::Head) {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        $curlHeaders = $resolvedRequest->headers->toCurlHeaders();
        if ($curlHeaders !== []) {
            curl_setopt($handle, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        curl_setopt(
            $handle,
            CURLOPT_HEADERFUNCTION,
            static function (\CurlHandle $curlHandle, string $line) use ($headers): int {
                // cURL requires the handle parameter in this callback signature.
                unset($curlHandle);

                return $headers->collect($line);
            },
        );
        curl_setopt(
            $handle,
            CURLOPT_WRITEFUNCTION,
            static function (\CurlHandle $curlHandle, string $chunk) use ($bodyCollector): int {
                // cURL requires the handle parameter in this callback signature.
                unset($curlHandle);

                return $bodyCollector->collect($chunk);
            },
        );

        foreach ($resolvedRequest->options->additional as $option => $value) {
            curl_setopt($handle, $option, $value);
        }

        return $resolvedRequest;
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

        $payload = $request->body->toCurlPayload();
        if (is_string($payload) && $request->options->maxUploadBytes !== null && strlen($payload) > $request->options->maxUploadBytes) {
            throw new InvalidArgumentException(sprintf('HTTP request body exceeded max upload bytes (%d).', $request->options->maxUploadBytes));
        }

        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);

        return $resolvedRequest;
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

        $resource = null;
        if (is_string($uploadPath)) {
            $resource = fopen($uploadPath, 'rb');
            if ($resource === false) {
                throw new InvalidArgumentException(sprintf('Failed to open upload file: %s', $uploadPath));
            }
        } elseif (is_resource($uploadStream)) {
            $resource = $uploadStream;
            rewind($resource);
        }

        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Upload source must be a file path or stream resource.');
        }

        curl_setopt($handle, CURLOPT_UPLOAD, true);
        curl_setopt($handle, CURLOPT_INFILE, $resource);
        curl_setopt($handle, CURLOPT_INFILESIZE, $size);

        return $resolvedRequest->metadata([
            ...$resolvedRequest->metadata,
            '_upload_handle' => $resource,
            '_upload_opened_by_configurator' => is_string($uploadPath),
        ]);
    }

    private function setOptionalStringOption(\CurlHandle $handle, int $option, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        curl_setopt($handle, $option, $value);
    }

    private function setRequiredStringOption(\CurlHandle $handle, int $option, string $value, string $error): void
    {
        if ($value === '') {
            throw new InvalidArgumentException($error);
        }

        curl_setopt($handle, $option, $value);
    }
}
