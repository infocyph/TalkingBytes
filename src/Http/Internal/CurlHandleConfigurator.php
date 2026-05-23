<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final class CurlHandleConfigurator
{
    public function configure(\CurlHandle $handle, HttpRequest $request, ResponseHeaderCollector $headers): HttpRequest
    {
        $resolvedRequest = $this->applyBodyAndContentType($request, $handle);

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
        $this->setOptionalStringOption($handle, CURLOPT_SSLCERT, $resolvedRequest->options->clientCertificate);
        $this->setOptionalStringOption($handle, CURLOPT_SSLKEY, $resolvedRequest->options->clientKey);
        $this->setOptionalStringOption($handle, CURLOPT_KEYPASSWD, $resolvedRequest->options->clientKeyPassphrase);

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

        foreach ($resolvedRequest->options->additional as $option => $value) {
            curl_setopt($handle, $option, $value);
        }

        return $resolvedRequest;
    }

    private function applyBodyAndContentType(HttpRequest $request, \CurlHandle $handle): HttpRequest
    {
        if ($request->body === null) {
            return $request;
        }

        $resolvedRequest = $request;
        if ($request->headers->get('Content-Type') === null) {
            $resolvedRequest = $resolvedRequest->header('Content-Type', $request->body->contentType());
        }

        curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body->toCurlPayload());

        return $resolvedRequest;
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
