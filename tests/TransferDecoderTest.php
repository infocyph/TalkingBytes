<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\Parser\TransferDecoder;

it('preserves identity-encoded MIME bytes while enforcing decoded size limits', function (?string $encoding, string $body): void {
    $decoder = new TransferDecoder();
    $size = strlen($body);

    expect($decoder->decode($body, $encoding))->toBe($body)
        ->and($decoder->decode($body, $encoding, $size))->toBe($body);
    expect(fn() => $decoder->decode($body, $encoding, $size - 1))
        ->toThrow(EmailParseException::class, 'Decoded MIME body exceeds limit');
})->with([
    '7bit' => ['7bit', "ASCII\r\n"],
    '8bit' => ['8bit', "caf\xe9\r\n"],
    'binary' => ['binary', "\0\xff\r\n"],
    'missing encoding' => [null, "ASCII\r\n"],
    'empty encoding' => ['', "ASCII\r\n"],
]);

it('preserves MIME Base64 whitespace padding and partial-quartet behavior', function (string $encoded, string $expected): void {
    $decoder = new TransferDecoder();

    expect($decoder->decode($encoded, 'base64'))->toBe($expected)
        ->and($decoder->decode($encoded, 'base64', strlen($expected)))->toBe($expected);
})->with([
    'empty' => ['', ''],
    'whitespace only' => [" \t\r\n\v\f", ''],
    'two-character tail' => ['YQ', 'a'],
    'three-character tail' => ['YWI', 'ab'],
    'double padding' => ['YQ==', 'a'],
    'single padding' => ['YWI=', 'ab'],
    'complete quartet' => ['YWJj', 'abc'],
    'whitespace inside quartet' => ["\tY W\vJ\fj\r\n", 'abc'],
    'separately padded quartets' => ['YQ==Yg==', 'ab'],
    'binary bytes' => ['AAECA//+', "\0\1\2\3\xff\xfe"],
]);

it('rejects invalid MIME Base64 in complete quartets and tails', function (string $encoded): void {
    expect(fn() => (new TransferDecoder())->decode($encoded, 'base64'))
        ->toThrow(EmailParseException::class, 'MIME body contains invalid base64 data.');
})->with([
    'single-character tail' => ['Y'],
    'invalid padding count' => ['Y==='],
    'padding inside quartet' => ['Y=Q='],
    'invalid full quartet' => ['YW!j'],
    'invalid tail' => ['YWJj$'],
    'incomplete trailing quartet' => ['YWJjY'],
    'NUL byte' => ["\0YWJj"],
    'non-ASCII byte' => ["\x80YWJj"],
]);

it('enforces decoded size limits for padded complete and trailing quartets', function (string $encoded, int $limit): void {
    expect(fn() => (new TransferDecoder())->decode($encoded, 'base64', $limit))
        ->toThrow(EmailParseException::class, sprintf('Decoded MIME body exceeds limit (%d bytes).', $limit));
})->with([
    'complete quartet' => ['YWJj', 2],
    'padded quartet' => ['YQ==', 0],
    'trailing quartet' => ['YWJjZA', 3],
    'empty zero limit' => ['YQ', 0],
]);

it('rejects an exceeded decoded limit before scanning a malformed suffix', function (): void {
    expect(fn() => (new TransferDecoder())->decode('YWJj$', 'base64', 2))
        ->toThrow(EmailParseException::class, 'Decoded MIME body exceeds limit (2 bytes).');
});
