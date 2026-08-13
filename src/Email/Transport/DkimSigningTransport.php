<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\DkimConfig;
use Infocyph\TalkingBytes\Email\Dkim\DkimSigner;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

final readonly class DkimSigningTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private DkimConfig $config,
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private DkimSigner $signer = new DkimSigner(),
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $message = $message->prepare();
        $raw = $this->rawEmailBuilder->build($message, includeSubject: true);
        $dkimHeader = $this->signer->buildSignatureHeader($raw->headers, $raw->body, $this->config);
        $dkimValue = trim(substr($dkimHeader, strlen('DKIM-Signature:')));

        return $this->innerTransport->send($message->withDkimSignature($dkimValue));
    }
}
