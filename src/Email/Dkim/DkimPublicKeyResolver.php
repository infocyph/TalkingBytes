<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Dkim;

interface DkimPublicKeyResolver
{
    public function resolve(string $domain, string $selector): ?string;
}
