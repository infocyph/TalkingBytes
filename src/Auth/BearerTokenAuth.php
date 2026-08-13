<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final readonly class BearerTokenAuth implements AuthenticatorInterface
{
    public function __construct(#[\SensitiveParameter] private string $token)
    {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException('Bearer token must not be empty.');
        }
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        return $request->header('Authorization', 'Bearer ' . $this->token);
    }
}
