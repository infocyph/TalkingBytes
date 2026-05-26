<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\HttpResponse;

it('exposes status group helper predicates', function (): void {
    $ok = new HttpResponse(200, '{}');
    $redirect = new HttpResponse(302, '');
    $clientError = new HttpResponse(404, '');
    $serverError = new HttpResponse(503, '');
    $informational = new HttpResponse(101, '');
    $invalid = new HttpResponse(700, '');

    expect($ok->statusGroup())->toBe(2)
        ->and($ok->isSuccessful())->toBeTrue()
        ->and($ok->isClientError())->toBeFalse();

    expect($redirect->statusGroup())->toBe(3)
        ->and($redirect->isRedirection())->toBeTrue();

    expect($clientError->statusGroup())->toBe(4)
        ->and($clientError->isClientError())->toBeTrue();

    expect($serverError->statusGroup())->toBe(5)
        ->and($serverError->isServerError())->toBeTrue();

    expect($informational->statusGroup())->toBe(1)
        ->and($informational->isInformational())->toBeTrue();

    expect($invalid->statusGroup())->toBeNull()
        ->and($invalid->isSuccessful())->toBeFalse();
});
