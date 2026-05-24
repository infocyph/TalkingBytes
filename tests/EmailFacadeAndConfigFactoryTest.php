<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

it('builds config objects from arrays', function (): void {
    $smtp = SmtpConfig::fromArray([
        'host' => 'smtp.example.com',
        'port' => 2525,
        'security' => SmtpSecurity::Ssl->value,
        'credentials' => ['username' => 'user', 'password' => 'pass'],
        'timeoutSeconds' => 15,
    ]);

    $sendmail = SendmailConfig::fromArray([
        'path' => '/usr/sbin/sendmail',
        'extraArguments' => ['-t', '-i'],
        'timeoutSeconds' => 20,
    ]);

    $spool = SpoolConfig::fromArray([
        'directory' => sys_get_temp_dir(),
        'writeMetadata' => false,
        'extension' => 'eml',
    ]);

    $imap = ImapConfig::fromArray([
        'host' => 'imap.example.com',
        'port' => 993,
        'security' => ImapSecurity::Ssl->value,
        'username' => 'u',
        'password' => 'p',
    ]);

    $pop3 = Pop3Config::fromArray([
        'host' => 'pop.example.com',
        'port' => 110,
        'security' => Pop3Security::None->value,
        'username' => 'u',
        'password' => 'p',
    ]);

    $log = LogEmailConfig::fromArray([
        'directory' => sys_get_temp_dir(),
        'dailyFiles' => true,
        'filenamePrefix' => 'mail',
    ]);

    expect($smtp->host)->toBe('smtp.example.com');
    expect($smtp->credentials?->username)->toBe('user');
    expect($sendmail->timeoutSeconds)->toBe(20);
    expect($spool->writeMetadata)->toBeFalse();
    expect($imap->security)->toBe(ImapSecurity::Ssl);
    expect($pop3->security)->toBe(Pop3Security::None);
    expect($log->filenamePrefix)->toBe('mail');
});

it('exposes unified email facade factories', function (): void {
    $sender = Email::sender()->usingNull();
    $receiver = Email::receiver()->usingSpool(new SpoolConfig(directory: sys_get_temp_dir()));
    $pop3 = Email::mailbox()->usingPop3(new Pop3Config(
        host: 'pop.example.com',
        security: Pop3Security::None,
        username: 'u',
        password: 'p',
    ));

    expect($sender)->toBeInstanceOf(Emailer::class);
    expect($receiver)->toBeInstanceOf(SpoolEmailReceiver::class);
    expect($pop3)->toBeInstanceOf(Pop3Mailbox::class);
    expect(method_exists(Mailbox::class, 'usingPop3'))->toBeFalse();
});
