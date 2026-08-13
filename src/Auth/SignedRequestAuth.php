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
}
