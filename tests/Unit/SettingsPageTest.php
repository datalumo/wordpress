<?php

use Brain\Monkey\Functions;
use Datalumo\Wp\Admin\SettingsPage;
use Datalumo\Wp\Support\Options;

it('rejects a secret or token in a widget key field and keeps the current value', function (string $pasted) {
    Functions\when('sanitize_text_field')->returnArg(1);

    $page = new SettingsPage();
    $method = new ReflectionMethod(SettingsPage::class, 'sanitizeWidgetKey');
    $rejected = new ReflectionProperty(SettingsPage::class, 'widgetKeyRejected');

    expect($method->invoke($page, $pasted, 'org-1/widget-2'))->toBe('org-1/widget-2')
        ->and($rejected->getValue($page))->toBeTrue();
})->with([
    'dl_secret',
    '1|plain-api-token',
    'not-a-key',
]);

it('does not print a second Settings saved notice', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/settings.php');

    expect($view)->not->toContain("esc_html_e('Settings saved.'");
});

it('points settings help at the WordPress plugin docs', function () {
    expect(SettingsPage::docsUrl())->toBe('https://datalumo.app/docs/wordpress')
        ->and(SettingsPage::docsUrl('chat-page-actions'))->toBe('https://datalumo.app/docs/wordpress#chat-page-actions')
        ->and(SettingsPage::helpUrl())->toBe('https://datalumo.app/docs/wordpress')
        ->and(SettingsPage::ASK_MAX_LENGTH)->toBe(280);
});

it('hides the self-hosted URL behind a disclosure on connect', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/settings.php');

    expect($view)->toContain('datalumo-self-host')
        ->and($view)->toContain("Using a self-hosted Datalumo?")
        ->and($view)->toContain('datalumo-grant-api-url');
});

it('hides the settings sidebar until setup is ready', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/settings.php');

    expect($view)->toContain('datalumo-settings-aside')
        ->and(substr_count($view, 'if (SettingsPage::setupIsReady())'))->toBe(2);
});

it('hides other tabs until the post-connect checklist is saved', function () {
    Options::merge([
        'api_token' => 'tok_1',
        'organisation' => ['id' => 'org_1'],
        'setup_pending' => true,
    ]);

    expect(SettingsPage::setupIsReady())->toBeFalse();

    Options::set('setup_pending', false);

    expect(SettingsPage::setupIsReady())->toBeTrue();
});

it('accepts a compound widget key', function () {
    Functions\when('sanitize_text_field')->returnArg(1);

    $page = new SettingsPage();
    $method = new ReflectionMethod(SettingsPage::class, 'sanitizeWidgetKey');
    $rejected = new ReflectionProperty(SettingsPage::class, 'widgetKeyRejected');

    expect($method->invoke($page, 'org-1/widget-2', ''))->toBe('org-1/widget-2')
        ->and($rejected->getValue($page))->toBeFalse();
});
