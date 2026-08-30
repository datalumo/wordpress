<?php

namespace Datalumo\Wp\Integration;

use Datalumo\Wp\Support\Assets;

/**
 * Listens for a Datalumo host action named add_to_cart and adds the
 * product to the visitor's WooCommerce cart (their browser session).
 */
class AddToCart
{
    public const AJAX_ACTION = 'datalumo_add_to_cart';

    public const NONCE = 'datalumo_add_to_cart';

    public const DEFAULT_EVENT = 'add_to_cart';

    public const MAX_CHOICES = 8;

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handle']);
    }

    public function enqueue(): void
    {
        wp_enqueue_script(
            'datalumo-add-to-cart',
            DATALUMO_URL . 'resources/js/add-to-cart.js',
            [],
            Assets::version(),
            ['in_footer' => true],
        );

        wp_localize_script('datalumo-add-to-cart', 'datalumoAddToCart', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'event' => $this->eventName(),
            'productId' => self::currentProductId(),
            'i18n' => [
                'missingProduct' => __('No product was specified.', 'datalumo'),
                'failed' => __('Could not add that to the cart.', 'datalumo'),
            ],
        ]);
    }

    public function eventName(): string
    {
        $event = apply_filters('datalumo_add_to_cart_event', self::DEFAULT_EVENT);

        return is_string($event) && $event !== '' ? $event : self::DEFAULT_EVENT;
    }

    public function handle(): void
    {
        check_ajax_referer(self::NONCE);

        $result = $this->addFromPayload($this->requestPayload());

        if ($result['ok']) {
            wp_send_json_success($result);
        }

        wp_send_json_error(array_filter([
            'message' => $result['message'] ?? '',
            'status' => $result['status'] ?? null,
            'layout' => $result['layout'] ?? null,
            'choices' => $result['choices'] ?? null,
            'url' => $result['url'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, message: string, fragments?: array<string, string>, cart_hash?: string}
     */
    public function addFromPayload(array $payload): array
    {
        $payload = apply_filters('datalumo_add_to_cart_payload', $payload);
        $payload = is_array($payload) ? $payload : [];

        $resolved = self::resolvePayload($payload);
        $productId = $resolved['product_id'];

        if ($productId <= 0 && $resolved['sku'] !== '' && function_exists('wc_get_product_id_by_sku')) {
            $productId = (int) wc_get_product_id_by_sku($resolved['sku']);
        }

        if ($productId <= 0) {
            $productId = self::productIdFromPageUrl($payload);
        }

        if ($productId <= 0) {
            return [
                'ok' => false,
                'message' => __('No product was specified.', 'datalumo'),
            ];
        }

        $variation = self::applyChoices($resolved['variation'], $payload);

        return $this->add($productId, $resolved['quantity'], $resolved['variation_id'], $variation);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{product_id: int, quantity: int, variation_id: int, sku: string, variation: array<string, string>}
     */
    public static function resolvePayload(array $payload): array
    {
        $quantity = self::firstPositiveInt($payload, ['quantity', 'qty']);

        return [
            'product_id' => self::firstPositiveInt($payload, ['product_id', 'product', 'id', 'external_id']),
            'quantity' => $quantity > 0 ? min($quantity, 99) : 1,
            'variation_id' => self::firstPositiveInt($payload, ['choice', 'variation_id', 'variation']),
            'sku' => self::firstString($payload, ['sku']),
            'variation' => self::variationAttributes($payload),
        ];
    }

    public static function currentProductId(): int
    {
        if (! function_exists('is_product') || ! is_product()) {
            return 0;
        }

        return (int) get_the_ID();
    }

    /**
     * Merge attributes stored on a variation product into the payload map.
     *
     * @param  array<string, string>  $variation
     * @return array<string, string>
     */
    public static function attributesForVariation(int $variationId, array $variation): array
    {
        if ($variationId <= 0 || ! function_exists('wc_get_product')) {
            return $variation;
        }

        $product = wc_get_product($variationId);

        if (! $product || ! method_exists($product, 'get_variation_attributes')) {
            return $variation;
        }

        $fromProduct = $product->get_variation_attributes();
        $merged = [];

        foreach (is_array($fromProduct) ? $fromProduct : [] as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        foreach ($variation as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function productIdFromPageUrl(array $payload): int
    {
        $url = self::firstString($payload, ['page_url', 'url']);

        if ($url === '' || ! function_exists('url_to_postid')) {
            return 0;
        }

        $id = (int) url_to_postid($url);

        if ($id <= 0 || ! function_exists('wc_get_product')) {
            return 0;
        }

        return wc_get_product($id) ? $id : 0;
    }

    /**
     * @param  array<string, string>  $variation
     * @return array{ok: bool, message: string, fragments?: array<string, string>, cart_hash?: string}
     */
    public function add(int $productId, int $quantity, int $variationId = 0, array $variation = []): array
    {
        if (! function_exists('wc_get_product') || ! function_exists('WC')) {
            return [
                'ok' => false,
                'message' => __('WooCommerce is not available.', 'datalumo'),
            ];
        }

        $parent = wc_get_product($productId);

        if (! $parent) {
            return [
                'ok' => false,
                'message' => __('That product could not be found.', 'datalumo'),
            ];
        }

        if (method_exists($parent, 'is_type') && $parent->is_type('variable')) {
            $resolved = $this->resolveVariableSelection($parent, $variationId, $variation);

            if (array_key_exists('ok', $resolved)) {
                return $resolved;
            }

            $variationId = $resolved['variation_id'];
            $variation = $resolved['variation'];
        }

        $product = wc_get_product($variationId > 0 ? $variationId : $productId);

        if (! $product) {
            return [
                'ok' => false,
                'message' => __('That product could not be found.', 'datalumo'),
            ];
        }

        if (method_exists($product, 'is_purchasable') && ! $product->is_purchasable()) {
            return [
                'ok' => false,
                'message' => __('That product cannot be purchased.', 'datalumo'),
            ];
        }

        $max = method_exists($product, 'get_max_purchase_quantity')
            ? (int) $product->get_max_purchase_quantity()
            : -1;

        if ($max > 0) {
            $quantity = min($quantity, $max);
        }

        if (function_exists('wc_load_cart') && WC()->cart === null) {
            wc_load_cart();
        }

        if (! WC()->cart) {
            return [
                'ok' => false,
                'message' => __('Could not add that to the cart.', 'datalumo'),
            ];
        }

        $variation = self::attributesForVariation($variationId, $variation);

        $added = WC()->cart->add_to_cart($productId, $quantity, $variationId, $variation);

        if (! $added) {
            return [
                'ok' => false,
                'message' => $this->cartErrorMessage(),
            ];
        }

        $name = method_exists($product, 'get_name')
            ? wp_strip_all_tags((string) $product->get_name())
            : '';

        return [
            'ok' => true,
            'message' => $name !== ''
                /* translators: %s: product name */
                ? sprintf(__('%s added to your cart.', 'datalumo'), $name)
                : __('Added to your cart.', 'datalumo'),
            'fragments' => $this->cartFragments(),
            'cart_hash' => method_exists(WC()->cart, 'get_cart_hash')
                ? (string) WC()->cart->get_cart_hash()
                : '',
        ];
    }

    /**
     * Ask for one missing attribute at a time (colour, then size), then
     * match the variation that has every picked value.
     *
     * @param  array<string, string>  $variation
     * @return array{variation_id: int, variation: array<string, string>}|array{ok: false, message: string, status?: string, layout?: string, choices?: array<int, array<string, string>>, url?: string}
     */
    private function resolveVariableSelection(object $parent, int $variationId, array $variation): array
    {
        $rows = method_exists($parent, 'get_available_variations')
            ? $parent->get_available_variations()
            : [];
        $rows = is_array($rows) ? $rows : [];
        $variation = self::attributesForVariation($variationId, $variation);
        $missing = self::missingAttributeKeys(
            self::variationAttributeKeys($parent, $rows),
            $variation,
        );

        if ($missing !== []) {
            return $this->elicitAttributes($parent, $rows, $missing, $variation);
        }

        if ($variationId <= 0) {
            $variationId = self::matchVariationId($rows, $variation);
        }

        if ($variationId <= 0) {
            $url = method_exists($parent, 'get_permalink')
                ? (string) $parent->get_permalink()
                : '';

            return [
                'ok' => false,
                'message' => __('This product needs a variation.', 'datalumo'),
                'url' => $url,
            ];
        }

        return [
            'variation_id' => $variationId,
            'variation' => $variation,
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $selected
     * @return array{ok: false, message: string, status?: string, layout?: string, choices?: array<int, array<string, mixed>>, url?: string}
     */
    private function elicitAttributes(object $product, array $rows, array $keys, array $selected): array
    {
        $choices = self::choicesTree($rows, $keys, $selected, $product);
        $url = method_exists($product, 'get_permalink')
            ? (string) $product->get_permalink()
            : '';

        if ($choices === []) {
            return [
                'ok' => false,
                'message' => __('This product needs a variation.', 'datalumo'),
                'url' => $url,
            ];
        }

        if (self::choicesExceedMax($choices)) {
            return [
                'ok' => false,
                'message' => __('This product has many options. Open the product to pick one.', 'datalumo'),
                'url' => $url,
            ];
        }

        return [
            'ok' => false,
            'status' => 'choices',
            /* translators: %s: product attribute name, such as colour or size */
            'message' => sprintf(__('Which %s?', 'datalumo'), self::attributeLabel($keys[0])),
            'layout' => 'options',
            'choices' => $choices,
            'url' => $url,
        ];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return array<int, string>
     */
    public static function variationAttributeKeys(object $product, array $rows): array
    {
        if (method_exists($product, 'get_variation_attributes')) {
            $attrs = $product->get_variation_attributes();

            if (is_array($attrs) && $attrs !== []) {
                $keys = [];

                foreach (array_keys($attrs) as $key) {
                    if (is_string($key) && $key !== '') {
                        $keys[] = self::attributeKey($key);
                    }
                }

                if ($keys !== []) {
                    return $keys;
                }
            }
        }

        $keys = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_array($row['attributes'] ?? null)) {
                continue;
            }

            foreach (array_keys($row['attributes']) as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @param  array<int, string>  $needed
     * @param  array<string, string>  $selected
     * @return array<int, string>
     */
    public static function missingAttributeKeys(array $needed, array $selected): array
    {
        $missing = [];

        foreach ($needed as $key) {
            if (trim((string) ($selected[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, string>  $selected
     * @return array<string, string>
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public static function choicePath(array $payload): array
    {
        $choice = $payload['choice'] ?? null;

        if (is_array($choice)) {
            $path = [];

            foreach ($choice as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $path[] = trim($item);
                }
            }

            return $path;
        }

        if (is_string($choice) && trim($choice) !== '') {
            return [trim($choice)];
        }

        return [];
    }

    /**
     * @param  array<string, string>  $selected
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public static function applyChoices(array $selected, array $payload): array
    {
        foreach (self::choicePath($payload) as $choice) {
            $selected = self::applyChoice($selected, $choice);
        }

        return $selected;
    }

    public static function applyChoice(array $selected, string $choice): array
    {
        if ($choice === '' || ! str_contains($choice, ':')) {
            return $selected;
        }

        [$key, $value] = explode(':', $choice, 2);
        $key = trim($key);
        $value = trim($value);

        if (str_starts_with($key, 'attribute_') && $value !== '') {
            $selected[$key] = $value;
        }

        return $selected;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $keys
     * @param  array<string, string>  $selected
     * @return array<int, array<string, mixed>>
     */
    public static function choicesTree(array $rows, array $keys, array $selected, object $product): array
    {
        if ($keys === []) {
            return [];
        }

        $key = $keys[0];
        $rest = array_slice($keys, 1);
        $options = self::optionsForAttribute($rows, $key, $selected, $product);

        if ($rest === []) {
            return $options;
        }

        /* translators: %s: product attribute name, such as colour or size */
        $nextMessage = sprintf(__('Which %s?', 'datalumo'), self::attributeLabel($rest[0]));
        $tree = [];

        foreach ($options as $option) {
            $id = (string) ($option['id'] ?? '');
            $value = str_contains($id, ':') ? trim(explode(':', $id, 2)[1]) : '';

            if ($value === '') {
                continue;
            }

            $nested = self::choicesTree($rows, $rest, array_merge($selected, [$key => $value]), $product);

            if ($nested === []) {
                continue;
            }

            $option['message'] = $nextMessage;
            $option['choices'] = $nested;
            $tree[] = $option;
        }

        return $tree;
    }

    /**
     * @param  array<int, array<string, mixed>>  $choices
     */
    public static function choicesExceedMax(array $choices): bool
    {
        if (count($choices) > self::MAX_CHOICES) {
            return true;
        }

        foreach ($choices as $choice) {
            $nested = $choice['choices'] ?? null;

            if (is_array($nested) && $nested !== [] && self::choicesExceedMax($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<string, string>  $selected
     * @return array<int, array{id: string, label: string}>
     */
    public static function optionsForAttribute(array $rows, string $key, array $selected, object $product): array
    {
        $fromRows = [];
        $any = false;

        foreach ($rows as $row) {
            if (! is_array($row) || ! self::isAvailableVariation($row)) {
                continue;
            }

            if (! self::variationMatches($row, $selected)) {
                continue;
            }

            $value = trim((string) ($row['attributes'][$key] ?? ''));

            if ($value === '') {
                $any = true;

                continue;
            }

            $fromRows[$value] = self::optionLabel($value);
        }

        if ($any || $fromRows === []) {
            foreach (self::parentAttributeOptions($product, $key) as $slug => $label) {
                $fromRows[$slug] = $label;
            }
        }

        $choices = [];

        foreach ($fromRows as $slug => $label) {
            $choices[] = [
                'id' => $key.':'.$slug,
                'label' => $label,
            ];
        }

        return $choices;
    }

    /**
     * @param  array<int, mixed>  $rows
     * @param  array<string, string>  $selected
     */
    public static function matchVariationId(array $rows, array $selected): int
    {
        foreach ($rows as $row) {
            if (! is_array($row) || ! self::isAvailableVariation($row)) {
                continue;
            }

            if (! self::variationMatches($row, $selected)) {
                continue;
            }

            return (int) $row['variation_id'];
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $selected
     */
    public static function variationMatches(array $row, array $selected): bool
    {
        $attrs = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];

        foreach ($selected as $key => $value) {
            if (! is_string($key) || trim((string) $value) === '') {
                continue;
            }

            $rowValue = trim((string) ($attrs[$key] ?? ''));

            if ($rowValue !== '' && $rowValue !== trim((string) $value)) {
                return false;
            }
        }

        return true;
    }

    public static function attributeKey(string $name): string
    {
        return str_starts_with($name, 'attribute_') ? $name : 'attribute_'.$name;
    }

    public static function attributeLabel(string $key): string
    {
        $name = str_starts_with($key, 'attribute_') ? substr($key, 10) : $key;

        if (function_exists('wc_attribute_label')) {
            $label = wc_attribute_label($name);

            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        if (str_starts_with($name, 'pa_')) {
            $name = substr($name, 3);
        }

        return self::optionLabel($name);
    }

    public static function optionLabel(string $value): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $value));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function isAvailableVariation(array $row): bool
    {
        if ((int) ($row['variation_id'] ?? 0) <= 0) {
            return false;
        }

        if (array_key_exists('is_in_stock', $row) && ! $row['is_in_stock']) {
            return false;
        }

        if (array_key_exists('is_purchasable', $row) && ! $row['is_purchasable']) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private static function parentAttributeOptions(object $product, string $key): array
    {
        if (! method_exists($product, 'get_variation_attributes')) {
            return [];
        }

        $attrs = $product->get_variation_attributes();

        if (! is_array($attrs)) {
            return [];
        }

        $raw = $attrs[$key] ?? $attrs[self::unprefixedAttributeKey($key)] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $options = [];

        foreach ($raw as $index => $value) {
            if (is_int($index) || (is_string($index) && ctype_digit((string) $index))) {
                $slug = trim((string) $value);

                if ($slug !== '') {
                    $options[$slug] = self::optionLabel($slug);
                }

                continue;
            }

            $slug = trim((string) $index);
            $label = is_scalar($value) ? trim((string) $value) : '';

            if ($slug !== '') {
                $options[$slug] = $label !== '' ? $label : self::optionLabel($slug);
            }
        }

        return $options;
    }

    private static function unprefixedAttributeKey(string $key): string
    {
        return str_starts_with($key, 'attribute_') ? substr($key, 10) : $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded below.
        $raw = wp_unslash($_POST['payload'] ?? '');
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function cartFragments(): array
    {
        if (! function_exists('woocommerce_mini_cart')) {
            return [];
        }

        ob_start();
        woocommerce_mini_cart();
        $miniCart = (string) ob_get_clean();

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce cart fragment API.
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', [
            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $miniCart . '</div>',
        ]);

        return is_array($fragments) ? $fragments : [];
    }

    private function cartErrorMessage(): string
    {
        if (function_exists('wc_get_notices')) {
            $notices = wc_get_notices('error');

            if (function_exists('wc_clear_notices')) {
                wc_clear_notices();
            }

            $first = is_array($notices) ? ($notices[0]['notice'] ?? '') : '';

            if (is_string($first) && $first !== '') {
                return wp_strip_all_tags($first);
            }
        }

        return __('Could not add that to the cart.', 'datalumo');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private static function firstPositiveInt(array $payload, array $keys): int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_float($value) && $value > 0 && $value === floor($value)) {
                return (int) $value;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value !== '' && ctype_digit($value)) {
                    return (int) $value;
                }
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private static function firstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private static function variationAttributes(array $payload): array
    {
        $attributes = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'attribute_')) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $attributes[$key] = (string) $value;
        }

        return $attributes;
    }
}
