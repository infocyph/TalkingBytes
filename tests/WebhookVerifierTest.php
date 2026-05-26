<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Webhook\WebhookSignature;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

it('verifies valid webhook signatures', function (): void {
    $payload = '{"id":1}';
    $timestamp = time();
    $signature = (new WebhookSignature('secret'))->buildHeader($payload, $timestamp);

    $verifier = new WebhookVerifier('secret');
    $result = $verifier->verifyResult($payload, $signature);

    expect($result->valid)->toBeTrue();
    expect($result->signaturePresent)->toBeTrue();
    expect($result->signaturePrefix)->toHaveLength(8);
    expect($verifier->verify($payload, $signature))->toBeTrue();
});

it('rejects malformed signature and timestamp values with explicit reasons', function (): void {
    $verifier = new WebhookVerifier('secret');

    expect($verifier->verifyResult('{"x":1}', '')->reason)->toBe('missing_signature_header');
    expect($verifier->verifyResult('{"x":1}', 't=abc,v1=abcdef')->reason)->toBe('malformed_signature');
    expect($verifier->verifyResult('{"x":1}', 't=1,v1=nothex')->reason)->toBe('malformed_signature');
    expect($verifier->verifyResult('{"x":1}', 'v1=abcd', 'bad')->reason)->toBe('invalid_timestamp');
});

it('rejects expired and mismatched signatures', function (): void {
    $payload = '{"id":1}';
    $oldTimestamp = time() - 1000;

    $oldSignature = (new WebhookSignature('secret'))->buildHeader($payload, $oldTimestamp);
    $verifier = new WebhookVerifier('secret', 300);

    expect($verifier->verifyResult($payload, $oldSignature)->reason)->toBe('expired_timestamp');

    $wrong = (new WebhookSignature('wrong'))->buildHeader($payload, time());
    expect($verifier->verifyResult($payload, $wrong)->reason)->toBe('signature_mismatch');
});
