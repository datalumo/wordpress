<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Sync\PagePreparer;

beforeEach(function () {
    // apply_filters returns the value being filtered (its 2nd arg): indexable
    // stays true, render_shortcodes stays false, and the payload passes through.
    Functions\when('apply_filters')->returnArg(2);

    // Content pipeline: leave the body untouched so a title assertion is isolated.
    Functions\when('strip_shortcodes')->returnArg(1);
    Functions\when('wpautop')->returnArg(1);
    Functions\when('do_blocks')->returnArg(1);
    Functions\when('wptexturize')->returnArg(1);
    Functions\when('wp_filter_content_tags')->returnArg(1);
    Functions\when('wp_strip_all_tags')->returnArg(1);

    Functions\when('get_the_terms')->justReturn([]);
    Functions\when('wp_list_pluck')->justReturn([]);
    Functions\when('get_the_author_meta')->justReturn('Jane');
    Functions\when('get_post_time')->justReturn('2004-01-01T00:00:00+00:00');
    Functions\when('get_post_modified_time')->justReturn('2004-01-02T00:00:00+00:00');
    Functions\when('get_permalink')->justReturn('https://example.com/post');
    Functions\when('get_the_post_thumbnail_url')->justReturn(false);
});

it('decodes HTML entities in the post title', function () {
    Functions\when('get_the_title')->justReturn('Kleine&#8217; Douglas redde het niet');

    $payload = (new PagePreparer())->prepare(new WP_Post());

    expect($payload['name'])->toBe('Kleine’ Douglas redde het niet')
        ->and($payload['content_mime'])->toBe('text/html')
        ->and($payload['thumbnail'])->toBeNull();
});

it('upgrades an http featured image to https', function () {
    Functions\when('get_the_title')->justReturn('Tote');
    Functions\when('get_the_post_thumbnail_url')->justReturn('http://datalumo-fresh.test/wp-content/uploads/tote.png');

    $payload = (new PagePreparer())->prepare(new WP_Post());

    expect($payload['thumbnail'])->toBe('https://datalumo-fresh.test/wp-content/uploads/tote.png');
});

it('includes the featured image as a thumbnail', function () {
    Functions\when('get_the_title')->justReturn('Guide');
    Functions\when('get_the_post_thumbnail_url')->justReturn('https://example.com/wp-content/uploads/guide-1024x768.jpg');

    $payload = (new PagePreparer())->prepare(new WP_Post());

    expect($payload['thumbnail'])->toBe('https://example.com/wp-content/uploads/guide-1024x768.jpg');
});

it('skips a non-http featured image', function () {
    Functions\when('get_the_title')->justReturn('Guide');
    Functions\when('get_the_post_thumbnail_url')->justReturn('javascript:alert(1)');

    $payload = (new PagePreparer())->prepare(new WP_Post());

    expect($payload['thumbnail'])->toBeNull();
});

it('falls back when block rendering fatals', function () {
    Functions\when('get_the_title')->justReturn('Product');
    Functions\when('do_blocks')->alias(function (): string {
        throw new Error('Call to undefined function Automattic\WooCommerce\Blocks\Domain\Services\wc_get_notices()');
    });

    $post = new WP_Post();
    $post->post_content = '<p>Ceramic mug</p>';

    expect((new PagePreparer())->prepare($post)['content'])->toBe('<p>Ceramic mug</p>');
});

it('skips a post with no content', function () {
    Functions\when('get_the_title')->justReturn('Empty');
    Functions\when('wp_strip_all_tags')->justReturn('');

    $post = new WP_Post();
    $post->post_content = '';

    expect((new PagePreparer())->prepare($post))->toBeNull();
});

it('keeps a payload the filter filled after an empty body', function () {
    Functions\when('get_the_title')->justReturn('Mug');
    Functions\when('apply_filters')->alias(function (string $hook, mixed $value) {
        if ($hook === 'datalumo_page_payload' && is_array($value)) {
            $value['content'] = '<p>A ceramic mug.</p>';
        }

        return $value;
    });

    $post = new WP_Post();
    $post->post_content = '';

    expect((new PagePreparer())->prepare($post)['content'])->toBe('<p>A ceramic mug.</p>');
});
