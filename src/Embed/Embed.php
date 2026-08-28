<?php

namespace Datalumo\Wp\Embed;

use Datalumo\Wp\Support\Options;

/**
 * Front-end embeds: the floating chat widget, the [datalumo_search] and
 * [datalumo_chat] shortcodes, and signed visitor identity for logged-in
 * users so conversations continue across visits.
 */
class Embed
{
    public const SCRIPT_HANDLE = 'datalumo-sdk';

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'registerScript']);
        add_action('wp_footer', [$this, 'renderChatbot']);

        if (! shortcode_exists('datalumo_search')) {
            add_shortcode('datalumo_search', [$this, 'searchShortcode']);
        }

        if (! shortcode_exists('datalumo_chat')) {
            add_shortcode('datalumo_chat', [$this, 'chatShortcode']);
        }
    }

    public function registerScript(): void
    {
        wp_register_script(
            self::SCRIPT_HANDLE,
            Options::baseUrl() . '/widget/v1/datalumo.js',
            [],
            DATALUMO_VERSION,
            ['in_footer' => true],
        );
    }

    public function renderChatbot(): void
    {
        $widgetKey = (string) Options::get('chatbot.widget_key');

        if (! Options::get('chatbot.enabled') || $widgetKey === '') {
            return;
        }

        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, sprintf(
            'Datalumo.chat(%s, %s).mount();',
            wp_json_encode($widgetKey),
            // Site-wide mount is always the floating bubble, whatever the
            // widget's dashboard default variant is.
            wp_json_encode(['variant' => 'bubble'] + $this->overrides()),
        ));
    }

    public function searchShortcode(array|string $attributes = []): string
    {
        $widgetKey = (string) (((array) $attributes)['widget'] ?? Options::get('search_box.widget_key'));

        if ($widgetKey === '') {
            return '';
        }

        $id = 'datalumo-search-' . wp_unique_id();

        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, sprintf(
            'Datalumo.search(%s, %s).mount();',
            wp_json_encode($widgetKey),
            wp_json_encode(['target' => '#' . $id] + $this->overrides()),
        ));

        return sprintf('<div id="%s" class="datalumo-search"></div>', esc_attr($id));
    }

    public function chatShortcode(array|string $attributes = []): string
    {
        $widgetKey = (string) (((array) $attributes)['widget'] ?? Options::get('chatbot.widget_key'));

        if ($widgetKey === '') {
            return '';
        }

        $id = 'datalumo-chat-' . wp_unique_id();

        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_add_inline_script(self::SCRIPT_HANDLE, sprintf(
            'Datalumo.chat(%s, %s).mount();',
            wp_json_encode($widgetKey),
            wp_json_encode(['variant' => 'inline', 'target' => '#' . $id] + $this->overrides()),
        ));

        return sprintf('<div id="%s" class="datalumo-chat"></div>', esc_attr($id));
    }

    /**
     * Shared widget overrides: the signed identity of the logged-in visitor,
     * when configured. The hash is HMAC'd server-side with the widget's signing
     * secret, so it can't be forged in the browser.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $overrides = [];
        $user = $this->identity();
        $context = $this->pageContext();

        if ($user !== null) {
            $overrides['user'] = $user;
        }

        if ($context !== []) {
            $overrides['context'] = $context;
        }

        return $overrides;
    }

    /**
     * Product page hints for chat. Merged with the widget's automatic
     * page_url / page_title so "this product" has a real id to copy.
     *
     * @return array<string, string>
     */
    public function pageContext(): array
    {
        $context = [];

        if (function_exists('is_product') && is_product()) {
            $id = (int) get_the_ID();

            if ($id > 0) {
                $context['product_id'] = (string) $id;
            }

            if ($id > 0 && function_exists('wc_get_product')) {
                $product = wc_get_product($id);

                if ($product && method_exists($product, 'get_sku')) {
                    $sku = trim((string) $product->get_sku());

                    if ($sku !== '') {
                        $context['sku'] = $sku;
                    }
                }
            }
        }

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('datalumo_chat_context', $context);

            return is_array($filtered) ? $filtered : $context;
        }

        return $context;
    }

    /**
     * @return array{id: string, hash: string}|null
     */
    public function identity(): ?array
    {
        $secret = (string) Options::get('chatbot.signing_secret');

        if (! Options::get('chatbot.identity_enabled') || $secret === '' || ! is_user_logged_in()) {
            return null;
        }

        $id = 'wp-user-' . get_current_user_id();

        return [
            'id' => $id,
            'hash' => hash_hmac('sha256', $id, $secret),
        ];
    }
}
