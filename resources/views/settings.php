<?php

use Datalumo\Wp\Admin\SettingsPage;
use Datalumo\Wp\Connect\Grant;
use Datalumo\Wp\Support\Options;

if (! defined('ABSPATH')) {
    exit;
}

/** @var string $datalumo_tab (set by SettingsPage::render) */
$datalumo_organisation = Options::get('organisation', []);
$datalumo_sources = (array) Options::get('sources', []);
$datalumo_syncs = (array) Options::get('syncs', []);
$datalumo_post_types = get_post_types(['public' => true], 'objects');
unset($datalumo_post_types['attachment']);

$datalumo_tabs = [
    'connection' => __('Connection', 'datalumo'),
    'content-sync' => __('Content sync', 'datalumo'),
    'chatbot' => __('Chatbot', 'datalumo'),
    'search-box' => __('Search box', 'datalumo'),
    'enhanced-search' => __('Enhanced search', 'datalumo'),
];

$datalumo_nonce_ok = isset($_GET['_wpnonce'])
    && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'datalumo_settings_updated');
$datalumo_notice = $datalumo_nonce_ok && isset($_GET['datalumo_notice'])
    ? sanitize_key(wp_unslash($_GET['datalumo_notice']))
    : '';
$datalumo_notices = [
    'connected' => [__('Connected to Datalumo.', 'datalumo'), 'success'],
    'grant_failed' => [__('Connect did not finish. Start again from this page.', 'datalumo'), 'error'],
    'disconnected' => [__('Disconnected. The API key remains in Datalumo under API keys until you revoke it there.', 'datalumo'), 'success'],
    'setup_saved' => [__('Setup saved.', 'datalumo'), 'success'],
    'invalid_widget_key' => [__('Widget keys look like org-id/widget-id. An API token or secret will not work there.', 'datalumo'), 'error'],
];
$datalumo_connected = Options::isConnected();
$datalumo_setup_pending = $datalumo_connected && (bool) Options::get('setup_pending');
$datalumo_chat_key = (string) Options::get('chatbot.widget_key', '');
$datalumo_search_key = (string) Options::get('search_box.widget_key', '') ?: (string) Options::get('enhanced.widget_key', '');
$datalumo_setup_source_id = (string) Options::get('setup_source_id', '');
$datalumo_setup_types = ['post', 'page'];

foreach ($datalumo_syncs as $datalumo_sync_row) {
    if (($datalumo_sync_row['source_id'] ?? '') === $datalumo_setup_source_id) {
        $datalumo_setup_types = $datalumo_sync_row['post_types'] ?? $datalumo_setup_types;
        break;
    }
}
?>
<div class="wrap datalumo-settings">
    <h1>Datalumo</h1>

    <?php if (isset($datalumo_notices[$datalumo_notice])) : ?>
        <div class="notice notice-<?php echo esc_attr($datalumo_notices[$datalumo_notice][1]); ?> is-dismissible">
            <p><?php echo esc_html($datalumo_notices[$datalumo_notice][0]); ?></p>
        </div>
    <?php endif; ?>

    <?php if (SettingsPage::setupIsReady()) : ?>
        <nav class="nav-tab-wrapper">
            <?php foreach ($datalumo_tabs as $datalumo_tab_key => $datalumo_tab_label) : ?>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=datalumo&tab=' . $datalumo_tab_key)); ?>"
                   class="nav-tab <?php echo $datalumo_tab === $datalumo_tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($datalumo_tab_label); ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <?php
    $datalumo_docs_hash = match ($datalumo_tab) {
        'content-sync' => 'for-developers',
        'chatbot' => 'chat-page-actions',
        'search-box' => 'add-a-search-box',
        'enhanced-search' => 'enhanced-search',
        default => '',
    };
    $datalumo_docs_hint = match ($datalumo_tab) {
        'content-sync' => __('Custom fields, filters, and sync details.', 'datalumo'),
        'chatbot' => __('Shortcodes, visitor identity, and chat page actions.', 'datalumo'),
        'search-box', 'enhanced-search' => __('Search box shortcode and enhanced search.', 'datalumo'),
        default => __('Connect, sync, and widgets.', 'datalumo'),
    };
    ?>

    <div class="datalumo-settings-layout">
    <div class="datalumo-settings-main">

    <?php if ($datalumo_tab === 'connection') : ?>
        <?php if ($datalumo_connected) : ?>
            <?php if (! $datalumo_setup_pending) : ?>
                <form id="datalumo-disconnect" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(Grant::ACTION_DISCONNECT); ?>" />
                    <?php wp_nonce_field(Grant::ACTION_DISCONNECT); ?>
                </form>
            <?php endif; ?>

            <?php if ($datalumo_setup_pending) : ?>
                <?php $datalumo_setup_step = 1; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="datalumo-checklist">
                    <input type="hidden" name="action" value="<?php echo esc_attr(Grant::ACTION_SETUP); ?>" />
                    <?php wp_nonce_field(Grant::ACTION_SETUP); ?>
                    <header class="datalumo-setup-header">
                        <h2><?php esc_html_e('Finish setup', 'datalumo'); ?></h2>
                        <p><?php esc_html_e('Pick a starting setup. You can change these later.', 'datalumo'); ?></p>
                    </header>

                    <fieldset class="datalumo-setup-card">
                        <legend>
                            <span class="datalumo-setup-step"><?php echo esc_html((string) $datalumo_setup_step++); ?></span>
                            <?php esc_html_e('What to sync', 'datalumo'); ?>
                        </legend>
                        <div class="datalumo-setup-types">
                            <?php foreach ($datalumo_post_types as $datalumo_post_type) : ?>
                                <label class="datalumo-checkbox">
                                    <input type="checkbox" name="setup_post_types[]"
                                           value="<?php echo esc_attr($datalumo_post_type->name); ?>"
                                           <?php checked(in_array($datalumo_post_type->name, $datalumo_setup_types, true)); ?> />
                                    <?php echo esc_html($datalumo_post_type->labels->name); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <?php if ($datalumo_chat_key !== '') : ?>
                        <fieldset class="datalumo-setup-card">
                            <legend>
                                <span class="datalumo-setup-step"><?php echo esc_html((string) $datalumo_setup_step++); ?></span>
                                <?php esc_html_e('Chat', 'datalumo'); ?>
                            </legend>
                            <div class="datalumo-setup-options">
                                <label class="datalumo-checkbox">
                                    <input type="checkbox" name="setup_chat_enabled" value="1" <?php checked((bool) Options::get('chatbot.enabled')); ?> />
                                    <?php esc_html_e('Show the floating chat on every page', 'datalumo'); ?>
                                </label>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <?php if ($datalumo_search_key !== '') : ?>
                        <fieldset class="datalumo-setup-card">
                            <legend>
                                <span class="datalumo-setup-step"><?php echo esc_html((string) $datalumo_setup_step++); ?></span>
                                <?php esc_html_e('Search', 'datalumo'); ?>
                            </legend>
                            <div class="datalumo-setup-options">
                                <label class="datalumo-checkbox">
                                    <input type="checkbox" name="setup_enhanced_enabled" value="1" <?php checked((bool) Options::get('enhanced.enabled')); ?> />
                                    <?php esc_html_e('Use Datalumo for WordPress search results', 'datalumo'); ?>
                                </label>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <p class="datalumo-setup-actions">
                        <?php submit_button(__('Save and sync now', 'datalumo'), 'primary', 'setup_sync_now', false); ?>
                        <?php submit_button(__('Save setup', 'datalumo'), 'secondary', 'submit', false); ?>
                    </p>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="datalumo-setup-disconnect">
                    <input type="hidden" name="action" value="<?php echo esc_attr(Grant::ACTION_DISCONNECT); ?>" />
                    <?php wp_nonce_field(Grant::ACTION_DISCONNECT); ?>
                    <button type="submit" class="button-link"><?php esc_html_e('Disconnect', 'datalumo'); ?></button>
                </form>
            <?php endif; ?>
        <?php else : ?>
            <div id="datalumo-mode-grant" class="datalumo-connect-hero">
                <p><?php esc_html_e('Connect this site to Datalumo. You will pick or create a knowledge base and widgets, then we create a source and API key for you.', 'datalumo'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(Grant::ACTION_START); ?>" />
                    <?php wp_nonce_field(Grant::ACTION_START); ?>
                    <?php submit_button(__('Connect with Datalumo', 'datalumo'), 'primary', 'submit', false); ?>
                    <?php if (! defined('DATALUMO_API_URL')) : ?>
                        <?php
                        $datalumo_grant_url = (string) Options::get('api_url', 'https://datalumo.app');
                        $datalumo_self_hosted = Options::normaliseBaseUrl($datalumo_grant_url) !== 'https://datalumo.app';
                        ?>
                        <details class="datalumo-self-host"<?php echo $datalumo_self_hosted ? ' open' : ''; ?>>
                            <summary><?php esc_html_e('Using a self-hosted Datalumo?', 'datalumo'); ?></summary>
                            <p>
                                <label for="datalumo-grant-api-url"><?php esc_html_e('Datalumo URL', 'datalumo'); ?></label>
                                <input type="url" id="datalumo-grant-api-url" name="api_url" class="regular-text"
                                       value="<?php echo esc_attr($datalumo_grant_url); ?>" />
                            </p>
                        </details>
                    <?php endif; ?>
                </form>
                <p>
                    <button type="button" class="button-link" data-datalumo-mode="manual"><?php esc_html_e('Manual setup', 'datalumo'); ?></button>
                </p>
            </div>
        <?php endif; ?>

        <div id="datalumo-mode-manual" class="datalumo-manual"<?php echo ($datalumo_connected && ! $datalumo_setup_pending) ? '' : ' hidden'; ?>>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="datalumo_save" />
                <input type="hidden" name="datalumo_tab" value="connection" />
                <?php wp_nonce_field(SettingsPage::NONCE); ?>
                <table class="form-table" role="presentation">
                    <?php if ($datalumo_connected) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Organisation', 'datalumo'); ?></th>
                            <td>
                                <div class="datalumo-org-status">
                                    <span><?php echo esc_html($datalumo_organisation['name'] ?? $datalumo_organisation['id'] ?? ''); ?></span>
                                    <button type="submit" form="datalumo-disconnect" class="button"><?php esc_html_e('Disconnect', 'datalumo'); ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (! defined('DATALUMO_API_URL')) : ?>
                        <tr>
                            <th scope="row"><label for="datalumo-api-url"><?php esc_html_e('Datalumo URL', 'datalumo'); ?></label></th>
                            <td>
                                <input type="url" id="datalumo-api-url" name="api_url" class="regular-text"
                                       value="<?php echo esc_attr(Options::get('api_url', 'https://datalumo.app')); ?>" />
                                <p class="description"><?php esc_html_e('Leave as-is unless you were told otherwise.', 'datalumo'); ?></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><label for="datalumo-token"><?php esc_html_e('API token', 'datalumo'); ?></label></th>
                        <td>
                            <input type="password" id="datalumo-token" class="regular-text code" autocomplete="off"
                                   placeholder="<?php echo Options::get('api_token') ? esc_attr__('•••••••• (saved)', 'datalumo') : ''; ?>" />
                            <p class="description"><?php esc_html_e('Paste a token from API keys. The organisation is inferred from the token.', 'datalumo'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <button type="button" class="button button-primary" id="datalumo-connect"><?php esc_html_e('Connect & test', 'datalumo'); ?></button>
                            <?php if (! defined('DATALUMO_API_URL')) : ?>
                                <?php submit_button(__('Save URL', 'datalumo'), 'secondary', 'submit', false); ?>
                            <?php endif; ?>
                            <span id="datalumo-connect-result" class="datalumo-inline-result"></span>
                            <?php if (! $datalumo_connected) : ?>
                                <p>
                                    <button type="button" class="button-link" data-datalumo-mode="grant"><?php esc_html_e('Connect with Datalumo', 'datalumo'); ?></button>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </form>
        </div>
    <?php else : ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="datalumo_save" />
        <input type="hidden" name="datalumo_tab" value="<?php echo esc_attr($datalumo_tab); ?>" />
        <?php wp_nonce_field(SettingsPage::NONCE); ?>

        <?php if ($datalumo_tab === 'content-sync') : ?>
            <?php if (! Options::isConnected()) : ?>
                <p><?php esc_html_e('Connect your account first.', 'datalumo'); ?></p>
            <?php else : ?>
                <?php
                $datalumo_sync_rows = $datalumo_syncs === [] ? [[]] : $datalumo_syncs;
                $datalumo_sync_multiple = count($datalumo_sync_rows) > 1;
                ?>
                <div id="datalumo-syncs">
                    <?php foreach ($datalumo_sync_rows as $datalumo_sync_index => $datalumo_sync) : ?>
                        <fieldset class="datalumo-sync-row<?php echo $datalumo_sync_multiple ? ' is-multiple' : ''; ?>" data-index="<?php echo esc_attr((string) $datalumo_sync_index); ?>">
                            <input type="hidden" name="syncs[<?php echo esc_attr((string) $datalumo_sync_index); ?>][id]" value="<?php echo esc_attr($datalumo_sync['id'] ?? ''); ?>" />
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Source', 'datalumo'); ?></th>
                                    <td>
                                        <select name="syncs[<?php echo esc_attr((string) $datalumo_sync_index); ?>][source_id]">
                                            <option value=""><?php esc_html_e('— choose a source —', 'datalumo'); ?></option>
                                            <?php
                                            $datalumo_selected_kb = '';
                                            foreach ($datalumo_sources as $datalumo_source) :
                                                $datalumo_kb = is_array($datalumo_source['knowledge_base'] ?? null)
                                                    ? (string) ($datalumo_source['knowledge_base']['name'] ?? '')
                                                    : '';
                                                if (($datalumo_sync['source_id'] ?? '') === ($datalumo_source['id'] ?? '')) {
                                                    $datalumo_selected_kb = $datalumo_kb;
                                                }
                                                $datalumo_source_label = $datalumo_kb !== ''
                                                    ? $datalumo_source['name'].' ('.$datalumo_kb.')'
                                                    : (string) ($datalumo_source['name'] ?? '');
                                                ?>
                                                <option value="<?php echo esc_attr($datalumo_source['id']); ?>" <?php selected(($datalumo_sync['source_id'] ?? '') === $datalumo_source['id']); ?>>
                                                    <?php echo esc_html($datalumo_source_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($datalumo_selected_kb !== '') : ?>
                                            <p class="description">
                                                <?php
                                                printf(
                                                    /* translators: %s: knowledge base name */
                                                    esc_html__('Knowledge base: %s', 'datalumo'),
                                                    esc_html($datalumo_selected_kb),
                                                );
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Post types', 'datalumo'); ?></th>
                                    <td>
                                        <?php foreach ($datalumo_post_types as $datalumo_post_type) : ?>
                                            <label class="datalumo-checkbox">
                                                <input type="checkbox" name="syncs[<?php echo esc_attr((string) $datalumo_sync_index); ?>][post_types][]"
                                                       value="<?php echo esc_attr($datalumo_post_type->name); ?>"
                                                       <?php checked(in_array($datalumo_post_type->name, $datalumo_sync['post_types'] ?? [], true)); ?> />
                                                <?php echo esc_html($datalumo_post_type->labels->name); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php if (($datalumo_sync['id'] ?? '') !== '') : ?>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Full sync', 'datalumo'); ?></th>
                                        <td class="datalumo-sync-controls" data-sync="<?php echo esc_attr($datalumo_sync['id']); ?>">
                                            <button type="button" class="button datalumo-sync-start" data-sync="<?php echo esc_attr($datalumo_sync['id']); ?>"><?php esc_html_e('Sync now', 'datalumo'); ?></button>
                                            <button type="button" class="button datalumo-sync-cancel" data-sync="<?php echo esc_attr($datalumo_sync['id']); ?>" disabled><?php esc_html_e('Cancel', 'datalumo'); ?></button>
                                            <span class="datalumo-sync-spinner" aria-hidden="true"></span>
                                            <span class="datalumo-inline-result datalumo-sync-status" data-sync="<?php echo esc_attr($datalumo_sync['id']); ?>"></span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <?php submit_button(__('Save syncs', 'datalumo')); ?>
            <?php endif; ?>
        <?php elseif ($datalumo_tab === 'chatbot') : ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Chat widget', 'datalumo'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="chatbot_enabled" value="1" <?php checked((bool) Options::get('chatbot.enabled')); ?> />
                            <?php esc_html_e('Show the floating chat on every page', 'datalumo'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="datalumo-chatbot-key"><?php esc_html_e('Widget key', 'datalumo'); ?></label></th>
                    <td>
                        <input type="text" id="datalumo-chatbot-key" name="chatbot_widget_key" class="regular-text code"
                               value="<?php echo esc_attr(Options::get('chatbot.widget_key', '')); ?>" />
                        <p class="description"><?php esc_html_e('Copy it from the widget editor ("For developers"). Add this site to the widget\'s websites list.', 'datalumo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Visitor identity', 'datalumo'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="chatbot_identity_enabled" value="1" <?php checked((bool) Options::get('chatbot.identity_enabled')); ?> />
                            <?php esc_html_e('Let logged-in users continue their conversations across visits', 'datalumo'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="datalumo-signing-secret"><?php esc_html_e('Identity signing secret', 'datalumo'); ?></label></th>
                    <td>
                        <input type="password" id="datalumo-signing-secret" name="chatbot_signing_secret" class="regular-text code" autocomplete="off"
                               placeholder="<?php echo Options::get('chatbot.signing_secret') ? esc_attr__('•••••••• (saved)', 'datalumo') : ''; ?>" />
                        <p class="description"><?php esc_html_e('From the same "For developers" section. Never exposed to the browser. Used to sign user ids server-side.', 'datalumo'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        <?php elseif ($datalumo_tab === 'search-box') : ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="datalumo-search-key"><?php esc_html_e('Widget key', 'datalumo'); ?></label></th>
                    <td>
                        <input type="text" id="datalumo-search-key" name="search_box_widget_key" class="regular-text code"
                               value="<?php echo esc_attr(Options::get('search_box.widget_key', '')); ?>" />
                        <p class="description"><?php esc_html_e('A search-type widget key. Place the box anywhere with the [datalumo_search] shortcode.', 'datalumo'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        <?php elseif ($datalumo_tab === 'enhanced-search') : ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enhanced search', 'datalumo'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enhanced_enabled" value="1" <?php checked((bool) Options::get('enhanced.enabled')); ?> />
                            <?php esc_html_e('Serve WordPress search results from Datalumo (falls back to native search on any error)', 'datalumo'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="datalumo-enhanced-key"><?php esc_html_e('Widget key', 'datalumo'); ?></label></th>
                    <td>
                        <input type="text" id="datalumo-enhanced-key" name="enhanced_widget_key" class="regular-text code"
                               value="<?php echo esc_attr(Options::get('enhanced.widget_key', '')); ?>" />
                        <p class="description"><?php esc_html_e('A search-type widget key; this site must be in its websites list.', 'datalumo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Limit to post types', 'datalumo'); ?></th>
                    <td>
                        <?php foreach ($datalumo_post_types as $datalumo_post_type) : ?>
                            <label class="datalumo-checkbox">
                                <input type="checkbox" name="enhanced_post_types[]" value="<?php echo esc_attr($datalumo_post_type->name); ?>"
                                       <?php checked(in_array($datalumo_post_type->name, (array) Options::get('enhanced.post_types', []), true)); ?> />
                                <?php echo esc_html($datalumo_post_type->labels->name); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description"><?php esc_html_e('Leave all unchecked to enhance every search.', 'datalumo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('AI summary', 'datalumo'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enhanced_summary_enabled" value="1" <?php checked((bool) Options::get('enhanced.summary_enabled')); ?> />
                            <?php esc_html_e('Show a streamed AI answer above the results', 'datalumo'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="datalumo-summary-selector"><?php esc_html_e('Summary placement', 'datalumo'); ?></label></th>
                    <td>
                        <input type="text" id="datalumo-summary-selector" name="enhanced_summary_selector" class="regular-text code"
                               placeholder="<?php esc_attr_e('auto', 'datalumo'); ?>"
                               value="<?php echo esc_attr(Options::get('enhanced.summary_selector', '')); ?>" />
                        <select name="enhanced_summary_position">
                            <option value="prepend" <?php selected(Options::get('enhanced.summary_position') !== 'append'); ?>><?php esc_html_e('Before results', 'datalumo'); ?></option>
                            <option value="append" <?php selected(Options::get('enhanced.summary_position') === 'append'); ?>><?php esc_html_e('After results', 'datalumo'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Optional CSS selector of the results container; detected automatically when empty.', 'datalumo'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    </div>

    <?php if (SettingsPage::setupIsReady()) : ?>
        <aside class="datalumo-settings-aside">
            <form class="datalumo-help-card" method="get" action="<?php echo esc_url(SettingsPage::helpUrl()); ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="origin">
                <h2><?php esc_html_e('Need help?', 'datalumo'); ?></h2>
                <div class="datalumo-help-fields">
                    <label class="screen-reader-text" for="datalumo-help-ask"><?php esc_html_e('Ask a question', 'datalumo'); ?></label>
                    <textarea id="datalumo-help-ask" name="ask" required maxlength="<?php echo esc_attr((string) SettingsPage::ASK_MAX_LENGTH); ?>" rows="4" placeholder="<?php esc_attr_e('How do I sync products?', 'datalumo'); ?>"></textarea>
                    <button type="submit" class="button button-primary datalumo-help-submit">
                        <?php esc_html_e('Start chat', 'datalumo'); ?>
                        <svg class="datalumo-help-icon" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill="currentColor" d="M10 3c-3.87 0-7 2.69-7 6 0 1.52.7 2.9 1.86 3.98-.2.7-.62 1.72-1.6 2.77a.5.5 0 0 0 .36.8c1.7 0 2.86-.62 3.62-1.12A8.2 8.2 0 0 0 10 15c3.87 0 7-2.69 7-6s-3.13-6-7-6Z"/>
                        </svg>
                    </button>
                </div>
            </form>
            <div class="datalumo-docs-card">
                <p>
                    <a href="<?php echo esc_url(SettingsPage::docsUrl($datalumo_docs_hash)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('WordPress plugin docs', 'datalumo'); ?></a>
                </p>
                <p><?php echo esc_html($datalumo_docs_hint); ?></p>
            </div>
        </aside>
    <?php endif; ?>
    </div>
</div>
