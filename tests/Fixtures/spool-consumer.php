<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

$directory = $argv[1] ?? '';
$mode = $argv[2] ?? 'consume';
$marker = $argv[3] ?? '';
$output = $argv[4] ?? '';

$parser = new class($mode, $marker) implements EmailParser
{
    public function __construct(
        private string $mode,
        private string $marker,
    ) {}

    public function parse(string $rawEmail, array $metadata = []): ParsedEmail
    {
        if ($this->marker !== '') {
            file_put_contents($this->marker, 'claimed');
        }

        if ($this->mode === 'crash') {
            exit(0);
        }

        if ($this->mode === 'hold') {
            usleep(300_000);
        }

        return (new RawEmailParser())->parse($rawEmail, $metadata);
    }
};

$email = (new SpoolEmailReceiver(
    new SpoolConfig($directory, lockBeforeRead: true),
    parser: $parser,
    deleteAfterRead: true,
))->receiveParsed();

file_put_contents($output, json_encode([
    'subject' => $email?->subject,
], JSON_THROW_ON_ERROR));
