<?php
/**
 * Push Notification Manager - Firebase Cloud Messaging
 * Handles FCM token registration and push sending
 */

if (!defined('ABSPATH')) exit;

class AsadMindset_Push_Notifications {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        $this->create_table();
    }

    private function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'push_tokens';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                token text NOT NULL,
                platform varchar(20) DEFAULT 'web',
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY is_active (is_active)
            ) $charset;";
            
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    private function get_user_id_from_request($r) {
        $auth = $r->get_header('Authorization');
        if (!$auth || strpos($auth, 'Bearer ') !== 0) return false;
        $token = substr($auth, 7);
        
        $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
        if (!$secret_key) return false;
        
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) return false;
            $payload = json_decode(base64_decode($parts[1]), true);
            if (!$payload || !isset($payload['data']['user']['id'])) return false;
            if (isset($payload['exp']) && $payload['exp'] < time()) return false;
            return $payload['data']['user']['id'];
        } catch (Exception $e) {
            return false;
        }
    }

    public function register_routes() {
        $ns = 'asadmindset/v1';

        register_rest_route($ns, '/push/register', [
            'methods'  => 'POST',
            'callback' => [$this, 'register_token'],
            'permission_callback' => function($r) { return (bool) $this->get_user_id_from_request($r); },
        ]);

        register_rest_route($ns, '/push/unregister', [
            'methods'  => 'POST',
            'callback' => [$this, 'unregister_token'],
            'permission_callback' => function($r) { return (bool) $this->get_user_id_from_request($r); },
        ]);

        register_rest_route($ns, '/push/presence', [
            'methods'  => 'POST',
            'callback' => [$this, 'update_presence'],
            'permission_callback' => function($r) { return (bool) $this->get_user_id_from_request($r); },
        ]);

        register_rest_route($ns, '/push/send', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_push'],
            'permission_callback' => function($r) {
                $uid = $this->get_user_id_from_request($r);
                if (!$uid) return false;
                $user = get_user_by('id', $uid);
                return $user && user_can($user, 'manage_options');
            },
        ]);
    }

    /**
     * POST /push/register - Save FCM token
     */
    public function register_token($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $params = $r->get_json_params();
        $token = sanitize_text_field($params['token'] ?? '');
        $platform = sanitize_text_field($params['platform'] ?? 'web');

        if (empty($token)) {
            return new WP_REST_Response(['message' => 'Token required'], 400);
        }

        $table = $wpdb->prefix . 'push_tokens';

        // Check if token already exists (globally - same token can't be for 2 users)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM $table WHERE token = %s",
            $token
        ));

        if ($existing) {
            $wpdb->update($table, [
                'user_id'    => $uid,
                'is_active'  => 1,
                'platform'   => $platform,
                'updated_at' => current_time('mysql'),
            ], ['id' => $existing->id]);
        } else {
            // Deactivate old tokens for this user (keep max 3)
            $old_tokens = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d AND is_active = 1 ORDER BY updated_at DESC LIMIT 99 OFFSET 3",
                $uid
            ));
            if (!empty($old_tokens)) {
                $ids = implode(',', array_map('intval', $old_tokens));
                $wpdb->query("UPDATE $table SET is_active = 0 WHERE id IN ($ids)");
            }

            $wpdb->insert($table, [
                'user_id'    => $uid,
                'token'      => $token,
                'platform'   => $platform,
                'is_active'  => 1,
                'created_at' => current_time('mysql'),
            ]);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST /push/unregister - Remove FCM token
     */
    public function unregister_token($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $params = $r->get_json_params();
        $token = sanitize_text_field($params['token'] ?? '');

        if (!empty($token)) {
            $table = $wpdb->prefix . 'push_tokens';
            $wpdb->update($table, ['is_active' => 0], ['user_id' => $uid, 'token' => $token]);
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * POST /push/presence - Update user presence in a chat
     * Body: { chatType: 'support'|'team', conversationId: int|null }
     */
    public function update_presence($r) {
        $uid = $this->get_user_id_from_request($r);
        $params = $r->get_json_params();
        $chat_type = sanitize_text_field($params['chatType'] ?? '');
        $conv_id = isset($params['conversationId']) ? (int) $params['conversationId'] : 0;

        if ($conv_id > 0 && $chat_type) {
            set_transient("chat_presence_{$chat_type}_{$conv_id}_{$uid}", true, 60);
        } else if ($chat_type && isset($params['leftConversationId'])) {
            delete_transient("chat_presence_{$chat_type}_{$params['leftConversationId']}_{$uid}");
        }

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Check if user is currently active in a specific conversation
     */
    public function is_user_in_chat($user_id, $chat_type, $conversation_id) {
        return (bool) get_transient("chat_presence_{$chat_type}_{$conversation_id}_{$user_id}");
    }

    /**
     * POST /push/send - Send push notification (admin only)
     * Body: { userId: int, title: string, body: string, data: object }
     */
    public function send_push($r) {
        $params = $r->get_json_params();
        $target_uid = (int) ($params['userId'] ?? 0);
        $title = sanitize_text_field($params['title'] ?? 'AsadMindset');
        $body = sanitize_text_field($params['body'] ?? '');
        $data = $params['data'] ?? [];

        if (!$target_uid || !$body) {
            return new WP_REST_Response(['message' => 'userId and body required'], 400);
        }

        $result = $this->send_to_user($target_uid, $title, $body, $data);
        return new WP_REST_Response($result, $result['success'] ? 200 : 500);
    }

    /**
     * Send push notification to a specific user
     */
    public function send_to_user($user_id, $title, $body, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'push_tokens';

        // Check if user is currently in this chat - skip push if so
        $chat_type = $data['type'] ?? '';
        $conv_id = $data['conversationId'] ?? '';
        if ($chat_type && $conv_id && $this->is_user_in_chat($user_id, $chat_type, $conv_id)) {
            return ['success' => true, 'skipped' => true, 'reason' => 'User is in chat'];
        }

        $tokens = $wpdb->get_col($wpdb->prepare(
            "SELECT token FROM $table WHERE user_id = %d AND is_active = 1",
            $user_id
        ));

        if (empty($tokens)) {
            return ['success' => false, 'message' => 'No active tokens'];
        }

        $results = [];
        foreach ($tokens as $token) {
            $result = $this->send_fcm($token, $title, $body, $data);
            $results[] = $result;

            // If token is invalid, deactivate it
            if (isset($result['error']) && in_array($result['error'], ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'])) {
                $wpdb->update($table, ['is_active' => 0], ['token' => $token]);
            }
        }

        return ['success' => true, 'results' => $results, 'tokens_count' => count($tokens)];
    }

    /**
     * Send via Firebase Cloud Messaging HTTP v1 API
     */
    private function send_fcm($token, $title, $body, $data = []) {
        $access_token = $this->get_fcm_access_token();
        if (!$access_token) {
            return ['error' => 'Failed to get access token'];
        }

        $project_id = 'asadmindset-4d01b';
        $url = "https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send";

        $message = [
            'message' => [
                'token' => $token,
                'data' => array_merge(
                    array_map('strval', $data),
                    [
                        'title' => $title,
                        'body'  => $body,
                        'icon'  => 'https://app.asadmindset.com/icon-192.png',
                    ]
                ),
            ]
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($message),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code === 200) {
            return ['success' => true, 'messageId' => $result['name'] ?? ''];
        }

        return [
            'error' => $result['error']['details'][0]['errorCode'] ?? $result['error']['status'] ?? 'UNKNOWN',
            'message' => $result['error']['message'] ?? ''
        ];
    }

    /**
     * Get FCM access token using service account key
     * 
     * IMPORTANT: You need to download a service account key from Firebase Console:
     * Project Settings → Service accounts → Generate new private key
     * Save as: wp-content/firebase-service-account.json
     */
    private function get_fcm_access_token() {
        $key_file = WP_CONTENT_DIR . '/firebase-service-account.json';
        
        if (!file_exists($key_file)) {
            error_log('Firebase service account key not found: ' . $key_file);
            return false;
        }

        $key_data = json_decode(file_get_contents($key_file), true);
        if (!$key_data) return false;

        // Check for cached token
        $cached = get_transient('fcm_access_token');
        if ($cached) return $cached;

        // Create JWT
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claim = base64_encode(json_encode([
            'iss'   => $key_data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $signature_input = str_replace(['+', '/', '='], ['-', '_', ''], $header) . '.' .
                           str_replace(['+', '/', '='], ['-', '_', ''], $claim);

        $private_key = openssl_pkey_get_private($key_data['private_key']);
        if (!$private_key) return false;

        openssl_sign($signature_input, $signature, $private_key, 'sha256');
        $jwt = $signature_input . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) return false;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) return false;

        // Cache for 50 minutes
        set_transient('fcm_access_token', $data['access_token'], 3000);

        return $data['access_token'];
    }
}

new AsadMindset_Push_Notifications();