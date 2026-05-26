<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Mailbox\ImapModifiedUtf7;
use Infocyph\TalkingBytes\Email\Mailbox\ImapResponse;
use Infocyph\TalkingBytes\Email\Mailbox\ImapResponseParser;

it('parses imap list details with escaped values and modified utf7 names', function (): void {
    $encodedName = ImapModifiedUtf7::encode('Projects/日本語');

    $response = new ImapResponse(
        tag: 'A0001',
        status: 'OK',
        lines: [
            '* LIST (\\HasNoChildren) "/" "INBOX"',
            sprintf('* LIST (\\HasChildren) NIL "%s"', str_replace('"', '\\"', $encodedName)),
        ],
        literals: [],
    );

    $parser = new ImapResponseParser;
    $details = $parser->folderDetails($response);

    expect($details)->toHaveCount(2);
    expect($details[0]->path)->toBe('INBOX');
    expect($details[0]->delimiter)->toBe('/');
    expect($details[1]->path)->toBe('Projects/日本語');
    expect($details[1]->delimiter)->toBeNull();
    expect($details[1]->hasChildren())->toBeTrue();
});

it('parses imap list attributes, escaped quotes, and folder names with spaces', function (): void {
    $response = new ImapResponse(
        tag: 'A0010',
        status: 'OK',
        lines: [
            '* LIST (\\Noselect \\HasChildren) "/" "Projects"',
            '* LIST (\\NoInferiors \\HasNoChildren) "/" "Client \\"A\\" Reports"',
            '* LIST (\\HasNoChildren) NIL "Archive 2026"',
        ],
        literals: [],
    );

    $parser = new ImapResponseParser;
    $details = $parser->folderDetails($response);

    expect($details)->toHaveCount(3);
    expect($details[0]->isSelectable())->toBeFalse();
    expect($details[1]->noInferiors())->toBeTrue();
    expect($details[1]->path)->toBe('Client "A" Reports');
    expect($details[2]->path)->toBe('Archive 2026');
    expect($details[2]->delimiter)->toBeNull();
});

it('parses mailbox status including uid validity and uid next', function (): void {
    $response = new ImapResponse(
        tag: 'A0002',
        status: 'OK',
        lines: ['* STATUS "INBOX" (MESSAGES 5 RECENT 1 UNSEEN 3 UIDVALIDITY 42 UIDNEXT 99)'],
        literals: [],
    );

    $parser = new ImapResponseParser;
    $status = $parser->status($response);

    expect($status->messages)->toBe(5);
    expect($status->recent)->toBe(1);
    expect($status->unseen)->toBe(3);
    expect($status->uidValidity)->toBe(42);
    expect($status->uidNext)->toBe(99);
});

it('tolerates missing and unknown fields in mailbox status response', function (): void {
    $response = new ImapResponse(
        tag: 'A0002',
        status: 'OK',
        lines: ['* STATUS "INBOX" (MESSAGES 0 UNKNOWN 9 UIDVALIDITY 7)'],
        literals: [],
    );

    $status = (new ImapResponseParser)->status($response);

    expect($status->messages)->toBe(0);
    expect($status->recent)->toBe(0);
    expect($status->unseen)->toBe(0);
    expect($status->uidValidity)->toBe(7);
    expect($status->uidNext)->toBeNull();
});

it('parses envelope summary fields from fetch response', function (): void {
    $response = new ImapResponse(
        tag: 'A0003',
        status: 'OK',
        lines: [
            '* 1 FETCH (UID 10 FLAGS (\\Seen \\Flagged) ENVELOPE ("Fri, 24 May 2026 12:00:00 +0000" "Envelope Subject" (("Sender Name" NIL "sender" "example.com")) NIL NIL NIL NIL NIL NIL "<env@example.com>"))',
        ],
        literals: [],
    );

    $parser = new ImapResponseParser;
    $summary = $parser->parseEnvelopeSummary($response, 10);

    expect($summary->uid)->toBe(10);
    expect($summary->subject)->toBe('Envelope Subject');
    expect($summary->from)->toBe('Sender Name <sender@example.com>');
    expect($summary->messageId)->toBe('<env@example.com>');
    expect($summary->date?->format('Y-m-d'))->toBe('2026-05-29');
    expect($summary->sequence)->toBe(1);
    expect($summary->flags)->toBe(['\\Seen', '\\Flagged']);
});

it('parses envelope summary tolerantly with nil and invalid fields', function (): void {
    $response = new ImapResponse(
        tag: 'A0003',
        status: 'OK',
        lines: [
            '* 2 FETCH (UID 77 ENVELOPE (NIL NIL NIL NIL NIL NIL NIL NIL NIL NIL) FLAGS ())',
        ],
        literals: [],
    );

    $summary = (new ImapResponseParser)->parseEnvelopeSummary($response, 77);

    expect($summary->uid)->toBe(77);
    expect($summary->subject)->toBeNull();
    expect($summary->from)->toBeNull();
    expect($summary->messageId)->toBeNull();
});

it('parses envelope summary with multiple from addresses and invalid date safely', function (): void {
    $response = new ImapResponse(
        tag: 'A0007',
        status: 'OK',
        lines: [
            '* 3 FETCH (UID 90 ENVELOPE ("invalid-date" "=?UTF-8?B?U3ViamVjdA==?=" (("Primary Sender" NIL "primary" "example.com")("Second Sender" NIL "second" "example.com")) NIL NIL NIL NIL NIL NIL NIL) FLAGS (\\Seen))',
        ],
        literals: [],
    );

    $summary = (new ImapResponseParser)->parseEnvelopeSummary($response, 90);

    expect($summary->uid)->toBe(90);
    expect($summary->subject)->toBe('=?UTF-8?B?U3ViamVjdA==?=');
    expect($summary->from)->toContain('primary@example.com');
    expect($summary->date)->toBeNull();
    expect($summary->flags)->toBe(['\\Seen']);
});

it('maps fetch raw message from first fetch-associated literal with additional literals present', function (): void {
    $response = new ImapResponse(
        tag: 'A0004',
        status: 'OK',
        lines: [
            '* 1 FETCH (BODY.PEEK[HEADER] {17}',
            ' BODY[TEXT] {5}',
            ')',
            'A0004 OK done',
        ],
        literals: [
            "Subject: A\r\n\r\n",
            'HELLO',
        ],
    );

    $parser = new ImapResponseParser;
    $raw = $parser->fetchRawMessage($response);

    expect($raw)->toBe("Subject: A\r\n\r\n");
});

it('resolves section-specific literals from multi-literal fetch responses', function (): void {
    $response = new ImapResponse(
        tag: 'A0005',
        status: 'OK',
        lines: [
            '* 1 FETCH (BODY.PEEK[HEADER] {17}',
            ' BODY.PEEK[TEXT] {5}',
            ' BODY.PEEK[1.2] {4}',
            ')',
            'A0005 OK done',
        ],
        literals: [
            "Subject: A\r\n\r\n",
            'HELLO',
            'QQ==',
        ],
    );

    $parser = new ImapResponseParser;

    expect($parser->fetchSectionLiteral($response, 'BODY.PEEK[HEADER]'))->toBe("Subject: A\r\n\r\n");
    expect($parser->fetchSectionLiteral($response, 'BODY.PEEK[TEXT]'))->toBe('HELLO');
    expect($parser->fetchSectionLiteral($response, 'BODY.PEEK[1.2]'))->toBe('QQ==');
});

it('prefers RFC822 literal for raw message extraction when multiple literals are present', function (): void {
    $response = new ImapResponse(
        tag: 'A0006',
        status: 'OK',
        lines: [
            '* 1 FETCH (BODY.PEEK[HEADER] {17}',
            ' RFC822 {11}',
            ')',
            'A0006 OK done',
        ],
        literals: [
            "Subject: A\r\n\r\n",
            "Body\r\nLine\r\n",
        ],
    );

    $parser = new ImapResponseParser;

    expect($parser->fetchRawMessage($response))->toBe("Body\r\nLine\r\n");
});
