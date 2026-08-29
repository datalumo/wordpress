<?php

/**
 * Dummy content for bin/fresh-site. Run with: wp eval-file bin/fresh-site-seed.php
 */

if (! defined('ABSPATH')) {
    fwrite(STDERR, "Run this with WP-CLI: wp eval-file bin/fresh-site-seed.php\n");
    exit(1);
}

wp_delete_post(1, true);
wp_delete_post((int) get_option('page_on_front'), true);

$guide = wp_insert_post([
    'post_title' => 'Visitor guide',
    'post_name' => 'visitor-guide',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => <<<'HTML'
<p>This is a clean demo shop for trying the Datalumo WordPress plugin.</p>
<p>Sign in at <code>/wp-admin/</code> with <strong>admin</strong> / <strong>password</strong>, then open Settings → Datalumo and press Connect with Datalumo.</p>
HTML,
], true);

wp_insert_post([
    'post_title' => 'About',
    'post_name' => 'about',
    'post_status' => 'publish',
    'post_type' => 'page',
    'post_content' => '<p>Datalumo Fresh is a disposable WordPress site. Wipe it with <code>bin/fresh-site --force</code>.</p>',
], true);

$posts = [
    [
        'Opening hours and pickup',
        'We are open Tuesday to Saturday, 10:00 to 18:00. Weekend pickup is at the side door on Kerkstraat. Closed on Mondays.',
    ],
    [
        'Shipping and returns',
        'Orders placed before 15:00 ship the same day inside the Netherlands. You have 30 days to return unused items. Start a return from your account or reply to the order email.',
    ],
    [
        'Care for wool',
        'Hand wash wool in cold water and lay it flat to dry. A wool beanie can go in a mesh bag on a gentle cycle if you skip the dryer.',
    ],
    [
        'How we roast coffee',
        'Beans are roasted in small batches every Thursday. Light roasts land on Friday. Dark roasts are bagged the same afternoon.',
    ],
    [
        'Gift wrapping',
        'Add a note at checkout and we wrap the order in recycled paper. Gift receipts hide prices. We do not print prices on the packing slip when you ask.',
    ],
];

foreach ($posts as [$title, $content]) {
    wp_insert_post([
        'post_title' => $title,
        'post_status' => 'publish',
        'post_type' => 'post',
        'post_content' => '<p>'.$content.'</p>',
    ], true);
}

if ($guide && ! is_wp_error($guide)) {
    update_option('show_on_front', 'page');
    update_option('page_on_front', $guide);
}

if (! class_exists('WooCommerce')) {
    WP_CLI::warning('WooCommerce is not active; skipped products.');

    return;
}

$simple = [
    ['Canvas Tote', '29.00', 'A heavy canvas tote that stands on its own. Natural colour, one size.', 'tote'],
    ['Ceramic Mug', '14.00', 'A 300ml stoneware mug. Dishwasher safe. Speckled cream glaze.', 'mug'],
    ['Wool Beanie', '22.00', 'Merino beanie, unisex. Hand wash or a gentle cycle in a mesh bag.', 'beanie'],
    ['Drip Coffee 250g', '11.50', 'Thursday roast. Light and chocolatey. Ground for filter on request.', 'coffee'],
];

foreach ($simple as [$name, $price, $description, $sku]) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price($price);
    $product->set_short_description($description);
    $product->set_description($description);
    $product->set_sku($sku);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(25);
    $product->set_catalog_visibility('visible');
    $product->set_status('publish');
    $product->save();
}

foreach (['color' => 'Color', 'size' => 'Size'] as $slug => $label) {
    if (wc_attribute_taxonomy_id_by_name($slug)) {
        continue;
    }

    wc_create_attribute([
        'name' => $label,
        'slug' => $slug,
        'type' => 'select',
        'order_by' => 'menu_order',
        'has_archives' => false,
    ]);
}

delete_transient('wc_attribute_taxonomies');
WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
WC()->attributes = null;

if (! taxonomy_exists('pa_color') || ! taxonomy_exists('pa_size')) {
    foreach (wc_get_attribute_taxonomies() as $taxonomy) {
        $name = wc_attribute_taxonomy_name($taxonomy->attribute_name);
        register_taxonomy($name, ['product'], []);
    }
}

foreach (['Blue', 'Red'] as $color) {
    wp_insert_term($color, 'pa_color');
}

foreach (['Small', 'Large'] as $size) {
    wp_insert_term($size, 'pa_size');
}

$colorAttribute = new WC_Product_Attribute();
$colorAttribute->set_id(wc_attribute_taxonomy_id_by_name('color'));
$colorAttribute->set_name('pa_color');
$colorAttribute->set_options(array_values(array_filter([
    get_term_by('name', 'Blue', 'pa_color')->term_id ?? null,
    get_term_by('name', 'Red', 'pa_color')->term_id ?? null,
])));
$colorAttribute->set_visible(true);
$colorAttribute->set_variation(true);

$sizeAttribute = new WC_Product_Attribute();
$sizeAttribute->set_id(wc_attribute_taxonomy_id_by_name('size'));
$sizeAttribute->set_name('pa_size');
$sizeAttribute->set_options(array_values(array_filter([
    get_term_by('name', 'Small', 'pa_size')->term_id ?? null,
    get_term_by('name', 'Large', 'pa_size')->term_id ?? null,
])));
$sizeAttribute->set_visible(true);
$sizeAttribute->set_variation(true);

$jacket = new WC_Product_Variable();
$jacket->set_name('Trail Jacket');
$jacket->set_sku('jacket');
$jacket->set_short_description('A packable shell. Pick a colour, then a size.');
$jacket->set_description('Two-way zip, stuffs into its own pocket. Colour and size are variations so chat add-to-cart can walk those steps.');
$jacket->set_attributes([$colorAttribute, $sizeAttribute]);
$jacket->set_catalog_visibility('visible');
$jacket->set_status('publish');
$jacketId = $jacket->save();

$prices = [
    'Blue' => ['Small' => '89.00', 'Large' => '89.00'],
    'Red' => ['Small' => '92.00', 'Large' => '92.00'],
];

foreach ($prices as $color => $sizes) {
    foreach ($sizes as $size => $price) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($jacketId);
        $variation->set_attributes([
            'pa_color' => sanitize_title($color),
            'pa_size' => sanitize_title($size),
        ]);
        $variation->set_regular_price($price);
        $variation->set_sku('jacket-'.sanitize_title($color).'-'.sanitize_title($size));
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity(8);
        $variation->set_status('publish');
        $variation->save();
    }
}

WC_Product_Variable::sync($jacketId);

WP_CLI::success('Seeded pages, posts, simple products, and a variable Trail Jacket.');
