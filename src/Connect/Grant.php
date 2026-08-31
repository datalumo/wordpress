<?php

namespace Datalumo\Wp\Connect;

use Datalumo\Wp\Api\ApiException;
use Datalumo\Wp\Api\Client;
use Datalumo\Wp\Support\Options;

class Grant
{
    public const ACTION_START = 'datalumo_connect_start';

    public const ACTION_CALLBACK = 'datalumo_oauth_callback';

    public const ACTION_DISCONNECT = 'datalumo_disconnect';

    public const ACTION_SETUP = 'datalumo_setup';

    public const TRANSIENT = 'datalumo_wp_grant_state';

    public static function register(): void
    {
        add_action('admin_post_'.self::ACTION_START, [self::class, 'start']);
        add_action('admin_post_'.self::ACTION_CALLBACK, [self::class, 'callback']);
        add_action('admin_post_'.self::ACTION_DISCONNECT, [self::class, 'disconnect']);
        add_action('admin_post_'.self::ACTION_SETUP, [self::class, 'saveSetup']);
    }

    public static function start(): void
    {
        self::authorise();
        check_admin_referer(self::ACTION_START);

        $state = wp_generate_uuid4();
        set_transient(self::TRANSIENT.'_'.get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        $apiUrl = isset($_POST['api_url'])
            ? (esc_url_raw(wp_unslash((string) $_POST['api_url'])) ?: Options::baseUrl())
            : Options::baseUrl();

        if (! defined('DATALUMO_API_URL') && $apiUrl !== '') {
            Options::set('api_url', $apiUrl);
        }

        $url = self::startUrl($apiUrl, $state);
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            self::redirectSettings('connection', 'grant_failed');
        }

        // Off-site on purpose. wp_safe_redirect would rewrite dl.test /
        // datalumo.app back to this site's admin.
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Datalumo grant URL is off-site.
        wp_redirect($url);
        exit;
    }

    public static function callback(): void
    {
        self::authorise();

        $expected = (string) get_transient(self::TRANSIENT.'_'.get_current_user_id());
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth IdP redirect cannot post a WP nonce; CSRF is the hashed state vs the per-user transient.
        $state = sanitize_text_field(wp_unslash((string) ($_GET['state'] ?? '')));
        $code = sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        delete_transient(self::TRANSIENT.'_'.get_current_user_id());

        if ($expected === '' || $state === '' || ! hash_equals($expected, $state) || $code === '') {
            self::redirectSettings('connection', 'grant_failed');
        }

        try {
            $payload = (new Client())->exchangeWordPressGrant($code, $state);
        } catch (ApiException $e) {
            self::redirectSettings('connection', 'grant_failed');
        }

        self::apply($payload);
        self::redirectSettings('connection', 'connected');
    }

    public static function disconnect(): void
    {
        self::authorise();
        check_admin_referer(self::ACTION_DISCONNECT);

        $chatbot = (array) Options::get('chatbot', []);
        $chatbot['widget_key'] = '';
        $chatbot['signing_secret'] = '';
        $chatbot['enabled'] = false;
        $chatbot['identity_enabled'] = false;

        $searchBox = (array) Options::get('search_box', []);
        $searchBox['widget_key'] = '';

        $enhanced = (array) Options::get('enhanced', []);
        $enhanced['widget_key'] = '';
        $enhanced['enabled'] = false;

        Options::merge([
            'api_token' => '',
            'organisation' => [],
            'sources' => [],
            'setup_pending' => false,
            'setup_source_id' => '',
            'connected_via' => '',
            'chatbot' => $chatbot,
            'search_box' => $searchBox,
            'enhanced' => $enhanced,
        ]);

        self::redirectSettings('connection', 'disconnected');
    }

    public static function saveSetup(): void
    {
        self::authorise();
        check_admin_referer(self::ACTION_SETUP);

        $input = wp_unslash($_POST);
        $sourceId = (string) Options::get('setup_source_id', '');
        $postTypes = array_map('sanitize_key', (array) ($input['setup_post_types'] ?? []));

        if ($sourceId !== '' && $postTypes !== []) {
            $syncs = (array) Options::get('syncs', []);
            $existing = false;

            foreach ($syncs as $index => $row) {
                if (($row['source_id'] ?? '') === $sourceId) {
                    $syncs[$index]['post_types'] = $postTypes;
                    $existing = true;
                    break;
                }
            }

            if (! $existing) {
                $syncs[] = [
                    'id' => wp_generate_uuid4(),
                    'source_id' => $sourceId,
                    'post_types' => $postTypes,
                    'meta_mappings' => [],
                ];
            }

            Options::set('syncs', $syncs);
        }

        $chatbot = (array) Options::get('chatbot', []);
        $chatbot['enabled'] = ! empty($input['setup_chat_enabled']);
        $chatbot['identity_enabled'] = ! empty($input['setup_identity_enabled']);
        Options::set('chatbot', $chatbot);

        $enhanced = (array) Options::get('enhanced', []);
        $enhanced['enabled'] = ! empty($input['setup_enhanced_enabled']);
        Options::set('enhanced', $enhanced);

        Options::set('setup_pending', false);

        $args = [
            'page' => 'datalumo',
            'tab' => 'connection',
            'datalumo_notice' => 'setup_saved',
            '_wpnonce' => wp_create_nonce('datalumo_settings_updated'),
        ];

        if (! empty($input['setup_sync_now']) && $sourceId !== '') {
            $syncId = '';

            foreach ((array) Options::get('syncs', []) as $row) {
                if (($row['source_id'] ?? '') === $sourceId) {
                    $syncId = (string) ($row['id'] ?? '');
                    break;
                }
            }

            if ($syncId !== '') {
                $args['tab'] = 'content-sync';
                $args['datalumo_sync'] = $syncId;
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function apply(array $payload): void
    {
        $organisation = is_array($payload['organisation'] ?? null) ? $payload['organisation'] : [];
        $sources = is_array($payload['sources'] ?? null) ? $payload['sources'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $chatbot = is_array($payload['chatbot'] ?? null) ? $payload['chatbot'] : null;
        $search = is_array($payload['search'] ?? null) ? $payload['search'] : null;

        $sourceId = (string) ($source['id'] ?? '');
        $stored = [
            'api_token' => (string) ($payload['token'] ?? ''),
            'organisation' => $organisation,
            'sources' => $sources,
            'setup_pending' => true,
            'setup_source_id' => $sourceId,
            'connected_via' => 'grant',
        ];

        if ($sourceId !== '') {
            $syncs = (array) Options::get('syncs', []);
            $hasSource = false;

            foreach ($syncs as $row) {
                if (($row['source_id'] ?? '') === $sourceId) {
                    $hasSource = true;
                    break;
                }
            }

            if (! $hasSource) {
                $syncs[] = [
                    'id' => wp_generate_uuid4(),
                    'source_id' => $sourceId,
                    'post_types' => ['post', 'page'],
                    'meta_mappings' => [],
                ];
                $stored['syncs'] = $syncs;
            }
        }

        if (is_array($chatbot)) {
            $stored['chatbot'] = array_merge((array) Options::get('chatbot', []), [
                'widget_key' => (string) ($chatbot['widget_key'] ?? ''),
                'signing_secret' => (string) ($chatbot['signing_secret'] ?? ''),
            ]);
        }

        if (is_array($search)) {
            $key = (string) ($search['widget_key'] ?? '');
            $stored['search_box'] = array_merge((array) Options::get('search_box', []), [
                'widget_key' => $key,
            ]);
            $stored['enhanced'] = array_merge((array) Options::get('enhanced', []), [
                'widget_key' => $key,
            ]);
        }

        Options::merge($stored);
    }

    public static function startUrl(string $baseUrl, string $state): string
    {
        $base = Options::normaliseBaseUrl($baseUrl);
        $home = home_url('/');
        $host = (string) wp_parse_url($home, PHP_URL_HOST);

        return $base.'/integrations/wordpress?'.http_build_query([
            'site' => $host,
            'site_url' => $home,
            'return' => admin_url('admin-post.php?action='.self::ACTION_CALLBACK),
            'cancel' => admin_url('options-general.php').'?page=datalumo&tab=connection',
            'state' => $state,
        ]);
    }

    private static function authorise(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'datalumo'));
        }
    }

    private static function redirectSettings(string $tab, string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => 'datalumo',
                'tab' => $tab,
                'datalumo_notice' => $notice,
                '_wpnonce' => wp_create_nonce('datalumo_settings_updated'),
            ],
            admin_url('options-general.php'),
        ));
        exit;
    }
}
