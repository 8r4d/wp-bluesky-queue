<?php
if (!defined('ABSPATH')) exit;

class WPBQ_Buffer_API {

    const ENDPOINT = 'https://api.buffer.com';

    private $api_key;
    private $channel_ids;

    public function __construct() {
        $this->api_key = trim(get_option('wpbq_buffer_api_key', ''));

        $raw_ids = get_option('wpbq_buffer_channel_ids', '');
        $this->channel_ids = array_filter(array_map('trim', explode(',', $raw_ids)));
    }

    /**
     * Run a GraphQL request against the Buffer API
     */
    private function graphql($query) {
        $response = wp_remote_post(self::ENDPOINT, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode(array('query' => $query)),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error = isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : 'HTTP ' . $code;
            return new WP_Error('buffer_http_error', $error);
        }

        if (!empty($body['errors'])) {
            return new WP_Error('buffer_graphql_error', $body['errors'][0]['message']);
        }

        return $body['data'];
    }

    /**
     * Encode a PHP value as a GraphQL literal. Wrap a value with
     * gql_enum() first for bare (unquoted) enum tokens like `automatic`.
     */
    private function gql_encode($value) {
        if (is_array($value) && isset($value['__gql_enum__'])) {
            return $value['__gql_enum__'];
        }

        if (is_array($value)) {
            $is_list = empty($value) || array_keys($value) === range(0, count($value) - 1);

            if ($is_list) {
                return '[' . implode(', ', array_map(array($this, 'gql_encode'), $value)) . ']';
            }

            $parts = array();
            foreach ($value as $key => $val) {
                $parts[] = $key . ': ' . $this->gql_encode($val);
            }
            return '{ ' . implode(', ', $parts) . ' }';
        }

        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string) $value;

        return wp_json_encode((string) $value);
    }

    private function gql_enum($token) {
        return array('__gql_enum__' => $token);
    }

    /**
     * Ask Buffer's own schema what a union/interface type's real member
     * names are, instead of relying on (repeatedly wrong) third-party docs.
     */
    private function introspect_possible_types($type_name) {
        $query = 'query { __type(name: ' . wp_json_encode($type_name) . ') { kind possibleTypes { name fields { name } } } }';
        $result = $this->graphql($query);

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['__type']) ? $result['__type'] : null;
    }

    /**
     * Queue a post to Buffer, which hands off publishing to whatever
     * networks are connected to the configured channel(s).
     */
    public function create_post($text, $link_url = '', $image_url = '') {
        if (empty($this->api_key) || empty($this->channel_ids)) {
            return new WP_Error('not_configured', 'Buffer API key and at least one channel ID are required');
        }

        // Buffer's link preview card is a dedicated asset, not text-embedded
        $assets = array();
        if (!empty($image_url)) {
            $assets[] = array('image' => array('url' => $image_url));
        } elseif (!empty($link_url)) {
            $assets[] = array('link' => array('url' => $link_url));
        }

        $posted = array();
        $errors = array();

        foreach ($this->channel_ids as $channel_id) {
            $input = array(
                'text'           => $text,
                'channelId'      => $channel_id,
                'schedulingType' => $this->gql_enum('automatic'),
                'mode'           => $this->gql_enum('addToQueue'),
                'needsApproval'  => false,
                'assets'         => $assets,
            );

            $mutation = 'mutation {
                createPost(input: ' . $this->gql_encode($input) . ') {
                    ... on PostActionSuccess { post { id } }
                    ... on NotFoundError { message }
                    ... on UnauthorizedError { message }
                    ... on UnexpectedError { message }
                    ... on RestProxyError { message code }
                    ... on LimitReachedError { message }
                    ... on InvalidInputError { message }
                }
            }';

            $result = $this->graphql($mutation);

            if (is_wp_error($result)) {
                $errors[] = $channel_id . ': ' . $result->get_error_message();
                continue;
            }

            $payload = isset($result['createPost']) ? $result['createPost'] : array();
            if (!empty($payload['message'])) {
                $errors[] = $channel_id . ': ' . $payload['message'];
            } elseif (!empty($payload['post']['id'])) {
                $posted[] = $payload['post']['id'];
            } else {
                $errors[] = $channel_id . ': unexpected response';
            }
        }

        if (empty($posted)) {
            return new WP_Error('buffer_post_failed', implode(' | ', $errors));
        }

        return array('post_ids' => $posted, 'errors' => $errors);
    }

    /**
     * Test the connection and list channels available on the account,
     * so the admin can copy the channel ID(s) into settings.
     */
    public function test_connection() {
        if (empty($this->api_key)) {
            return new WP_Error('not_configured', 'Buffer API key is required');
        }

        $orgs = $this->graphql('query { account { organizations { id } } }');
        if (is_wp_error($orgs)) {
            return $orgs;
        }

        $organizations = isset($orgs['account']['organizations']) ? $orgs['account']['organizations'] : array();
        $org_ids = wp_list_pluck($organizations, 'id');
        if (empty($org_ids)) {
            return new WP_Error('no_organizations', 'No organizations found on this Buffer account');
        }

        $all_channels = array();
        $errors = array();

        foreach ($org_ids as $org_id) {
            $channels_query = 'query { channels(input: ' . $this->gql_encode(array('organizationId' => $org_id)) . ') { id name service } }';
            $channels = $this->graphql($channels_query);

            if (is_wp_error($channels)) {
                $errors[] = $channels->get_error_message();
                continue;
            }

            $org_channels = isset($channels['channels']) ? $channels['channels'] : array();
            foreach ($org_channels as $channel) {
                $all_channels[] = $channel;
            }
        }

        if (empty($all_channels) && !empty($errors)) {
            return new WP_Error('buffer_channels_failed', implode(' | ', $errors));
        }

        $post_action_payload = $this->introspect_possible_types('PostActionPayload');

        return array(
            'channels'             => $all_channels, // Array of {id, name, service}
            'post_action_payload'  => is_wp_error($post_action_payload) ? null : $post_action_payload,
        );
    }
}
