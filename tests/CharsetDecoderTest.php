<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Parser\CharsetDecoder;

it('decodes common charset aliases to utf8', function (): void {
    $decoder = new CharsetDecoder;
    if (! function_exists('iconv') && ! function_exists('mb_convert_encoding')) {
        expect($decoder->toUtf8('plain', 'ISO-8859-1'))->toBe('plain');

        return;
    }

    $cases = [
        ['charset' => 'ISO-8859-1', 'text' => 'Café'],
        ['charset' => 'ISO-8859-15', 'text' => 'Prix €'],
        ['charset' => 'WINDOWS-1252', 'text' => 'Résumé'],
        ['charset' => 'KOI8-R', 'text' => 'Привет'],
        ['charset' => 'SHIFT_JIS', 'text' => 'テスト'],
    ];

    foreach ($cases as $case) {
        $bytes = null;
        if (function_exists('iconv')) {
            $bytes = iconv('UTF-8', $case['charset'].'//IGNORE', $case['text']);
        } elseif (function_exists('mb_convert_encoding')) {
            $bytes = mb_convert_encoding($case['text'], $case['charset'], 'UTF-8');
        }

        if (! is_string($bytes) || $bytes === '') {
            continue;
        }

        expect($decoder->toUtf8($bytes, $case['charset']))->toBe($case['text']);
    }
});

it('uses configured fallback charset when source charset is unknown', function (): void {
    if (! function_exists('iconv') && ! function_exists('mb_convert_encoding')) {
        expect((new CharsetDecoder('WINDOWS-1252'))->toUtf8('plain', 'X-UNKNOWN-CHARSET'))->toBe('plain');

        return;
    }

    $source = function_exists('iconv')
        ? iconv('UTF-8', 'WINDOWS-1252//IGNORE', 'Résumé')
        : mb_convert_encoding('Résumé', 'WINDOWS-1252', 'UTF-8');

    $decoder = new CharsetDecoder('WINDOWS-1252');
    if (! is_string($source) || $source === '') {
        expect($decoder->toUtf8('plain', 'X-UNKNOWN-CHARSET'))->toBe('plain');

        return;
    }

    expect($decoder->toUtf8($source, 'X-UNKNOWN-CHARSET'))->toBe('Résumé');
});

it('supports additional charset aliases and handles invalid byte payloads safely', function (): void {
    $decoder = new CharsetDecoder('WINDOWS-1252');
    if (! function_exists('iconv') && ! function_exists('mb_convert_encoding')) {
        expect($decoder->toUtf8("\xFF\xFE\xFA", 'X-UNKNOWN'))->toBeString();

        return;
    }
    $cases = [
        ['charset' => 'CP850', 'text' => 'Cafe'],
        ['charset' => 'GB18030', 'text' => '中文'],
        ['charset' => 'BIG5', 'text' => '中文'],
    ];

    foreach ($cases as $case) {
        $bytes = null;
        if (function_exists('iconv')) {
            $bytes = iconv('UTF-8', $case['charset'].'//IGNORE', $case['text']);
        } elseif (function_exists('mb_convert_encoding')) {
            $bytes = mb_convert_encoding($case['text'], $case['charset'], 'UTF-8');
        }

        if (! is_string($bytes) || $bytes === '') {
            continue;
        }

        expect($decoder->toUtf8($bytes, $case['charset']))->toBe($case['text']);
    }

    $invalidBytes = "\xFF\xFE\xFA";
    $decoded = $decoder->toUtf8($invalidBytes, 'X-UNKNOWN');
    expect($decoded)->toBeString();
});

it('restores the error handler stack and original error mask after charset fallback', function (): void {
    $outer = static fn(): bool => true;
    $calls = [];
    $inner = static function (int $severity) use (&$calls): bool {
        $calls[] = $severity;
        return true;
    };
    $reporting = error_reporting(E_USER_WARNING);
    set_error_handler($outer);
    set_error_handler($inner, E_USER_WARNING);
    try {
        for ($i = 0; $i < 3; $i++) {
            (new CharsetDecoder())->toUtf8('text', 'INVALID-CHARSET-AUDIT');
        }
        trigger_error('excluded notice', E_USER_NOTICE);
        trigger_error('included warning', E_USER_WARNING);
        restore_error_handler();
        $after = set_error_handler($outer);
        restore_error_handler();
    } finally {
        error_reporting($reporting);
        // Unwind even a broken implementation so the regression cannot pollute other tests.
        for ($i = 0; $i < 20; $i++) {
            $current = set_error_handler($outer);
            restore_error_handler();
            restore_error_handler();
            if ($current === $outer) {
                break;
            }
        }
    }
    expect($after)->toBe($outer)->and($calls)->toBe([E_USER_WARNING]);
});
