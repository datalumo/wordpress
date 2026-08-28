<?php

use Datalumo\Wp\Support\Credentials;

it('recognises a compound widget key', function () {
    expect(Credentials::looksLikeWidgetKey('org-1/widget-2'))->toBeTrue();
});

it('does not treat a secret as a widget key', function () {
    expect(Credentials::looksLikeWidgetKey('dl_abc123'))->toBeFalse();
});

it('does not treat a bare token as a widget key', function () {
    expect(Credentials::looksLikeWidgetKey('1|plain-api-token'))->toBeFalse();
});

it('recognises a dl_ secret', function () {
    expect(Credentials::looksLikeSecret('dl_abc123'))->toBeTrue()
        ->and(Credentials::looksLikeSecret('1|plain-api-token'))->toBeFalse();
});
