<?php

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Datalumo\Wp\Integration\AddToCart;
use Datalumo\Wp\Integration\WooCommerce;

if (! defined('DATALUMO_URL')) {
    define('DATALUMO_URL', 'https://example.com/wp-content/plugins/datalumo/');
}

if (! defined('DATALUMO_VERSION')) {
    define('DATALUMO_VERSION', '0.0.7');
}

it('registers the ajax handlers and script hook', function () {
    (new AddToCart())->register();

    expect(Actions\has('wp_enqueue_scripts'))->not->toBeFalse()
        ->and(Actions\has('wp_ajax_datalumo_add_to_cart'))->not->toBeFalse()
        ->and(Actions\has('wp_ajax_nopriv_datalumo_add_to_cart'))->not->toBeFalse();
});

it('registers add-to-cart when the WooCommerce integration boots', function () {
    (new WooCommerce())->register();

    expect(Actions\has('wp_ajax_datalumo_add_to_cart'))->not->toBeFalse()
        ->and(Actions\has('wp_ajax_nopriv_datalumo_add_to_cart'))->not->toBeFalse();
});

it('localises the frontend script with the event name and nonce', function () {
    Functions\when('apply_filters')->returnArg(2);
    Functions\when('is_product')->justReturn(false);
    Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
    Functions\when('wp_create_nonce')->justReturn('test-nonce');
    Functions\when('wp_enqueue_script')->justReturn(true);

    $localised = null;
    Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localised) {
        $localised = ['handle' => $handle, 'name' => $name, 'data' => $data];

        return true;
    });

    (new AddToCart())->enqueue();

    expect($localised['handle'])->toBe('datalumo-add-to-cart')
        ->and($localised['name'])->toBe('datalumoAddToCart')
        ->and($localised['data']['event'])->toBe('add_to_cart')
        ->and($localised['data']['nonce'])->toBe('test-nonce')
        ->and($localised['data']['productId'])->toBe(0)
        ->and($localised['data']['ajaxUrl'])->toBe('https://example.com/wp-admin/admin-ajax.php');
});

it('localises the current product id on a product page', function () {
    Functions\when('apply_filters')->returnArg(2);
    Functions\when('is_product')->justReturn(true);
    Functions\when('get_the_ID')->justReturn(1842);
    Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
    Functions\when('wp_create_nonce')->justReturn('test-nonce');
    Functions\when('wp_enqueue_script')->justReturn(true);

    $localised = null;
    Functions\when('wp_localize_script')->alias(function ($handle, $name, $data) use (&$localised) {
        $localised = $data;

        return true;
    });

    (new AddToCart())->enqueue();

    expect($localised['productId'])->toBe(1842);
});

it('reads product_id aliases and defaults quantity to 1', function (array $payload, array $expected) {
    expect(AddToCart::resolvePayload($payload))->toMatchArray($expected);
})->with([
    'product_id string' => [
        ['product_id' => '1842'],
        ['product_id' => 1842, 'quantity' => 1, 'variation_id' => 0, 'sku' => ''],
    ],
    'external_id' => [
        ['external_id' => '99', 'qty' => '3'],
        ['product_id' => 99, 'quantity' => 3, 'variation_id' => 0, 'sku' => ''],
    ],
    'id + sku + variation' => [
        ['id' => 12, 'sku' => 'MUG-BLU', 'variation_id' => 44, 'quantity' => 2],
        ['product_id' => 12, 'quantity' => 2, 'variation_id' => 44, 'sku' => 'MUG-BLU'],
    ],
    'choice is the variation id' => [
        ['product_id' => 1842, 'choice' => '99'],
        ['product_id' => 1842, 'quantity' => 1, 'variation_id' => 99, 'sku' => ''],
    ],
    'encoded attribute choice is not a variation id' => [
        ['product_id' => 1842, 'choice' => 'attribute_pa_color:blue'],
        ['product_id' => 1842, 'quantity' => 1, 'variation_id' => 0, 'sku' => ''],
    ],
    'choice path is not a variation id' => [
        ['product_id' => 1842, 'choice' => ['attribute_pa_color:blue', 'attribute_pa_size:small']],
        ['product_id' => 1842, 'quantity' => 1, 'variation_id' => 0, 'sku' => ''],
    ],
    'caps quantity' => [
        ['product_id' => 1, 'quantity' => 500],
        ['product_id' => 1, 'quantity' => 99],
    ],
    'ignores non-numeric id' => [
        ['id' => 'abc', 'sku' => 'SKU-1'],
        ['product_id' => 0, 'sku' => 'SKU-1'],
    ],
]);

it('collects variation attributes from the payload', function () {
    $resolved = AddToCart::resolvePayload([
        'product_id' => 10,
        'attribute_pa_color' => 'blue',
        'label' => 'ignored',
    ]);

    expect($resolved['variation'])->toBe(['attribute_pa_color' => 'blue']);
});

it('fails when no product id or sku can be resolved', function () {
    Functions\when('apply_filters')->returnArg(2);

    expect((new AddToCart())->addFromPayload(['label' => 'mug']))
        ->toMatchArray(['ok' => false, 'message' => 'No product was specified.']);
});

it('resolves the product from the page url when the payload has no id', function () {
    Functions\when('apply_filters')->returnArg(2);
    Functions\when('url_to_postid')->justReturn(1842);
    Functions\when('wc_get_product')->alias(fn ($id) => (int) $id === 1842 ? purchasableProduct() : null);
    stubCart(fn () => 'item-key');

    $result = (new AddToCart())->addFromPayload([
        'page_url' => 'https://store.test/product/v-neck-t-shirt',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toBe('Blue mug added to your cart.');
});

it('resolves a sku when product_id is missing', function () {
    Functions\when('apply_filters')->returnArg(2);
    Functions\when('wc_get_product_id_by_sku')->justReturn(77);
    Functions\when('wc_get_product')->alias(fn ($id) => (int) $id === 77 ? purchasableProduct() : null);
    stubCart(fn () => 'item-key');

    $result = (new AddToCart())->addFromPayload(['sku' => 'MUG-BLU']);

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toBe('Blue mug added to your cart.');
});

it('rejects a missing product', function () {
    Functions\when('wc_get_product')->justReturn(false);
    Functions\when('WC')->justReturn((object) ['cart' => (object) []]);

    expect((new AddToCart())->add(1842, 1))
        ->toMatchArray(['ok' => false, 'message' => 'That product could not be found.']);
});

it('rejects a variable product without a variation', function () {
    Functions\when('wc_get_product')->justReturn(purchasableProduct(variable: true));
    Functions\when('WC')->justReturn((object) ['cart' => (object) []]);

    expect((new AddToCart())->add(1842, 1))
        ->toMatchArray(['ok' => false, 'message' => 'This product needs a variation.']);
});

it('asks for colour then size then adds the matching variation', function () {
    $parent = purchasableProduct(
        variable: true,
        variations: [
            [
                'variation_id' => 11,
                'attributes' => ['attribute_pa_color' => 'blue', 'attribute_pa_size' => 'small'],
                'is_in_stock' => true,
                'is_purchasable' => true,
            ],
            [
                'variation_id' => 12,
                'attributes' => ['attribute_pa_color' => 'white', 'attribute_pa_size' => 'small'],
                'is_in_stock' => true,
                'is_purchasable' => true,
            ],
        ],
        permalink: 'https://store.test/vneck',
        attributeOptions: [
            'pa_color' => ['blue', 'white'],
            'pa_size' => ['small', 'large'],
        ],
    );

    Functions\when('apply_filters')->returnArg(2);
    Functions\when('wc_get_product')->alias(fn ($id) => (int) $id === 1842 ? $parent : purchasableProduct());
    Functions\when('WC')->justReturn((object) ['cart' => (object) []]);

    $first = (new AddToCart())->addFromPayload(['product_id' => 1842]);

    expect($first)->toMatchArray([
        'ok' => false,
        'status' => 'choices',
        'layout' => 'options',
        'message' => 'Which Color?',
        'url' => 'https://store.test/vneck',
        'choices' => [
            [
                'id' => 'attribute_pa_color:blue',
                'label' => 'Blue',
                'message' => 'Which Size?',
                'choices' => [
                    ['id' => 'attribute_pa_size:small', 'label' => 'Small'],
                ],
            ],
            [
                'id' => 'attribute_pa_color:white',
                'label' => 'White',
                'message' => 'Which Size?',
                'choices' => [
                    ['id' => 'attribute_pa_size:small', 'label' => 'Small'],
                ],
            ],
        ],
    ]);

    $second = (new AddToCart())->addFromPayload([
        'product_id' => 1842,
        'choice' => 'attribute_pa_color:blue',
    ]);

    expect($second)->toMatchArray([
        'ok' => false,
        'status' => 'choices',
        'message' => 'Which Size?',
        'choices' => [
            ['id' => 'attribute_pa_size:small', 'label' => 'Small'],
        ],
    ]);

    $seen = null;
    stubCart(function ($productId, $quantity, $variationId, $variation) use (&$seen) {
        $seen = [$productId, $quantity, $variationId, $variation];

        return 'item-key';
    });

    $added = (new AddToCart())->addFromPayload([
        'product_id' => 1842,
        'choice' => ['attribute_pa_color:blue', 'attribute_pa_size:small'],
    ]);

    expect($added['ok'])->toBeTrue()
        ->and($seen)->toBe([
            1842,
            1,
            11,
            [
                'attribute_pa_color' => 'blue',
                'attribute_pa_size' => 'small',
            ],
        ]);
});

it('turns a long variation list into a product link', function () {
    $rows = [];

    for ($i = 1; $i <= 9; $i++) {
        $rows[] = [
            'variation_id' => $i,
            'attributes' => ['attribute_pa_size' => 's'.$i],
            'is_in_stock' => true,
            'is_purchasable' => true,
        ];
    }

    Functions\when('wc_get_product')->justReturn(purchasableProduct(
        variable: true,
        variations: $rows,
        permalink: 'https://store.test/vneck',
    ));
    Functions\when('WC')->justReturn((object) ['cart' => (object) []]);

    expect((new AddToCart())->add(1842, 1))->toMatchArray([
        'ok' => false,
        'message' => 'This product has many options. Open the product to pick one.',
        'url' => 'https://store.test/vneck',
    ]);
});

it('rejects a product that is not purchasable', function () {
    Functions\when('wc_get_product')->justReturn(purchasableProduct(purchasable: false));
    Functions\when('WC')->justReturn((object) ['cart' => (object) []]);

    expect((new AddToCart())->add(1842, 1))
        ->toMatchArray(['ok' => false, 'message' => 'That product cannot be purchased.']);
});

it('fills variation attributes from the variation product', function () {
    Functions\when('wc_get_product')->alias(function ($id) {
        if ((int) $id === 99) {
            return new class
            {
                public function is_purchasable(): bool
                {
                    return true;
                }

                public function is_type(string $type): bool
                {
                    return $type === 'variation';
                }

                public function get_name(): string
                {
                    return 'V-Neck T-Shirt - Blue, Large';
                }

                public function get_max_purchase_quantity(): int
                {
                    return -1;
                }

                public function get_variation_attributes(): array
                {
                    return [
                        'attribute_pa_color' => 'blue',
                        'attribute_pa_size' => 'large',
                    ];
                }
            };
        }

        return purchasableProduct(variable: true);
    });

    $seen = null;
    stubCart(function ($productId, $quantity, $variationId, $variation) use (&$seen) {
        $seen = [$productId, $quantity, $variationId, $variation];

        return 'item-key';
    });
    Functions\when('wp_strip_all_tags')->returnArg(1);

    expect((new AddToCart())->add(1842, 1, 99)['ok'])->toBeTrue()
        ->and($seen)->toBe([
            1842,
            1,
            99,
            [
                'attribute_pa_color' => 'blue',
                'attribute_pa_size' => 'large',
            ],
        ]);
});

it('adds a simple product to the cart', function () {
    Functions\when('wc_get_product')->justReturn(purchasableProduct());
    stubCart(fn ($productId, $quantity, $variationId, $variation) => (
        $productId === 1842 && $quantity === 2 && $variationId === 0 && $variation === []
            ? 'item-key'
            : false
    ));

    $result = (new AddToCart())->add(1842, 2);

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toBe('Blue mug added to your cart.')
        ->and($result['cart_hash'])->toBe('cart-hash')
        ->and($result['fragments'])->toBe([]);
});

it('surfaces the WooCommerce notice when add_to_cart fails', function () {
    Functions\when('wc_get_product')->justReturn(purchasableProduct());
    stubCart(fn () => false);
    Functions\when('wc_get_notices')->justReturn([['notice' => 'Out of stock.']]);
    Functions\when('wc_clear_notices')->justReturn(true);

    expect((new AddToCart())->add(1842, 1))
        ->toMatchArray(['ok' => false, 'message' => 'Out of stock.']);
});

function purchasableProduct(
    bool $purchasable = true,
    bool $variable = false,
    array $variations = [],
    string $permalink = '',
    array $attributeOptions = [],
): object {
    return new class($purchasable, $variable, $variations, $permalink, $attributeOptions)
    {
        /**
         * @param  array<int, mixed>  $variations
         * @param  array<string, mixed>  $attributeOptions
         */
        public function __construct(
            private bool $purchasable,
            private bool $variable,
            private array $variations,
            private string $permalink,
            private array $attributeOptions,
        ) {}

        public function is_purchasable(): bool
        {
            return $this->purchasable;
        }

        public function is_type(string $type): bool
        {
            return $this->variable && $type === 'variable';
        }

        public function get_name(): string
        {
            return 'Blue mug';
        }

        public function get_max_purchase_quantity(): int
        {
            return -1;
        }

        /**
         * @return array<int, mixed>
         */
        public function get_available_variations(): array
        {
            return $this->variations;
        }

        public function get_permalink(): string
        {
            return $this->permalink;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_variation_attributes(): array
        {
            return $this->attributeOptions;
        }
    };
}

function stubCart(callable $addToCart): void
{
    $cart = new class($addToCart)
    {
        public function __construct(private $addToCart) {}

        public function add_to_cart(int $productId, int $quantity = 1, int $variationId = 0, array $variation = []): string|false
        {
            return ($this->addToCart)($productId, $quantity, $variationId, $variation);
        }

        public function get_cart_hash(): string
        {
            return 'cart-hash';
        }
    };

    Functions\when('WC')->justReturn((object) ['cart' => $cart]);
    Functions\when('wp_strip_all_tags')->returnArg(1);
}
