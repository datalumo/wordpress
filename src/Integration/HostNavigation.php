<?php

namespace Datalumo\Wp\Integration;

use Datalumo\Wp\Support\Assets;

/**
 * Listens for Datalumo host actions that open a page: the cart, checkout,
 * or a WordPress post.
 */
class HostNavigation
{
    public const AJAX_ACTION = 'datalumo_host_navigation';

    public const NONCE = 'datalumo_host_navigation';

    public const EVENTS = ['view_cart', 'open_checkout', 'open_page'];

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handle']);
    }

    public function enqueue(): void
    {
        wp_enqueue_script(
            'datalumo-host-navigation',
            DATALUMO_URL . 'resources/js/host-navigation.js',
            [],
            Assets::version(),
            ['in_footer' => true],
        );

        wp_localize_script('datalumo-host-navigation', 'datalumoHostNavigation', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'events' => self::EVENTS,
            'cartUrl' => $this->cartUrl(),
            'checkoutUrl' => $this->checkoutUrl(),
            'homeHost' => $this->homeHost(),
            'i18n' => [
                'failed' => __('Could not open that page.', 'datalumo'),
                'no_page' => __('No page was specified.', 'datalumo'),
                'no_cart' => __('The cart is not available.', 'datalumo'),
                'no_checkout' => __('Checkout is not available.', 'datalumo'),
            ],
        ]);
    }

    public function handle(): void
    {
        check_ajax_referer(self::NONCE);

        $payload = $this->requestPayload();
        $event = sanitize_key((string) ($payload['event'] ?? ''));

        if ($event !== 'open_page') {
            wp_send_json_error(['message' => __('Unknown action.', 'datalumo')], 400);
        }

        $url = $this->resolveUrl($event, $payload);

        if ($url === null) {
            wp_send_json_error(['message' => __('No page was specified.', 'datalumo')], 422);
        }

        wp_send_json_success(['url' => $url]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveUrl(string $event, array $payload): ?string
    {
        if ($event === 'view_cart') {
            return $this->cartUrl() ?: null;
        }

        if ($event === 'open_checkout') {
            return $this->checkoutUrl() ?: null;
        }

        if ($event === 'open_page') {
            return $this->publishedUrlFromPayload($payload);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publishedUrlFromPayload(array $payload): ?string
    {
        $url = trim((string) ($payload['url'] ?? ''));

        if ($url !== '' && $this->isSameSiteUrl($url)) {
            return $url;
        }

        $id = $this->positiveId(
            $payload['page_id'] ?? $payload['id'] ?? $payload['external_id'] ?? null,
        );

        if ($id !== null) {
            $permalink = $this->publishedPermalink($id);

            if ($permalink !== null) {
                return $permalink;
            }
        }

        $slug = trim((string) ($payload['slug'] ?? ''));

        if ($slug !== '' && function_exists('get_page_by_path')) {
            $page = get_page_by_path($slug);

            if (is_object($page) && isset($page->ID)) {
                return $this->publishedPermalink((int) $page->ID);
            }
        }

        return null;
    }

    public function isSameSiteUrl(string $url): bool
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        $home = $this->homeHost();

        if ($home === '' || $host === '') {
            return false;
        }

        return $host === $home || $host === 'www.' . $home || 'www.' . $host === $home;
    }

    public function cartUrl(): string
    {
        if (! function_exists('wc_get_cart_url')) {
            return '';
        }

        $url = (string) wc_get_cart_url();

        return $this->isSameSiteUrl($url) ? $url : '';
    }

    public function checkoutUrl(): string
    {
        if (! function_exists('wc_get_checkout_url')) {
            return '';
        }

        $url = (string) wc_get_checkout_url();

        return $this->isSameSiteUrl($url) ? $url : '';
    }

    private function homeHost(): string
    {
        $home = function_exists('home_url') ? home_url('/') : '';
        $host = wp_parse_url($home, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    private function publishedPermalink(int $id): ?string
    {
        if ($id <= 0 || ! function_exists('get_post') || ! function_exists('get_permalink')) {
            return null;
        }

        $post = get_post($id);

        if (! is_object($post) || ($post->post_status ?? '') !== 'publish') {
            return null;
        }

        $permalink = (string) get_permalink($post);

        return $this->isSameSiteUrl($permalink) ? $permalink : null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $id = (int) $value;

            return $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON decoded below.
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
