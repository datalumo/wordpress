<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Api\Client;

it('sends track false on a widget search', function () {
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_json_encode')->alias(fn ($value) => json_encode($value));
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"data":[]}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');
    Functions\when('home_url')->justReturn('https://example.com');

    $body = null;
    Functions\when('wp_remote_request')->alias(function (string $url, array $args) use (&$body) {
        $body = json_decode((string) $args['body'], true);

        return ['response' => ['code' => 200], 'body' => '{"data":[]}'];
    });

    (new Client())->search('org-1/widget-2', 'voor JO13', 20, track: false);

    expect($body)->toBe([
        'query' => 'voor JO13',
        'limit' => 20,
        'track' => false,
    ]);
});

it('omits track when the caller does not set it', function () {
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_json_encode')->alias(fn ($value) => json_encode($value));
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"data":[]}');
    Functions\when('wp_remote_retrieve_header')->justReturn('');
    Functions\when('home_url')->justReturn('https://example.com');

    $body = null;
    Functions\when('wp_remote_request')->alias(function (string $url, array $args) use (&$body) {
        $body = json_decode((string) $args['body'], true);

        return ['response' => ['code' => 200], 'body' => '{"data":[]}'];
    });

    (new Client())->search('org-1/widget-2', 'passing', 10);

    expect($body)->toBe([
        'query' => 'passing',
        'limit' => 10,
    ]);
});
