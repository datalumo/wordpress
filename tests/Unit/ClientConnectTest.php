<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;

it('calls org-less /me when the organisation id is empty', function () {
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_json_encode')->alias(fn ($value) => json_encode($value));
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"organisation":{"id":"org-1"}}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');

    $seen = null;
    Functions\when('wp_remote_request')->alias(function (string $url) use (&$seen) {
        $seen = $url;

        return ['response' => ['code' => 200], 'body' => '{"organisation":{"id":"org-1"}}'];
    });

    $result = (new Client())->me('', 'plain-token');

    expect($seen)->toBe('https://datalumo.app/api/v1/me')
        ->and($result['organisation']['id'])->toBe('org-1');
});

it('keeps the org-scoped /me path when an organisation id is given', function () {
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_json_encode')->alias(fn ($value) => json_encode($value));
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"organisation":{"id":"org-1"}}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');

    $seen = null;
    Functions\when('wp_remote_request')->alias(function (string $url) use (&$seen) {
        $seen = $url;

        return ['response' => ['code' => 200], 'body' => '{"organisation":{"id":"org-1"}}'];
    });

    (new Client())->me('org-1', 'plain-token');

    expect($seen)->toBe('https://datalumo.app/api/v1/org-1/me');
});

it('parses a compound widget key and rejects a secret', function () {
    Functions\when('esc_html__')->returnArg(1);

    expect((new Client())->parseWidgetKey('org-1/widget-2'))->toBe(['org-1', 'widget-2'])
        ->and(fn () => (new Client())->parseWidgetKey('dl_secret'))
        ->toThrow(ApiException::class);
});
