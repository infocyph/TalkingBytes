<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Auth;

use Infocyph\TalkingBytes\Http\HttpRequest;

interface AuthenticatorInterface
{
    public function apply(HttpRequest $request): HttpRequest;
}
