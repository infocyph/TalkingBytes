<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final readonly class QueryAuth implements AuthenticatorInterface
{
    public function __construct(
        private string $key,
        #[\SensitiveParameter]
        private string $value,
    ) {
        if (trim($this->key) === '') {
            throw new InvalidArgumentException('Query auth key must not be empty.');
        }

        if ($this->value === '') {
            throw new InvalidArgumentException('Query auth value must not be empty.');
        }
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        return $request->query($this->key, $this->value);
    }
}
