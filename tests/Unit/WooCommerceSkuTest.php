<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Integration\WooCommerce;

function wcProduct(
    string $sku = '',
    string $type = 'simple',
    array $children = [],
    string $shortDescription = '',
    array $attributes = [],
    int $id = 1,
    int $imageId = 0,
): object {
    return new class($sku, $type, $children, $shortDescription, $attributes, $id, $imageId)
    {
        public function __construct(
            private string $sku,
            private string $type,
            private array $children,
            private string $shortDescription,
            private array $attributes,
            private int $id,
            private int $imageId,
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_sku(): string
        {
            return $this->sku;
        }

        public function get_short_description(): string
        {
            return $this->shortDescription;
        }

        public function get_attributes(): array
        {
            return $this->attributes;
        }

        public function is_type(string $type): bool
        {
            return $this->type === $type;
        }

        public function get_children(): array
        {
            return $this->children;
        }

        public function get_image_id(): int
        {
            return $this->imageId;
        }
    };
}

function wcAttribute(string $name, array $options, bool $visible = true, bool $taxonomy = false): object
{
    return new class($name, $options, $visible, $taxonomy)
    {
        public function __construct(
            private string $name,
            private array $options,
            private bool $visible,
            private bool $taxonomy,
        ) {}

        public function get_name(): string
        {
            return $this->name;
        }

        public function get_visible(): bool
        {
            return $this->visible;
        }

        public function is_taxonomy(): bool
        {
            return $this->taxonomy;
        }

        public function get_options(): array
        {
            return $this->options;
        }
    };
}

beforeEach(function () {
    Functions\when('wp_strip_all_tags')->returnArg(1);
    Functions\when('wc_attribute_label')->returnArg(1);
    Functions\when('get_the_terms')->justReturn([]);
});

it('leaves non-product payloads alone', function () {
    $post = new WP_Post();
    $post->post_type = 'page';

    $payload = ['content' => '<p>About</p>', 'meta' => ['post_type' => 'page']];

    expect((new WooCommerce())->enrichProduct($payload, $post))->toBe($payload);
});

it('adds a simple product sku to meta and searchable content', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'VNECK-BLU'));

    $post = new WP_Post();
    $post->ID = 12;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A blue v-neck.</p>',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['meta']['sku'])->toBe('VNECK-BLU')
        ->and($payload['content'])->toContain('sku: VNECK-BLU');
});

it('includes variation skus on a variable product', function () {
    Functions\when('wc_get_product')->alias(function (int $id) {
        return match ($id) {
            10 => wcProduct(sku: 'jacket', type: 'variable', children: [11, 12], id: 10),
            11 => wcProduct(sku: 'jacket-blue-m', id: 11),
            12 => wcProduct(sku: 'jacket-red-l', id: 12),
            default => null,
        };
    });

    $post = new WP_Post();
    $post->ID = 10;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A jacket.</p>',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['meta']['sku'])->toBe(['jacket', 'jacket-blue-m', 'jacket-red-l'])
        ->and($payload['content'])->toContain('sku: jacket, jacket-blue-m, jacket-red-l');
});

it('does not overwrite an existing sku mapping', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'FROM-WC'));

    $post = new WP_Post();
    $post->ID = 3;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>Mapped.</p>',
        'meta' => ['sku' => 'FROM-MAP'],
    ], $post);

    expect($payload['meta']['sku'])->toBe('FROM-MAP')
        ->and($payload['content'])->toBe('<p>Mapped.</p>');
});

it('prepends the short description when the body is empty', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(
        sku: 'MUG-1',
        shortDescription: 'A ceramic mug.',
    ));

    $post = new WP_Post();
    $post->ID = 4;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['content'])->toStartWith('<p>A ceramic mug.</p>')
        ->and($payload['content'])->toContain('sku: MUG-1');
});

it('adds product categories and tags as searchable meta', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'SHOE-1'));
    Functions\when('get_the_terms')->alias(function (int $id, string $taxonomy) {
        return match ($taxonomy) {
            'product_cat' => [
                (object) ['name' => 'Running', 'slug' => 'running'],
                (object) ['name' => 'Mens', 'slug' => 'mens'],
            ],
            'product_tag' => [
                (object) ['name' => 'Sale', 'slug' => 'sale'],
            ],
            default => [],
        };
    });

    $post = new WP_Post();
    $post->ID = 8;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>Trail shoe.</p>',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['meta']['categories'])->toBe(['running', 'mens'])
        ->and($payload['meta']['tags'])->toBe(['sale'])
        ->and($payload['content'])->toContain('category: Running, Mens')
        ->and($payload['content'])->toContain('tag: Sale');
});

it('adds visible attributes and skips hidden ones', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(
        sku: 'JKT-1',
        attributes: [
            wcAttribute('Color', ['Blue', 'Red']),
            wcAttribute('Internal', ['secret'], visible: false),
        ],
    ));

    $post = new WP_Post();
    $post->ID = 9;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A jacket.</p>',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['meta']['attributes'])->toBe(['Color' => ['Blue', 'Red']])
        ->and($payload['content'])->toContain('Color: Blue, Red')
        ->and($payload['content'])->not->toContain('Internal');
});

it('adds a product image when the thumbnail is null', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'MUG-1', imageId: 44));
    Functions\when('wp_get_attachment_image_url')->justReturn('http://shop.example/mug-large.jpg');

    $post = new WP_Post();
    $post->ID = 4;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A mug.</p>',
        'meta' => ['post_type' => 'product'],
        'thumbnail' => null,
    ], $post);

    expect($payload['thumbnail'])->toBe('https://shop.example/mug-large.jpg');
});

it('adds a product image when the payload has no thumbnail', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'MUG-1', imageId: 44));
    Functions\when('wp_get_attachment_image_url')->justReturn('http://shop.example/mug-large.jpg');

    $post = new WP_Post();
    $post->ID = 4;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A mug.</p>',
        'meta' => ['post_type' => 'product'],
    ], $post);

    expect($payload['thumbnail'])->toBe('https://shop.example/mug-large.jpg');
});

it('does not overwrite an existing thumbnail', function () {
    Functions\when('wc_get_product')->justReturn(wcProduct(sku: 'MUG-1', imageId: 44));
    Functions\when('wp_get_attachment_image_url')->justReturn('https://shop.example/mug-large.jpg');

    $post = new WP_Post();
    $post->ID = 4;
    $post->post_type = 'product';

    $payload = (new WooCommerce())->enrichProduct([
        'content' => '<p>A mug.</p>',
        'meta' => ['post_type' => 'product'],
        'thumbnail' => 'https://example.com/already.jpg',
    ], $post);

    expect($payload['thumbnail'])->toBe('https://example.com/already.jpg');
});
