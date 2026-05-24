<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum DkimAlgorithm: string
{
    case Ed25519Sha256 = 'ed25519-sha256';

    case RsaSha256 = 'rsa-sha256';
}
