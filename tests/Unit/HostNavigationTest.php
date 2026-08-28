<?php

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Datalumo\Wp\Integration\HostNavigation;

if (! defined('DATALUMO_URL')) {
    define('DATALUMO_URL', 'https://example.com/wp-content/plugins/datalumo/');
}

if (! defined('DATALUMO_VERSION')) {
    define('DATALUMO_VERSION', '0.0.15');
}

function hostNav(): HostNavigation
{
    Functions\when('home_url')->justReturn('https://shop.test/');

    return new HostNavigation();
}

it('registers the ajax handlers and script hook', function () {
    (new HostNavigation())->register();

    expect(Actions\has('wp_enqueue_scripts'))->not->toBeFalse()
        ->and(Actions\has('wp_ajax_datalumo_host_navigation'))->not->toBeFalse()
        ->and(Actions\has('wp_ajax_nopriv_datalumo_host_navigation'))->not->toBeFalse();
});

it('resolves the WooCommerce cart and checkout urls', function () {
    Functions\when('wc_get_cart_url')->justReturn('https://shop.test/cart/');
    Functions\when('wc_get_checkout_url')->justReturn('https://shop.test/checkout/');

    $nav = hostNav();

    expect($nav->resolveUrl('view_cart', []))->toBe('https://shop.test/cart/')
        ->and($nav->resolveUrl('open_checkout', []))->toBe('https://shop.test/checkout/');
});

it('rejects an off-site cart url', function () {
    Functions\when('wc_get_cart_url')->justReturn('https://evil.test/cart/');

    expect(hostNav()->resolveUrl('view_cart', []))->toBeNull();
});

it('opens a published page by id', function () {
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_status' => 'publish']);
    Functions\when('get_permalink')->justReturn('https://shop.test/about/');

    expect(hostNav()->publishedUrlFromPayload(['page_id' => '42']))->toBe('https://shop.test/about/');
});

it('does not open a draft page', function () {
    Functions\when('get_post')->justReturn((object) ['ID' => 42, 'post_status' => 'draft']);
    Functions\when('get_permalink')->justReturn('https://shop.test/?p=42&preview=true');

    expect(hostNav()->publishedUrlFromPayload(['page_id' => '42']))->toBeNull();
});

it('opens a same-site url and rejects javascript or off-site urls', function () {
    $nav = hostNav();

    expect($nav->publishedUrlFromPayload(['url' => 'https://shop.test/contact/']))->toBe('https://shop.test/contact/')
        ->and($nav->isSameSiteUrl('javascript:alert(1)'))->toBeFalse()
        ->and($nav->isSameSiteUrl('https://evil.test/contact/'))->toBeFalse();
});

it('opens a page by slug', function () {
    Functions\when('get_page_by_path')->justReturn((object) ['ID' => 9]);
    Functions\when('get_post')->justReturn((object) ['ID' => 9, 'post_status' => 'publish']);
    Functions\when('get_permalink')->justReturn('https://shop.test/contact/');

    expect(hostNav()->publishedUrlFromPayload(['slug' => 'contact']))->toBe('https://shop.test/contact/');
});

it('appends a form hint when opening another page for start_form', function () {
    Functions\when('get_post')->justReturn((object) ['ID' => 9, 'post_status' => 'publish']);
    Functions\when('get_permalink')->justReturn('https://shop.test/contact/');

    expect(hostNav()->resolveUrl('start_form', [
        'page_id' => '9',
        'form_id' => 'contact',
    ]))->toBe('https://shop.test/contact/?datalumo_form=contact');
});

it('only allows simple selectors', function (string $selector, string $expected) {
    expect(hostNav()->sanitizeSelector($selector))->toBe($expected);
})->with([
    'id' => ['#contact', '#contact'],
    'bare id' => ['contact', 'contact'],
    'class' => ['.wpcf7-form', '.wpcf7-form'],
    'tag and class' => ['form.wpcf7-form', 'form.wpcf7-form'],
    'attribute' => ['form[action*="evil"]', ''],
    'wildcard' => ['*', ''],
    'js proto' => ['javascript:alert(1)', ''],
]);
