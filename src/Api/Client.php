<?php

namespace Datalumo\Wp\Api;

use Datalumo\Wp\Support\Options;

/**
 * Thin wp_remote_* client for the Datalumo API. Two auth modes:
 *
 * - Token requests (content sync): Bearer org API token against the
 *   /api/v1/{org}/… push endpoints.
 * - Widget requests (enhanced search): this site's Origin header, authorised
 *   as long as the site's domain is in the widget's website list.
 */
class Client
{
    /**
     * GET /me — connection test + organisation/source discovery. Tokens are
     * org-scoped, so both id and token are needed to connect.
     *
     * @param string|null $baseUrl Optional override (e.g. from the settings
     *                             form before it has been saved).
     */
    public function me(string $organisationId, string $token, ?string $baseUrl = null): array
    {
        return $this->request('GET', $this->orgUrl($organisationId, 'me', $baseUrl), token: $token);
    }

    public function pushPage(string $sourceId, array $payload): array
    {
        return $this->request('POST', $this->sourceUrl($sourceId, 'pages'), $payload);
    }

    public function pushBatch(string $sourceId, array $pages): array
    {
        return $this->request('POST', $this->sourceUrl($sourceId, 'pages/batch'), ['pages' => $pages]);
    }

    public function deletePage(string $sourceId, string $externalId): void
    {
        $this->request('DELETE', $this->sourceUrl($sourceId, 'pages/' . rawurlencode($externalId)));
    }

    /**
     * Kick indexing for a source and get its progress back. Idempotent on the
     * Datalumo side — safe to call any time; with nothing to index it is a
     * plain status read (total / indexed / indexing / failed / queued).
     */
    public function indexSource(string $sourceId): array
    {
        return $this->request('POST', $this->sourceUrl($sourceId, 'index'));
    }

    /**
     * Widget search, authorised by this site's Origin.
     *
     * @param string $widgetKey compound "org-id/widget-id"
     */
    public function search(string $widgetKey, string $query, int $limit = 50, ?string $sort = null): array
    {
        [$org, $widget] = $this->parseWidgetKey($widgetKey);

        $payload = array_filter(['query' => $query, 'limit' => $limit, 'sort' => $sort]);

        return $this->request(
            'POST',
            $this->orgUrl($org, "widgets/{$widget}/search"),
            $payload,
            origin: home_url(),
        );
    }

    private function orgUrl(string $organisationId, string $path, ?string $baseUrl = null): string
    {
        $base = $baseUrl !== null && $baseUrl !== ''
            ? Options::normaliseBaseUrl($baseUrl)
            : Options::baseUrl();

        return $base . '/api/v1/' . rawurlencode($organisationId) . '/' . $path;
    }

    private function sourceUrl(string $sourceId, string $path): string
    {
        $org = (string) Options::get('organisation.id');

        return $this->orgUrl($org, 'sources/' . rawurlencode($sourceId) . '/' . $path);
    }

    /**
     * @return array{0: string, 1: string} [organisation id, widget id]
     */
    public function parseWidgetKey(string $key): array
    {
        $parts = explode('/', trim($key), 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ApiException(esc_html__('Invalid widget key — copy it from the widget editor.', 'datalumo'));
        }

        return [$parts[0], $parts[1]];
    }

    private function request(string $method, string $url, array $body = [], ?string $token = null, ?string $origin = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($origin !== null) {
            $headers['Origin'] = $origin;
        } else {
            $token ??= (string) Options::get('api_token');

            if ($token !== '') {
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body === [] ? null : wp_json_encode($body),
            'timeout' => 30,
            'sslverify' => ! (defined('DATALUMO_SSL_VERIFY') && DATALUMO_SSL_VERIFY === false),
        ]);

        if (is_wp_error($response)) {
            throw new ApiException(esc_html($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 400) {
            if (is_array($decoded) && isset($decoded['message'])) {
                $message = (string) $decoded['message'];
            } else {
                /* translators: %d: HTTP status code */
                $message = sprintf(esc_html__('Datalumo request failed (%d).', 'datalumo'), $status);
            }

            $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception payload, not rendered output.
            throw new ApiException(esc_html($message), $status, $retryAfter > 0 ? $retryAfter : null);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
