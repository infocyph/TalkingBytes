<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Parser\AuthenticationResultsParser;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

it('contains core usage examples in README', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($readme)->toBeString();
    expect($readme)->toContain('SMTP send');
    expect($readme)->toContain('Sendmail / spool / null transports');
    expect($readme)->toContain('Spool receiver');
    expect($readme)->toContain('IMAP mailbox');
    expect($readme)->toContain('POP3 mailbox');
    expect($readme)->toContain('Bounce parsing');
    expect($readme)->toContain('template(');
    expect($readme)->toContain('Email::events');
    expect($readme)->toContain('bounce.detected');
    expect($readme)->toContain('Performance Notes');
    expect($readme)->toContain('Release Checklist');
    expect($readme)->toContain('UIDL');
    expect($readme)->toContain('MailboxSearch::maxSummaryFetches()');
    expect($readme)->toContain('Authentication-Results parsing');
    expect($readme)->toContain('Extension Policy');
    expect($readme)->toContain('Naming Map');
});

it('keeps release-level README examples syntactically valid in fake-safe mode', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('user@example.com')
        ->subject('README smoke')
        ->text('body');

    $smtp = Emailer::usingSmtp(new SmtpConfig('smtp.example.com'));
    $mail = Emailer::usingMailFunction();
    $null = Emailer::usingNull();
    $imap = Mailbox::usingImap(new ImapConfig('imap.example.com', username: 'u', password: 'p'));
    $pop3 = Pop3Mailbox::usingConfig(new Pop3Config('pop.example.com', username: 'u', password: 'p'));
    $search = MailboxSearch::new()->unseen()->limit(10);
    $auth = (new AuthenticationResultsParser)->parse('mx.example.com; dkim=pass header.d=example.com');
    $parsed = (new RawEmailParser)->parse("From: a@example.com\r\nTo: b@example.com\r\nSubject: S\r\n\r\nBody");
    $bounce = (new BounceParser)->parse($parsed);

    expect($message->headersData()->subject)->toBe('README smoke');
    expect($smtp)->toBeInstanceOf(Emailer::class);
    expect($mail)->toBeInstanceOf(Emailer::class);
    expect($null)->toBeInstanceOf(Emailer::class);
    expect($imap)->toBeInstanceOf(Mailbox::class);
    expect($pop3)->toBeInstanceOf(Pop3Mailbox::class);
    expect($search->limit)->toBe(10);
    expect($auth->passedDkim())->toBeTrue();
    expect($bounce)->toBeNull();
    expect(Email::mailbox()->usingPop3(new Pop3Config('pop.example.com', username: 'u', password: 'p')))
        ->toBeInstanceOf(Pop3Mailbox::class);
});
