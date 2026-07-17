<?php

namespace Datalumo\Wp\Embed;

use Datalumo\Wp\Support\Options;

/**
 * Front-end embeds: the floating chat widget, the [datalumo_search] and
 * [datalumo_chat] shortcodes, and signed visitor identity for logged-in
 * users — so their conversations can be continued across visits.
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
            null,
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
            // The site-wide mount is always the floating bubble, whatever
            // variant the widget's dashboard settings default to.
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
     * when identity is configured. The hash is computed server-side with the
     * widget's signing secret, so it can't be forged in the browser.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $user = $this->identity();

        return $user !== null ? ['user' => $user] : [];
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
