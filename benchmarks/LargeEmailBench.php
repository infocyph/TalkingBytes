<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Benchmarks;

use Infocyph\TalkingBytes\Email\EmailMessage;
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

    public function setUp(): void
    {
        $this->builder = new RawEmailBuilder();
        $this->messageOneMegabyte = $this->createMessage(1 * 1024 * 1024);
        $this->messageTenMegabytes = $this->createMessage(10 * 1024 * 1024);
        $this->messageTwentyFiveMegabytes = $this->createMessage(25 * 1024 * 1024);
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
