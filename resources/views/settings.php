<?php

use Datalumo\Wp\Admin\SettingsPage;
use Datalumo\Wp\Support\Options;

if (! defined('ABSPATH')) {
    exit;
}

/** @var string $tab (set by SettingsPage::render) */
$organisation = Options::get('organisation', []);
$sources = (array) Options::get('sources', []);
$syncs = (array) Options::get('syncs', []);
$postTypes = get_post_types(['public' => true], 'objects');
unset($postTypes['attachment']);

$tabs = [
    'connection' => __('Connection', 'datalumo'),
    'content-sync' => __('Content sync', 'datalumo'),
    'chatbot' => __('Chatbot', 'datalumo'),
    'search-box' => __('Search box', 'datalumo'),
    'enhanced-search' => __('Enhanced search', 'datalumo'),
];
?>
<div class="wrap datalumo-settings">
    <h1>Datalumo</h1>

    <?php if (! empty($_GET['updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'datalumo'); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper">
        <?php foreach ($tabs as $key => $label) : ?>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=datalumo&tab=' . $key)); ?>"
               class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="datalumo_save" />
        <input type="hidden" name="datalumo_tab" value="<?php echo esc_attr($tab); ?>" />
        <?php wp_nonce_field(SettingsPage::NONCE); ?>

        <?php if ($tab === 'connection') : ?>
            <table class="form-table" role="presentation">
                <?php if (! defined('DATALUMO_API_URL')) : ?>
                    <tr>
                        <th scope="row"><label for="datalumo-api-url"><?php esc_html_e('Datalumo URL', 'datalumo'); ?></label></th>
                        <td>
                            <input type="url" id="datalumo-api-url" name="api_url" class="regular-text"
                                   value="<?php echo esc_attr(Options::get('api_url', 'https://datalumo.com')); ?>" />
                            <p class="description"><?php esc_html_e('Leave as-is unless you were told otherwise.', 'datalumo'); ?></p>
                        </td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <th scope="row"><label for="datalumo-organisation-id"><?php esc_html_e('Organisation ID', 'datalumo'); ?></label></th>
                    <td>
                        <input type="text" id="datalumo-organisation-id" class="regular-text code"
                               value="<?php echo esc_attr($organisation['id'] ?? ''); ?>" />
                        <p class="description"><?php esc_html_e('Find it next to your API keys in the Datalumo dashboard.', 'datalumo'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="datalumo-token"><?php esc_html_e('API token', 'datalumo'); ?></label></th>
                    <td>
                        <input type="password" id="datalumo-token" class="regular-text code" autocomplete="off"
                               placeholder="<?php echo Options::get('api_token') ? esc_attr__('•••••••• (saved)', 'datalumo') : ''; ?>" />
                        <p class="description">
                            <?php if (Options::get('api_token')) : ?>
                                <?php esc_html_e('Leave blank to re-test, or paste a new token to replace it.', 'datalumo'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Create a token under API keys in your Datalumo dashboard.', 'datalumo'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"></th>
                    <td>
                        <button type="button" class="button button-secondary" id="datalumo-connect"><?php esc_html_e('Connect & test', 'datalumo'); ?></button>
                        <span id="datalumo-connect-result" class="datalumo-inline-result">
                            <?php if (Options::isConnected()) : ?>
                                <?php printf(
                                    esc_html__('Connected to %1$s (%2$d sources).', 'datalumo'),
                                    esc_html($organisation['name'] ?? $organisation['id'] ?? ''),
                                    count($sources),
                                ); ?>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>
            </table>
        <?php elseif ($tab === 'content-sync') : ?>
            <?php if (! Options::isConnected()) : ?>
                <p><?php esc_html_e('Connect your account first.', 'datalumo'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('Each sync pushes the chosen post types into one Datalumo source. Create a source of type "API" in your dashboard first.', 'datalumo'); ?></p>
                <div id="datalumo-syncs">
                    <?php foreach ($syncs === [] ? [[]] : $syncs as $index => $sync) : ?>
                        <fieldset class="datalumo-sync-row" data-index="<?php echo esc_attr((string) $index); ?>">
                            <input type="hidden" name="syncs[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($sync['id'] ?? ''); ?>" />
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row"><?php esc_html_e('Source', 'datalumo'); ?></th>
                                    <td>
                                        <select name="syncs[<?php echo esc_attr((string) $index); ?>][source_id]">
                                            <option value=""><?php esc_html_e('— choose a source —', 'datalumo'); ?></option>
                                            <?php foreach ($sources as $source) : ?>
                                                <option value="<?php echo esc_attr($source['id']); ?>" <?php selected(($sync['source_id'] ?? '') === $source['id']); ?>>
                                                    <?php echo esc_html($source['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Post types', 'datalumo'); ?></th>
                                    <td>
                                        <?php foreach ($postTypes as $postType) : ?>
                                            <label class="datalumo-checkbox">
                                                <input type="checkbox" name="syncs[<?php echo esc_attr((string) $index); ?>][post_types][]"
                                                       value="<?php echo esc_attr($postType->name); ?>"
                                                       <?php checked(in_array($postType->name, $sync['post_types'] ?? [], true)); ?> />
                                                <?php echo esc_html($postType->labels->name); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                                <?php if (($sync['id'] ?? '') !== '') : ?>
                                    <tr>
                                        <th scope="row"><?php esc_html_e('Full sync', 'datalumo'); ?></th>
                                        <td>
                                            <button type="button" class="button datalumo-sync-start" data-sync="<?php echo esc_attr($sync['id']); ?>"><?php esc_html_e('Sync now', 'datalumo'); ?></button>
                                            <button type="button" class="button datalumo-sync-cancel" data-sync="<?php echo esc_attr($sync['id']); ?>"><?php esc_html_e('Cancel', 'datalumo'); ?></button>
                                            <span class="datalumo-inline-result datalumo-sync-status" data-sync="<?php echo esc_attr($sync['id']); ?>"></span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
                <p class="description"><?php esc_html_e('Custom field and taxonomy mappings can be added per sync with the datalumo_page_payload filter for now.', 'datalumo'); ?></p>
                <?php submit_button(__('Save syncs', 'datalumo')); ?>
            <?php endif; ?>
        <?php elseif ($tab === 'chatbot') : ?>
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
                               value="<?php echo esc_attr(Options::get('chatbot.signing_secret', '')); ?>" />
                        <p class="description"><?php esc_html_e('From the same "For developers" section. Never exposed to the browser — used to sign user ids server-side.', 'datalumo'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="description"><?php esc_html_e('Tip: use the [datalumo_chat] shortcode to embed the chat inline on a page.', 'datalumo'); ?></p>
            <?php submit_button(); ?>
        <?php elseif ($tab === 'search-box') : ?>
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
        <?php elseif ($tab === 'enhanced-search') : ?>
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
                        <?php foreach ($postTypes as $postType) : ?>
                            <label class="datalumo-checkbox">
                                <input type="checkbox" name="enhanced_post_types[]" value="<?php echo esc_attr($postType->name); ?>"
                                       <?php checked(in_array($postType->name, (array) Options::get('enhanced.post_types', []), true)); ?> />
                                <?php echo esc_html($postType->labels->name); ?>
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
</div>
