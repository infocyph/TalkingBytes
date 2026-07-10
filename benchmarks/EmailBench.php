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
final class EmailBench
{
    private RawEmailBuilder $builder;

    private EmailMessage $message;

    private RawEmailParser $parser;

    private string $rawMultipartEmail;

    public function setUp(): void
    {
        $this->builder = new RawEmailBuilder();
        $this->parser = new RawEmailParser();
        $this->message = EmailMessage::new()
            ->from('sender@example.com', 'Sender Name')
            ->to('alice@example.com', 'bob@example.com')
            ->cc('ops@example.com')
            ->replyTo('reply@example.com')
            ->subject('Quarterly update')
            ->text("Hello team,\n\nThe text part is here.")
            ->html('<p>Hello team,</p><p>The HTML part is here.</p>')
            ->attachData(str_repeat('PDF-DATA-', 64), 'report.pdf', 'application/pdf')
            ->attachInlineData('<svg><rect width="8" height="8"/></svg>', 'logo.svg', 'logo-inline', 'image/svg+xml');
        $this->rawMultipartEmail = $this->createRawMultipartEmail();
    }

    #[Iterations(5)]
    #[Revs(50)]
    public function benchBuildRawEmail(): void
    {
        $this->builder->build($this->message);
    }

    #[Iterations(5)]
    #[Revs(50)]
    public function benchBuildRawEmailToStream(): void
    {
        $this->builder->buildToStream(
            $this->message,
            static function (string $chunk): void {
                unset($chunk);
            },
        );
    }

    #[Iterations(5)]
    #[Revs(50)]
    public function benchParseMultipartEmail(): void
    {
        $this->parser->parse($this->rawMultipartEmail);
    }

    private function createRawMultipartEmail(): string
    {
        $mixedBoundary = 'mixed-bench';
        $alternativeBoundary = 'alt-bench';

        return implode("\r\n", [
            'From: "Sender Name" <sender@example.com>',
            'To: alice@example.com, Bob <bob@example.com>',
            'Cc: ops@example.com',
            'Reply-To: reply@example.com',
            'Subject: Quarterly update',
            sprintf('Content-Type: multipart/mixed; boundary="%s"', $mixedBoundary),
            '',
            sprintf('--%s', $mixedBoundary),
            sprintf('Content-Type: multipart/alternative; boundary="%s"', $alternativeBoundary),
            '',
            sprintf('--%s', $alternativeBoundary),
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            "Hello=20team,\r\n\r\nThe=20text=20part=20is=20here.",
            sprintf('--%s', $alternativeBoundary),
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            '<p>Hello team,</p><p>The HTML part is here.</p>',
            sprintf('--%s--', $alternativeBoundary),
            sprintf('--%s', $mixedBoundary),
            'Content-Type: image/svg+xml; name="logo.svg"',
            'Content-Disposition: inline; filename="logo.svg"',
            'Content-ID: <logo-inline>',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('<svg><rect width="8" height="8"/></svg>'),
            sprintf('--%s', $mixedBoundary),
            'Content-Type: application/pdf; name="report.pdf"',
            'Content-Disposition: attachment; filename="report.pdf"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode(str_repeat('PDF-DATA-', 64)),
            sprintf('--%s--', $mixedBoundary),
            '',
        ]);
    }
}
