<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final readonly class BasicAuth implements AuthenticatorInterface
{
    public function __construct(
        #[\SensitiveParameter]
        private string $username,
        #[\SensitiveParameter]
        private string $password,
    ) {
        if (trim($this->username) === '') {
            throw new InvalidArgumentException('Basic auth username must not be empty.');
        }

        if ($this->password === '') {
            throw new InvalidArgumentException('Basic auth password must not be empty.');
        }
    }

    public function apply(HttpRequest $request): HttpRequest
    {
        $token = base64_encode($this->username . ':' . $this->password);

        return $request->header('Authorization', 'Basic ' . $token);
    }
}
