<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Closure;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Signing\RequestSigner;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use InvalidArgumentException;

final readonly class SignedRequestAuth implements AuthenticatorInterface
{
    /**
     * @param Closure():int $clock
     * @param Closure():string $nonceGenerator
     */
    public function __construct(
        private RequestSigner $signer,
        private ?Closure $clock = null,
        private ?Closure $nonceGenerator = null,
        private string $signatureHeader = 'X-TB-Signature',
        private string $timestampHeader = 'X-TB-Timestamp',
        private string $nonceHeader = 'X-TB-Nonce',
    ) {
        HeaderBag::assertValidHeaderName($this->signatureHeader);
        HeaderBag::assertValidHeaderName($this->timestampHeader);
        HeaderBag::assertValidHeaderName($this->nonceHeader);
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        $timestampValue = ($this->clock ?? time(...))();
        if ($timestampValue < 0) {
            throw new InvalidArgumentException('HTTP signing timestamp must be greater than or equal to zero.');
        }

        $timestamp = (string) $timestampValue;
        $nonce = ($this->nonceGenerator ?? static fn(): string => bin2hex(random_bytes(16)))();
        if ($nonce === '' || strlen($nonce) > 256) {
            throw new InvalidArgumentException('HTTP signing nonce must contain between 1 and 256 bytes.');
        }
        HeaderBag::assertValidHeaderValue($nonce);

        $canonical = implode("\n", [
            strtoupper($request->method->value),
            $this->pathWithQuery($request->buildUrl()),
            $timestamp,
            $nonce,
            $this->payloadHash($request),
        ]);

        $signature = $this->signer->sign($canonical);

        return $request
            ->markSensitiveHeader($this->timestampHeader)
            ->markSensitiveHeader($this->nonceHeader)
            ->markSensitiveHeader($this->signatureHeader)
            ->header($this->timestampHeader, $timestamp)
            ->header($this->nonceHeader, $nonce)
            ->header($this->signatureHeader, $signature);
    }

    private function pathWithQuery(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $normalizedPath = is_string($path) && $path !== '' ? $path : '/';

        if (!is_string($query) || $query === '') {
            return $normalizedPath;
        }

        return $normalizedPath . '?' . $query;
    }

    private function payloadHash(HttpRequest $request): string
    {
        $upload = $request->metadata['_upload_handle'] ?? null;
        if (is_resource($upload)) {
            return $this->uploadHash($request, $upload);
        }

        if (isset($request->metadata['upload_file_path']) || isset($request->metadata['upload_stream'])) {
            throw new InvalidArgumentException('HTTP upload must be prepared before request signing.');
        }

        if ($request->body === null) {
            return hash('sha256', '');
        }

        // cURL chooses the multipart boundary and wire encoding. Signing the PHP
        // field map would not authenticate the bytes sent over the network.
        if ($request->body instanceof MultipartBody) {
            return 'UNSIGNED-PAYLOAD';
        }

        $payload = $request->body->toCurlPayload();
        if (is_string($payload)) {
            return hash('sha256', $payload);
        }

        return 'UNSIGNED-PAYLOAD';
    }

    /**
     * @param resource $upload
     */
    private function uploadHash(HttpRequest $request, mixed $upload): string
    {
        $size = $request->metadata['upload_size'] ?? null;
        $offset = $request->metadata['upload_offset'] ?? null;
        if (!is_int($size) || $size < 0 || !is_int($offset) || $offset < 0) {
            throw new InvalidArgumentException('Prepared HTTP upload metadata is invalid.');
        }

        $originalOffset = ftell($upload);
        if (!is_int($originalOffset) || fseek($upload, $offset) !== 0) {
            throw new InvalidArgumentException('Unable to position HTTP upload stream for signing.');
        }

        $context = hash_init('sha256');
        $remaining = $size;

        try {
            while ($remaining > 0) {
                $chunk = fread($upload, min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    throw new InvalidArgumentException('HTTP upload ended before the declared upload size.');
                }

                hash_update($context, $chunk);
                $remaining -= strlen($chunk);
            }
        } finally {
            if (fseek($upload, $originalOffset) !== 0) {
                throw new InvalidArgumentException('Unable to restore HTTP upload stream after signing.');
            }
        }

        return hash_final($context);
    }
}
