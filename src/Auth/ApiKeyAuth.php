<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use InvalidArgumentException;

final readonly class ApiKeyAuth implements AuthenticatorInterface
{
    public function __construct(
        private string $key,
        private string $value,
        private bool $inQuery = false,
    ) {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException('API key name must not be empty.');
        }

        if ($this->value === '') {
            throw new InvalidArgumentException('API key value must not be empty.');
        }

        if (!$this->inQuery) {
            HeaderBag::assertValidHeaderName($this->key);
        }
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        if ($this->inQuery) {
            return $request->query($this->key, $this->value);
        }

        return $request->header($this->key, $this->value);
    }
}
