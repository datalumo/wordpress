<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Embed\Embed;

it('sends no page context off a singular view', function () {
    Functions\when('is_product')->justReturn(false);
    Functions\when('is_singular')->justReturn(false);
    Functions\when('apply_filters')->returnArg(2);

    expect((new Embed())->pageContext())->toBe([]);
});

it('puts the current page id in chat context on a singular view', function () {
    Functions\when('is_product')->justReturn(false);
    Functions\when('is_singular')->justReturn(true);
    Functions\when('get_the_ID')->justReturn(42);
    Functions\when('apply_filters')->returnArg(2);

    expect((new Embed())->pageContext())->toBe([
        'page_id' => '42',
    ]);
});

it('puts the current product id in chat context on a product page', function () {
    Functions\when('is_product')->justReturn(true);
    Functions\when('is_singular')->justReturn(true);
    Functions\when('get_the_ID')->justReturn(1842);
    Functions\when('wc_get_product')->justReturn(new class
    {
        public function get_sku(): string
        {
            return 'VNECK-BLU';
        }
    });
    Functions\when('apply_filters')->returnArg(2);

    expect((new Embed())->pageContext())->toBe([
        'page_id' => '1842',
        'product_id' => '1842',
        'sku' => 'VNECK-BLU',
    ]);
});
