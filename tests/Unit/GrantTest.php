<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Connect\Grant;
use Datalumo\Wp\Support\Options;

it('builds a start URL from the plugin base, site host, and admin callback', function () {
    Functions\when('home_url')->justReturn('https://shop.example/');
    Functions\when('admin_url')->alias(fn (string $path) => 'https://shop.example/wp-admin/'.$path);
    Functions\when('wp_parse_url')->alias(fn (string $url, int $component = -1) => parse_url($url, $component));

    expect(Grant::startUrl('https://datalumo.app', 'state-1'))->toBe(
        'https://datalumo.app/integrations/wordpress?'.http_build_query([
            'site' => 'shop.example',
            'site_url' => 'https://shop.example/',
            'return' => 'https://shop.example/wp-admin/admin-post.php?action=datalumo_oauth_callback',
            'cancel' => 'https://shop.example/wp-admin/options-general.php?page=datalumo&tab=connection',
            'state' => 'state-1',
        ]),
    );
});

it('maps an exchange payload onto stored options', function () {
    Grant::apply([
        'token' => 'tok_1',
        'organisation' => ['id' => 'org_1', 'name' => 'Acme'],
        'sources' => [['id' => 'src_1', 'name' => 'shop.example']],
        'source' => ['id' => 'src_1', 'name' => 'shop.example'],
        'chatbot' => ['widget_key' => 'org_1/chat_1', 'signing_secret' => 'dl_secret'],
        'search' => ['widget_key' => 'org_1/search_1'],
    ]);

    expect(Options::get('api_token'))->toBe('tok_1')
        ->and(Options::get('organisation.id'))->toBe('org_1')
        ->and(Options::get('setup_pending'))->toBeTrue()
        ->and(Options::get('setup_source_id'))->toBe('src_1')
        ->and(Options::get('connected_via'))->toBe('grant')
        ->and(Options::get('chatbot.widget_key'))->toBe('org_1/chat_1')
        ->and(Options::get('chatbot.signing_secret'))->toBe('dl_secret')
        ->and(Options::get('search_box.widget_key'))->toBe('org_1/search_1')
        ->and(Options::get('enhanced.widget_key'))->toBe('org_1/search_1')
        ->and(Options::get('syncs.0.source_id'))->toBe('src_1')
        ->and(Options::get('syncs.0.post_types'))->toBe(['post', 'page']);
});

it('sends the browser to Datalumo with wp_redirect so an off-site host is not rewritten', function () {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('check_admin_referer')->justReturn(true);
    Functions\when('set_transient')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(1);
    Functions\when('esc_url_raw')->returnArg(1);
    Functions\when('wp_unslash')->returnArg(1);
    Functions\when('home_url')->justReturn('http://datalumo-wp.test/');
    Functions\when('admin_url')->alias(fn (string $path) => 'http://datalumo-wp.test/wp-admin/'.$path);
    Functions\when('wp_parse_url')->alias(fn (string $url, int $component = -1) => parse_url($url, $component));

    $_POST = ['api_url' => 'https://dl.test'];

    $redirected = null;
    Functions\expect('wp_redirect')->once()->andReturnUsing(function (string $url) use (&$redirected): void {
        $redirected = $url;
        throw new RuntimeException('redirect');
    });
    Functions\expect('wp_safe_redirect')->never();

    try {
        Grant::start();
        expect(false)->toBeTrue();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('redirect');
    }

    expect($redirected)->toStartWith('https://dl.test/integrations/wordpress?');
});

it('rejects a callback when the stored state does not match', function () {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('get_current_user_id')->justReturn(1);
    Functions\when('get_transient')->justReturn('expected-state');
    Functions\when('delete_transient')->justReturn(true);
    Functions\when('sanitize_text_field')->returnArg(1);
    Functions\when('wp_unslash')->returnArg(1);
    Functions\when('wp_create_nonce')->justReturn('nonce');
    Functions\when('admin_url')->justReturn('https://shop.example/wp-admin/options-general.php');
    Functions\when('add_query_arg')->alias(fn (array $args, string $url) => $url.'?'.http_build_query($args));

    $redirected = null;
    Functions\when('wp_safe_redirect')->alias(function (string $url) use (&$redirected): void {
        $redirected = $url;
        throw new RuntimeException('redirect');
    });

    $_GET = ['state' => 'wrong-state', 'code' => 'abc'];

    try {
        Grant::callback();
        expect(false)->toBeTrue();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('redirect');
    }

    expect($redirected)->toContain('grant_failed')
        ->and($redirected)->not->toContain('connected');
});
