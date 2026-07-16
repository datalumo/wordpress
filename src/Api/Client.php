<?php

namespace Datalumo\Wp\Api;

use Datalumo\Wp\Support\Options;

/**
 * Thin wp_remote_* client for the Datalumo API. Two authentication modes:
 *
 * - Token requests (content sync) send the organisation API token as a
 *   Bearer header against /api/v1/{org}/… push endpoints.
 * - Widget requests (enhanced search) send this site's Origin header, which
 *   authorises the call as long as the site's domain is in the widget's
 *   website list — the same requirement the embedded widgets already have.
 */
class Client
{
    /**
     * GET /me — connection test + organisation/source discovery. The API
     * scopes tokens to an organisation, so both are needed to connect.
     */
    public function me(string $organisationId, string $token): array
    {
        return $this->request('GET', $this->orgUrl($organisationId, 'me'), token: $token);
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

    private function orgUrl(string $organisationId, string $path): string
    {
        return Options::baseUrl() . '/api/v1/' . rawurlencode($organisationId) . '/' . $path;
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
            throw new ApiException(__('Invalid widget key — copy it from the widget editor.', 'datalumo'));
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
            throw new ApiException($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : sprintf(__('Datalumo request failed (%d).', 'datalumo'), $status);

            $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');

            throw new ApiException($message, $status, $retryAfter > 0 ? $retryAfter : null);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
