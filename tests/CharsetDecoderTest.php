<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Parser\CharsetDecoder;

it('decodes common charset aliases to utf8', function (): void {
    if (! function_exists('iconv') && ! function_exists('mb_convert_encoding')) {
        $this->markTestSkipped('charset conversion extensions are unavailable.');
    }

    $decoder = new CharsetDecoder;

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
        $this->markTestSkipped('charset conversion extensions are unavailable.');
    }

    $source = function_exists('iconv')
        ? iconv('UTF-8', 'WINDOWS-1252//IGNORE', 'Résumé')
        : mb_convert_encoding('Résumé', 'WINDOWS-1252', 'UTF-8');

    if (! is_string($source) || $source === '') {
        $this->markTestSkipped('Unable to prepare fallback charset test payload.');
    }

    $decoder = new CharsetDecoder('WINDOWS-1252');

    expect($decoder->toUtf8($source, 'X-UNKNOWN-CHARSET'))->toBe('Résumé');
});

it('supports additional charset aliases and handles invalid byte payloads safely', function (): void {
    if (! function_exists('iconv') && ! function_exists('mb_convert_encoding')) {
        $this->markTestSkipped('charset conversion extensions are unavailable.');
    }

    $decoder = new CharsetDecoder('WINDOWS-1252');
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
