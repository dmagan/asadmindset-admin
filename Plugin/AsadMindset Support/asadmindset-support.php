<?php
/**
 * Plugin Name: Asad Mindset Support Chat
 * Description: Real-time support chat system with Pusher WebSocket
 * Version: 1.0.0
 * Author: Asad Mindset
 */

if (!defined('ABSPATH')) {
    exit;
}

// Pusher Configuration
define('PUSHER_APP_ID', '2097906');
define('PUSHER_KEY', '71815fd9e2b90f89a57b');
define('PUSHER_SECRET', 'c0ef9348420d4a83ed24');
define('PUSHER_CLUSTER', 'eu');

// Include Pusher PHP SDK (we'll use WordPress HTTP API instead for simplicity)
class AsadMindset_Support {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Create tables on activation
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_routes'));
        
        // CORS headers
        add_action('rest_api_init', array($this, 'add_cors_headers'), 15);
        
        // Allow audio file uploads
        add_filter('upload_mimes', array($this, 'allow_audio_uploads'), 99);
        add_filter('wp_check_filetype_and_ext', array($this, 'fix_audio_mime_types'), 99, 5);
        
        // Disable real MIME check for audio files
        add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $audio_types = array(
                'webm' => 'audio/webm',
                'mp4' => 'audio/mp4',
                'm4a' => 'audio/x-m4a',
                'ogg' => 'audio/ogg',
                'oga' => 'audio/ogg',
                'wav' => 'audio/wav',
                'mp3' => 'audio/mpeg'
            );
            
            if (isset($audio_types[$ext])) {
                return array(
                    'ext' => $ext,
                    'type' => $audio_types[$ext],
                    'proper_filename' => $filename
                );
            }
            return $data;
        }, 100, 4);
    }
    
    /**
     * Allow audio file uploads
     */
    public function allow_audio_uploads($mimes) {
        $mimes['webm'] = 'audio/webm';
        $mimes['ogg'] = 'audio/ogg';
        $mimes['oga'] = 'audio/ogg';
        $mimes['mp4'] = 'audio/mp4';
        $mimes['m4a'] = 'audio/x-m4a';
        $mimes['wav'] = 'audio/wav';
        $mimes['mp3'] = 'audio/mpeg';
        return $mimes;
    }
    
    /**
     * Fix audio mime type detection
     */
    public function fix_audio_mime_types($data, $file, $filename, $mimes, $real_mime = null) {
        if (!empty($data['ext']) && !empty($data['type'])) {
            return $data;
        }
        
        $filetype = wp_check_filetype($filename, $mimes);
        $ext = $filetype['ext'];
        $type = $filetype['type'];
        
        // Check for audio files by extension
        $audio_extensions = array('webm', 'ogg', 'mp4', 'm4a');
        $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($file_ext, $audio_extensions)) {
            $audio_mimes = array(
                'webm' => 'audio/webm',
                'ogg' => 'audio/ogg',
                'mp4' => 'audio/mp4',
                'm4a' => 'audio/mp4'
            );
            $data['ext'] = $file_ext;
            $data['type'] = $audio_mimes[$file_ext];
        }
        
        return $data;
    }
    
    /**
     * Add CORS headers
     */
    public function add_cors_headers() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function($value) {
            $origin = get_http_origin();
            $allowed_origins = array(
                'https://app.asadmindset.com',
                'https://admin.asadmindset.com',
                'https://dashboard.asadmindset.com',
                'http://localhost:3000',
                'http://localhost:3001'
            );
            
            if ($origin && in_array($origin, $allowed_origins)) {
                header('Access-Control-Allow-Origin: ' . $origin);
            } else {
                header('Access-Control-Allow-Origin: *');
            }
            
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
            
            return $value;
        });
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Conversations table
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $sql_conversations = "CREATE TABLE $table_conversations (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            status varchar(20) DEFAULT 'open',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        
        // Messages table
        $table_messages = $wpdb->prefix . 'support_messages';
        $sql_messages = "CREATE TABLE $table_messages (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            sender_type varchar(10) NOT NULL,
            sender_id bigint(20) NOT NULL,
            message_type varchar(20) DEFAULT 'text',
            content text,
            media_url varchar(500),
            duration int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'sent',
            is_read tinyint(1) DEFAULT 0,
            is_edited tinyint(1) DEFAULT 0,
            reply_to_id bigint(20) DEFAULT NULL,
            delivered_at datetime DEFAULT NULL,
            read_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY sender_type (sender_type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_conversations);
        dbDelta($sql_messages);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'asadmindset/v1';
        
        // === User Routes ===
        
        // Get or create conversation
        register_rest_route($namespace, '/conversation', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_user_conversation'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Send message (user)
        register_rest_route($namespace, '/conversation/message', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_user_message'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Edit message (user)
        register_rest_route($namespace, '/messages/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'edit_user_message'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Delete message (user)
        register_rest_route($namespace, '/messages/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_user_message'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Upload media
        register_rest_route($namespace, '/upload', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_media'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Mark messages as read (user marks admin messages as read)
        register_rest_route($namespace, '/conversation/read', array(
            'methods' => 'POST',
            'callback' => array($this, 'mark_messages_read_user'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // === Admin Routes ===
        
        // Admin login
        register_rest_route($namespace, '/admin/login', array(
            'methods' => 'POST',
            'callback' => array($this, 'admin_login'),
            'permission_callback' => '__return_true'
        ));
        
        // Get all conversations (admin)
        register_rest_route($namespace, '/admin/conversations', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_all_conversations'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Get conversation messages (admin)
        register_rest_route($namespace, '/admin/conversations/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_conversation_messages'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Mark messages as read (admin marks user messages as read)
        register_rest_route($namespace, '/admin/conversations/(?P<id>\d+)/read', array(
            'methods' => 'POST',
            'callback' => array($this, 'mark_messages_read_admin'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Send message (admin)
        register_rest_route($namespace, '/admin/conversations/(?P<id>\d+)/message', array(
            'methods' => 'POST',
            'callback' => array($this, 'send_admin_message'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Edit message (admin)
        register_rest_route($namespace, '/admin/messages/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'edit_message'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Delete message (admin)
        register_rest_route($namespace, '/admin/messages/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_message'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Admin upload media
        register_rest_route($namespace, '/admin/upload', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_media'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Update conversation status (admin)
        register_rest_route($namespace, '/admin/conversations/(?P<id>\d+)/status', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_conversation_status'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Get dashboard stats (admin)
        register_rest_route($namespace, '/admin/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_admin_stats'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Get Pusher config (for client)
        register_rest_route($namespace, '/pusher-config', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_pusher_config'),
            'permission_callback' => '__return_true'
        ));
        
        // Test endpoint (temporary)
        register_rest_route($namespace, '/test-upload', array(
            'methods' => 'GET',
            'callback' => function() {
                return rest_ensure_response(array(
                    'message' => 'Upload route is working!',
                    'version' => '2.0.0',
                    'time' => current_time('mysql')
                ));
            },
            'permission_callback' => '__return_true'
        ));
        
        // Debug upload test (temporary)
        register_rest_route($namespace, '/debug-upload', array(
            'methods' => 'POST',
            'callback' => function($request) {
                $files = $request->get_file_params();
                
                if (empty($files['file'])) {
                    return rest_ensure_response(array(
                        'error' => 'No file received',
                        'files' => $files,
                        'post' => $_POST,
                        'server' => array(
                            'content_type' => isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : 'not set',
                            'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 'not set'
                        )
                    ));
                }
                
                $file = $files['file'];
                
                return rest_ensure_response(array(
                    'success' => true,
                    'file_info' => array(
                        'name' => $file['name'],
                        'type' => $file['type'],
                        'size' => $file['size'],
                        'tmp_name' => $file['tmp_name'],
                        'error' => $file['error']
                    ),
                    'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'),
                    'max_file_uploads' => ini_get('max_file_uploads')
                ));
            },
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Check user authentication
     */
    public function check_user_auth($request) {
        $token = $this->get_bearer_token($request);
        if (!$token) {
            return false;
        }
        
        // Validate JWT token
        $user_id = $this->validate_jwt_token($token);
        return $user_id !== false;
    }
    
    /**
     * Check admin authentication
     */
    public function check_admin_auth($request) {
        $token = $this->get_bearer_token($request);
        if (!$token) {
            return false;
        }
        
        $user_id = $this->validate_jwt_token($token);
        if (!$user_id) {
            return false;
        }
        
        // Check if user is admin
        $user = get_user_by('id', $user_id);
        return $user && user_can($user, 'manage_options');
    }
    
    /**
     * Get bearer token from request
     */
    private function get_bearer_token($request) {
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && preg_match('/Bearer\s(\S+)/', $auth_header, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Validate JWT token and return user ID
     */
    private function validate_jwt_token($token) {
        // Use JWT Auth plugin's validation
        $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
        if (!$secret_key) {
            return false;
        }
        
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }
            
            $payload = json_decode(base64_decode($parts[1]), true);
            if (!$payload || !isset($payload['data']['user']['id'])) {
                return false;
            }
            
            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return false;
            }
            
            return $payload['data']['user']['id'];
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get current user ID from token
     */
    private function get_user_id_from_request($request) {
        $token = $this->get_bearer_token($request);
        return $this->validate_jwt_token($token);
    }
    
    /**
     * Get Pusher configuration
     */
    public function get_pusher_config($request) {
        return rest_ensure_response(array(
            'key' => PUSHER_KEY,
            'cluster' => PUSHER_CLUSTER
        ));
    }
    
    /**
     * Trigger Pusher event
     */
    private function trigger_pusher_event($channel, $event, $data) {
        $url = 'https://api-' . PUSHER_CLUSTER . '.pusher.com/apps/' . PUSHER_APP_ID . '/events';
        
        $body = json_encode(array(
            'name' => $event,
            'channel' => $channel,
            'data' => json_encode($data)
        ));
        
        $timestamp = time();
        $auth_signature = $this->generate_pusher_signature('POST', '/apps/' . PUSHER_APP_ID . '/events', $body, $timestamp);
        
        $response = wp_remote_post($url . '?' . http_build_query(array(
            'auth_key' => PUSHER_KEY,
            'auth_timestamp' => $timestamp,
            'auth_version' => '1.0',
            'body_md5' => md5($body),
            'auth_signature' => $auth_signature
        )), array(
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => $body,
            'timeout' => 10
        ));
        
        return !is_wp_error($response);
    }
    
    /**
     * Generate Pusher auth signature
     */
    private function generate_pusher_signature($method, $path, $body, $timestamp) {
        $params = array(
            'auth_key' => PUSHER_KEY,
            'auth_timestamp' => $timestamp,
            'auth_version' => '1.0',
            'body_md5' => md5($body)
        );
        
        ksort($params);
        $query_string = http_build_query($params);
        
        $sign_data = implode("\n", array($method, $path, $query_string));
        
        return hash_hmac('sha256', $sign_data, PUSHER_SECRET);
    }
    
    // ==========================================
    // USER ENDPOINTS
    // ==========================================
    
    /**
     * Get or create user conversation
     */
    public function get_user_conversation($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get existing conversation or create new
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        if (!$conversation) {
            // Create new conversation
            $wpdb->insert($table_conversations, array(
                'user_id' => $user_id,
                'status' => 'open'
            ));
            
            $conversation_id = $wpdb->insert_id;
            
            // Add welcome message
            $wpdb->insert($table_messages, array(
                'conversation_id' => $conversation_id,
                'sender_type' => 'admin',
                'sender_id' => 0,
                'message_type' => 'text',
                'content' => 'سلام! به پشتیبانی خوش آمدید 👋 چطور می‌تونم کمکتون کنم؟'
            ));
            
            $conversation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_conversations WHERE id = %d",
                $conversation_id
            ));
        }
        
        // Get messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conversation->id
        ));
        
        // Format messages
        $formatted_messages = array_map(function($msg) use ($wpdb, $table_messages) {
    $result = array(
        'id' => (int) $msg->id,
        'type' => $msg->message_type,
        'content' => $msg->content,
        'mediaUrl' => $msg->media_url,
        'duration' => (int) $msg->duration,
        'sender' => $msg->sender_type,
        'isEdited' => (bool) $msg->is_edited,
        'status' => $msg->status ?: 'sent',
        'createdAt' => $msg->created_at,
        'replyTo' => null
    );
    
    if (!empty($msg->reply_to_id)) {
        $reply_msg = $wpdb->get_row($wpdb->prepare(
            "SELECT id, message_type, content, sender_type FROM $table_messages WHERE id = %d",
            $msg->reply_to_id
        ));
        if ($reply_msg) {
            $result['replyTo'] = array(
                'id' => (int) $reply_msg->id,
                'type' => $reply_msg->message_type,
                'content' => $reply_msg->content,
                'sender' => $reply_msg->sender_type
            );
        }
    }
    
    return $result;
}, $messages);
        
        return rest_ensure_response(array(
            'conversationId' => (int) $conversation->id,
            'status' => $conversation->status,
            'messages' => $formatted_messages,
            'pusherChannel' => 'conversation-' . $conversation->id
        ));
    }
    
    /**
     * Send user message
     */
    public function send_user_message($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $params = $request->get_json_params();
        
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get user's conversation
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        if (!$conversation) {
            return new WP_Error('no_conversation', 'No conversation found', array('status' => 404));
        }
        
        // Insert message
        $message_data = array(
    'conversation_id' => $conversation->id,
    'sender_type' => 'user',
    'sender_id' => $user_id,
    'message_type' => isset($params['type']) ? $params['type'] : 'text',
    'content' => isset($params['content']) ? $params['content'] : '',
    'media_url' => isset($params['mediaUrl']) ? $params['mediaUrl'] : null,
    'duration' => isset($params['duration']) ? (int) $params['duration'] : 0,
    'reply_to_id' => isset($params['replyToId']) ? (int) $params['replyToId'] : null,
    'status' => 'sent'
);
        
        $wpdb->insert($table_messages, $message_data);
        $message_id = $wpdb->insert_id;
        
        // Update conversation timestamp
        $wpdb->update($table_conversations, 
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation->id)
        );
        
        // Get user info
        $user = get_user_by('id', $user_id);
        
        // Get replyTo data if exists
        $reply_to_data = null;
        if (!empty($params['replyToId'])) {
            $reply_msg = $wpdb->get_row($wpdb->prepare(
                "SELECT id, message_type, content, sender_type FROM $table_messages WHERE id = %d",
                $params['replyToId']
            ));
            if ($reply_msg) {
                $reply_to_data = array(
                    'id' => (int) $reply_msg->id,
                    'type' => $reply_msg->message_type,
                    'content' => $reply_msg->content,
                    'sender' => $reply_msg->sender_type
                );
            }
        }
        
        // Prepare message for Pusher
        $pusher_message = array(
            'id' => $message_id,
            'type' => $message_data['message_type'],
            'content' => $message_data['content'],
            'mediaUrl' => $message_data['media_url'],
            'duration' => $message_data['duration'],
            'sender' => 'user',
            'senderName' => $user ? $user->display_name : 'User',
            'isEdited' => false,
            'status' => 'sent',
            'createdAt' => current_time('mysql'),
            'conversationId' => $conversation->id,
            'replyTo' => $reply_to_data
        );
        
        // Trigger Pusher event - to conversation channel (for user)
        $this->trigger_pusher_event('conversation-' . $conversation->id, 'new-message', $pusher_message);
        
        // Trigger Pusher event - to admin channel (marks as delivered when admin receives)
        $this->trigger_pusher_event('admin-support', 'new-message', $pusher_message);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => $pusher_message
        ));
    }
    
    /**
     * Edit user message
     */
    public function edit_user_message($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $message_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get message and verify ownership
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE id = %d AND sender_id = %d AND sender_type = 'user'",
            $message_id,
            $user_id
        ));
        
        if (!$message) {
            return new WP_Error('not_found', 'Message not found or access denied', array('status' => 404));
        }
        
        // Update message
        $wpdb->update($table_messages,
            array(
                'content' => $params['content'],
                'is_edited' => 1
            ),
            array('id' => $message_id)
        );
        
        // Trigger Pusher event
        $this->trigger_pusher_event('conversation-' . $message->conversation_id, 'message-edited', array(
            'id' => $message_id,
            'content' => $params['content'],
            'isEdited' => true
        ));
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Delete user message
     */
    public function delete_user_message($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $message_id = (int) $request->get_param('id');
        
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get message and verify ownership
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE id = %d AND sender_id = %d AND sender_type = 'user'",
            $message_id,
            $user_id
        ));
        
        if (!$message) {
            return new WP_Error('not_found', 'Message not found or access denied', array('status' => 404));
        }
        
        // Delete message
        $wpdb->delete($table_messages, array('id' => $message_id));
        
        // Trigger Pusher event
        $this->trigger_pusher_event('conversation-' . $message->conversation_id, 'message-deleted', array(
            'id' => $message_id
        ));
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Upload media file
     */
    public function upload_media($request) {
        $files = $request->get_file_params();
        
        if (empty($files['file'])) {
            return new WP_Error('no_file', 'No file uploaded', array('status' => 400));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $file = $files['file'];
        
        // Log file info for debugging
        error_log('Upload attempt - Type: ' . $file['type'] . ', Name: ' . $file['name'] . ', Size: ' . $file['size']);
        
        // Get extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Define allowed types
        $audio_mimes = array(
            'webm' => 'audio/webm',
            'mp4' => 'audio/mp4',
            'm4a' => 'audio/x-m4a',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg'
        );
        
        $image_mimes = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        );
        
        $all_mimes = array_merge($audio_mimes, $image_mimes);
        
        // Check if extension is allowed
        if (!isset($all_mimes[$ext])) {
            error_log('Upload rejected - Extension not allowed: ' . $ext);
            return new WP_Error('invalid_type', 'Invalid file type: ' . $ext, array('status' => 400));
        }
        
        // Force the correct MIME type based on extension
        $correct_mime = $all_mimes[$ext];
        
        // Override WordPress MIME check completely for this upload
        add_filter('wp_check_filetype_and_ext', function($data) use ($ext, $correct_mime, $file) {
            return array(
                'ext' => $ext,
                'type' => $correct_mime,
                'proper_filename' => $file['name']
            );
        }, 999, 4);
        
        // Add our mimes
        add_filter('upload_mimes', function($mimes) use ($all_mimes) {
            return array_merge($mimes, $all_mimes);
        }, 999);
        
        // Upload file
        $upload = wp_handle_upload($file, array(
            'test_form' => false,
            'mimes' => $all_mimes
        ));
        
        if (isset($upload['error'])) {
            error_log('Upload error: ' . $upload['error']);
            return new WP_Error('upload_error', $upload['error'], array('status' => 500));
        }
        
        error_log('Upload success: ' . $upload['url']);
        
        return rest_ensure_response(array(
            'success' => true,
            'url' => $upload['url'],
            'type' => $correct_mime
        ));
    }
    
    // ==========================================
    // ADMIN ENDPOINTS
    // ==========================================
    
    /**
     * Admin login
     */
    public function admin_login($request) {
        $params = $request->get_json_params();
        
        $username = isset($params['username']) ? $params['username'] : '';
        $password = isset($params['password']) ? $params['password'] : '';
        
        $user = wp_authenticate($username, $password);
        
        if (is_wp_error($user)) {
            return new WP_Error('invalid_credentials', 'Invalid username or password', array('status' => 401));
        }
        
        // Check if user is admin
        if (!user_can($user, 'manage_options')) {
            return new WP_Error('not_admin', 'Access denied', array('status' => 403));
        }
        
        // Generate JWT token (using JWT Auth plugin)
        $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : 'your-secret-key';
        
        $issuedAt = time();
        $expire = $issuedAt + (DAY_IN_SECONDS * 7);
        
        $payload = array(
            'iss' => get_bloginfo('url'),
            'iat' => $issuedAt,
            'exp' => $expire,
            'data' => array(
                'user' => array(
                    'id' => $user->ID
                )
            )
        );
        
        // Simple JWT encoding (for production, use a proper JWT library)
        $header = base64_encode(json_encode(array('typ' => 'JWT', 'alg' => 'HS256')));
        $payload_encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', "$header.$payload_encoded", $secret_key);
        $token = "$header.$payload_encoded." . base64_encode($signature);
        
        return rest_ensure_response(array(
            'success' => true,
            'token' => $token,
            'user' => array(
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email
            )
        ));
    }
    
    /**
     * Get all conversations for admin
     */
    public function get_all_conversations($request) {
        global $wpdb;
        
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        $status = $request->get_param('status');
        
        $where = '';
        if ($status && $status !== 'all') {
            $where = $wpdb->prepare(' WHERE c.status = %s', $status);
        }
        
        $conversations = $wpdb->get_results("
            SELECT c.*, 
                   u.display_name as user_name,
                   u.user_email,
                   (SELECT COUNT(*) FROM $table_messages WHERE conversation_id = c.id AND is_read = 0 AND sender_type = 'user') as unread_count,
                   (SELECT content FROM $table_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                   (SELECT created_at FROM $table_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_at
            FROM $table_conversations c
            LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
            $where
            ORDER BY c.updated_at DESC
        ");
        
        $formatted = array_map(function($conv) {
            return array(
                'id' => (int) $conv->id,
                'userId' => (int) $conv->user_id,
                'userName' => $conv->user_name ?: 'Unknown User',
                'userEmail' => $conv->user_email,
                'status' => $conv->status,
                'unreadCount' => (int) $conv->unread_count,
                'lastMessage' => $conv->last_message,
                'lastMessageAt' => $conv->last_message_at,
                'createdAt' => $conv->created_at,
                'updatedAt' => $conv->updated_at
            );
        }, $conversations);
        
        return rest_ensure_response($formatted);
    }
    
    /**
     * Get conversation messages for admin
     */
    public function get_conversation_messages($request) {
        global $wpdb;
        
        $conversation_id = (int) $request->get_param('id');
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get conversation
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, u.display_name as user_name, u.user_email 
             FROM $table_conversations c
             LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
             WHERE c.id = %d",
            $conversation_id
        ));
        
        if (!$conversation) {
            return new WP_Error('not_found', 'Conversation not found', array('status' => 404));
        }
        
        // Get messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE conversation_id = %d ORDER BY created_at ASC",
            $conversation_id
        ));
        
        // Mark user messages as read
        $wpdb->update($table_messages,
            array('is_read' => 1),
            array('conversation_id' => $conversation_id, 'sender_type' => 'user')
        );
        
        $formatted_messages = array_map(function($msg) use ($wpdb, $table_messages) {
            $result = array(
                'id' => (int) $msg->id,
                'type' => $msg->message_type,
                'content' => $msg->content,
                'mediaUrl' => $msg->media_url,
                'duration' => (int) $msg->duration,
                'sender' => $msg->sender_type,
                'isEdited' => (bool) $msg->is_edited,
                'isRead' => (bool) $msg->is_read,
                'status' => $msg->status ?: 'sent',
                'createdAt' => $msg->created_at,
                'replyTo' => null
            );
            
            // Get replyTo data if exists
            if (!empty($msg->reply_to_id)) {
                $reply_msg = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, message_type, content, sender_type FROM $table_messages WHERE id = %d",
                    $msg->reply_to_id
                ));
                if ($reply_msg) {
                    $result['replyTo'] = array(
                        'id' => (int) $reply_msg->id,
                        'type' => $reply_msg->message_type,
                        'content' => $reply_msg->content,
                        'sender' => $reply_msg->sender_type
                    );
                }
            }
            
            return $result;
        }, $messages);
        
        return rest_ensure_response(array(
            'conversation' => array(
                'id' => (int) $conversation->id,
                'userId' => (int) $conversation->user_id,
                'userName' => $conversation->user_name ?: 'Unknown User',
                'userEmail' => $conversation->user_email,
                'status' => $conversation->status
            ),
            'messages' => $formatted_messages,
            'pusherChannel' => 'conversation-' . $conversation_id
        ));
    }
    
    /**
     * Send admin message
     */
    public function send_admin_message($request) {
        global $wpdb;
        
        $conversation_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $admin_id = $this->get_user_id_from_request($request);
        
        $table_messages = $wpdb->prefix . 'support_messages';
        $table_conversations = $wpdb->prefix . 'support_conversations';
        
        // Insert message
        $message_data = array(
            'conversation_id' => $conversation_id,
            'sender_type' => 'admin',
            'sender_id' => $admin_id,
            'message_type' => isset($params['type']) ? $params['type'] : 'text',
            'content' => isset($params['content']) ? $params['content'] : '',
            'media_url' => isset($params['mediaUrl']) ? $params['mediaUrl'] : null,
            'duration' => isset($params['duration']) ? (int) $params['duration'] : 0,
            'reply_to_id' => isset($params['replyToId']) ? (int) $params['replyToId'] : null,
            'status' => 'sent'
        );
        
        $wpdb->insert($table_messages, $message_data);
        $message_id = $wpdb->insert_id;
        
        // Update conversation timestamp
        $wpdb->update($table_conversations,
            array('updated_at' => current_time('mysql')),
            array('id' => $conversation_id)
        );
        
        // Get replyTo data if exists
        $reply_to_data = null;
        if (!empty($params['replyToId'])) {
            $reply_msg = $wpdb->get_row($wpdb->prepare(
                "SELECT id, message_type, content, sender_type FROM $table_messages WHERE id = %d",
                $params['replyToId']
            ));
            if ($reply_msg) {
                $reply_to_data = array(
                    'id' => (int) $reply_msg->id,
                    'type' => $reply_msg->message_type,
                    'content' => $reply_msg->content,
                    'sender' => $reply_msg->sender_type
                );
            }
        }
        
        // Prepare message for Pusher
        $pusher_message = array(
            'id' => $message_id,
            'type' => $message_data['message_type'],
            'content' => $message_data['content'],
            'mediaUrl' => $message_data['media_url'],
            'duration' => $message_data['duration'],
            'sender' => 'admin',
            'senderName' => 'پشتیبانی',
            'isEdited' => false,
            'status' => 'sent',
            'createdAt' => current_time('mysql'),
            'conversationId' => $conversation_id,
            'replyTo' => $reply_to_data
        );
        
        // Trigger Pusher event
        $this->trigger_pusher_event('conversation-' . $conversation_id, 'new-message', $pusher_message);
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => $pusher_message
        ));
    }
    
    /**
     * Edit message
     */
    public function edit_message($request) {
        global $wpdb;
        
        $message_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get message
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE id = %d",
            $message_id
        ));
        
        if (!$message) {
            return new WP_Error('not_found', 'Message not found', array('status' => 404));
        }
        
        // Update message
        $wpdb->update($table_messages,
            array(
                'content' => $params['content'],
                'is_edited' => 1
            ),
            array('id' => $message_id)
        );
        
        // Trigger Pusher event
        $this->trigger_pusher_event('conversation-' . $message->conversation_id, 'message-edited', array(
            'id' => $message_id,
            'content' => $params['content'],
            'isEdited' => true
        ));
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Delete message
     */
    public function delete_message($request) {
        global $wpdb;
        
        $message_id = (int) $request->get_param('id');
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get message for conversation_id
        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_messages WHERE id = %d",
            $message_id
        ));
        
        if (!$message) {
            return new WP_Error('not_found', 'Message not found', array('status' => 404));
        }
        
        // Delete message
        $wpdb->delete($table_messages, array('id' => $message_id));
        
        // Trigger Pusher event
        $this->trigger_pusher_event('conversation-' . $message->conversation_id, 'message-deleted', array(
            'id' => $message_id
        ));
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Mark messages as read (user reading admin messages)
     */
    public function mark_messages_read_user($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get user's conversation
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_conversations WHERE user_id = %d",
            $user_id
        ));
        
        if (!$conversation) {
            return new WP_Error('not_found', 'Conversation not found', array('status' => 404));
        }
        
        // Get unread admin message IDs
        $unread_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table_messages WHERE conversation_id = %d AND sender_type = 'admin' AND status != 'read'",
            $conversation->id
        ));
        
        if (!empty($unread_ids)) {
            // Update messages to read
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_messages SET status = 'read', read_at = %s WHERE conversation_id = %d AND sender_type = 'admin' AND status != 'read'",
                current_time('mysql'),
                $conversation->id
            ));
            
            // Trigger Pusher event for each message
            $this->trigger_pusher_event('conversation-' . $conversation->id, 'messages-read', array(
                'messageIds' => array_map('intval', $unread_ids),
                'readBy' => 'user'
            ));
            
            // Also notify admin channel
            $this->trigger_pusher_event('admin-support', 'messages-read', array(
                'conversationId' => $conversation->id,
                'messageIds' => array_map('intval', $unread_ids),
                'readBy' => 'user'
            ));
        }
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Mark messages as read (admin reading user messages)
     */
    public function mark_messages_read_admin($request) {
        global $wpdb;
        
        $conversation_id = (int) $request->get_param('id');
        $table_messages = $wpdb->prefix . 'support_messages';
        
        // Get unread user message IDs
        $unread_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $table_messages WHERE conversation_id = %d AND sender_type = 'user' AND status != 'read'",
            $conversation_id
        ));
        
        if (!empty($unread_ids)) {
            // Update messages to read
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_messages SET status = 'read', read_at = %s, is_read = 1 WHERE conversation_id = %d AND sender_type = 'user' AND status != 'read'",
                current_time('mysql'),
                $conversation_id
            ));
            
            // Trigger Pusher event
            $this->trigger_pusher_event('conversation-' . $conversation_id, 'messages-read', array(
                'messageIds' => array_map('intval', $unread_ids),
                'readBy' => 'admin'
            ));
        }
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Update conversation status
     */
    public function update_conversation_status($request) {
        global $wpdb;
        
        $conversation_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        
        $table_conversations = $wpdb->prefix . 'support_conversations';
        
        $wpdb->update($table_conversations,
            array('status' => $params['status']),
            array('id' => $conversation_id)
        );
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Get admin dashboard stats
     */
    public function get_admin_stats($request) {
        global $wpdb;
        
        $table_conversations = $wpdb->prefix . 'support_conversations';
        $table_messages = $wpdb->prefix . 'support_messages';
        
        $total_conversations = $wpdb->get_var("SELECT COUNT(*) FROM $table_conversations");
        $open_conversations = $wpdb->get_var("SELECT COUNT(*) FROM $table_conversations WHERE status = 'open'");
        $total_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_messages");
        $unread_messages = $wpdb->get_var("SELECT COUNT(*) FROM $table_messages WHERE is_read = 0 AND sender_type = 'user'");
        
        // Today's stats
        $today = date('Y-m-d');
        $today_conversations = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_conversations WHERE DATE(created_at) = %s",
            $today
        ));
        $today_messages = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_messages WHERE DATE(created_at) = %s",
            $today
        ));
        
        return rest_ensure_response(array(
            'totalConversations' => (int) $total_conversations,
            'openConversations' => (int) $open_conversations,
            'totalMessages' => (int) $total_messages,
            'unreadMessages' => (int) $unread_messages,
            'todayConversations' => (int) $today_conversations,
            'todayMessages' => (int) $today_messages
        ));
    }
}

// Initialize plugin
AsadMindset_Support::get_instance();