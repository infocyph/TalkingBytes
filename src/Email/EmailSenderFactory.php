<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;

final readonly class EmailSenderFactory
{
    public function fake(): Emailer
    {
        return Emailer::fake();
    }

    public function usingLog(LogEmailConfig $config): Emailer
    {
        return Emailer::usingLog($config);
    }

    public function usingMailFunction(): Emailer
    {
        return Emailer::usingMailFunction();
    }

    public function usingNull(): Emailer
    {
        return Emailer::usingNull();
    }

    public function usingSendmail(SendmailConfig $config = new SendmailConfig()): Emailer
    {
        return Emailer::usingSendmail($config);
    }

    public function usingSmtp(SmtpConfig $config): Emailer
    {
        return Emailer::usingSmtp($config);
    }

    public function usingSpool(SpoolConfig $config): Emailer
    {
        return Emailer::usingSpool($config);
    }
}
