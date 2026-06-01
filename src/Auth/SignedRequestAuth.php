<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Closure;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use Infocyph\TalkingBytes\Signing\RequestSignerInterface;

final readonly class SignedRequestAuth implements AuthenticatorInterface
{
    /**
     * @param Closure():int $clock
     * @param Closure():string $nonceGenerator
     */
    public function __construct(
        private RequestSignerInterface $signer,
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
        $timestamp = (string) (($this->clock ?? time(...))());
        $nonce = ($this->nonceGenerator ?? static fn(): string => bin2hex(random_bytes(16)))();

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

        $payload = $request->body->toCurlPayload();
        if (is_string($payload)) {
            return hash('sha256', $payload);
        }

        return 'UNSIGNED-PAYLOAD';
    }
}
