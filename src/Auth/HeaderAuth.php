<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use InvalidArgumentException;

final readonly class HeaderAuth implements AuthenticatorInterface
{
    public function __construct(
        private string $header,
        #[\SensitiveParameter]
        private string $value,
    ) {
        if (trim($this->header) === '') {
            throw new InvalidArgumentException('Header auth name must not be empty.');
        }

        HeaderBag::assertValidHeaderName($this->header);

        if ($this->value === '') {
            throw new InvalidArgumentException('Header auth value must not be empty.');
        }
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        return $request->header($this->header, $this->value);
    }
}
