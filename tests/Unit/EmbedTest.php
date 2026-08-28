<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Embed\Embed;

it('sends no product context off a product page', function () {
    Functions\when('is_product')->justReturn(false);
    Functions\when('apply_filters')->returnArg(2);

    expect((new Embed())->pageContext())->toBe([]);
});

it('puts the current product id in chat context on a product page', function () {
    Functions\when('is_product')->justReturn(true);
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
        'product_id' => '1842',
        'sku' => 'VNECK-BLU',
    ]);
});
