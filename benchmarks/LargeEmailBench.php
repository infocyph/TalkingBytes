<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

#[BeforeMethods('setUp')]
final class LargeEmailBench
{
    private RawEmailBuilder $builder;

    private EmailMessage $messageOneMegabyte;

    private EmailMessage $messageTenMegabytes;

    private EmailMessage $messageTwentyFiveMegabytes;

    private RawEmailParser $parser;

    private string $rawBase64Email;

    public function setUp(): void
    {
        $this->builder = new RawEmailBuilder();
        $this->messageOneMegabyte = $this->createMessage(1 * 1024 * 1024);
        $this->messageTenMegabytes = $this->createMessage(10 * 1024 * 1024);
        $this->messageTwentyFiveMegabytes = $this->createMessage(25 * 1024 * 1024);
        $this->parser = new RawEmailParser();
        $this->rawBase64Email = implode("\r\n", [
            'From: sender@example.com',
            'To: recipient@example.com',
            'Subject: Large inbound Base64 attachment',
            'Content-Type: multipart/mixed; boundary="inbound-bench"',
            '',
            '--inbound-bench',
            'Content-Type: application/octet-stream',
            'Content-Disposition: attachment; filename="payload.bin"',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode(str_repeat('email-payload-0123456789-', 32768)), 76, "\r\n"),
            '--inbound-bench--',
            '',
        ]);
    }

    #[Iterations(3)]
    #[Revs(1)]
    public function benchBuildOneMegabyteEmailToStream(): void
    {
        $this->buildToStream($this->messageOneMegabyte);
    }

    #[Iterations(3)]
    #[Revs(1)]
    public function benchBuildTenMegabyteEmailToStream(): void
    {
        $this->buildToStream($this->messageTenMegabytes);
    }

    #[Iterations(3)]
    #[Revs(1)]
    public function benchBuildTwentyFiveMegabyteEmailToStream(): void
    {
        $this->buildToStream($this->messageTwentyFiveMegabytes);
    }

    #[Iterations(3)]
    #[Revs(1)]
    public function benchParseBase64Attachment(): void
    {
        $this->parser->parse($this->rawBase64Email);
    }

    private function buildToStream(EmailMessage $message): void
    {
        $this->builder->buildToStream(
            $message,
            static function (string $chunk): void {
                unset($chunk);
            },
        );
    }

    private function createMessage(int $attachmentBytes): EmailMessage
    {
        return EmailMessage::new()
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject(sprintf('Large email benchmark (%d bytes)', $attachmentBytes))
            ->text('Large attachment benchmark payload.')
            ->attachData(
                str_repeat('x', $attachmentBytes),
                'payload.bin',
                maxSizeBytes: $attachmentBytes,
            );
    }
}
