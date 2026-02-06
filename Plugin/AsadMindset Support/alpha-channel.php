<?php
/**
 * Alpha Channel Module
 * کانال آلفا - پست‌گذاری و مشاهده مطالب
 */

if (!defined('ABSPATH')) {
    exit;
}

class Alpha_Channel {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Create channel tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Channel posts table
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $sql_posts = "CREATE TABLE $table_posts (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content text,
            media_type varchar(20) DEFAULT NULL,
            media_url varchar(500) DEFAULT NULL,
            media_duration int(11) DEFAULT 0,
            views_count int(11) DEFAULT 0,
            is_pinned tinyint(1) DEFAULT 0,
            pin_expires_at datetime DEFAULT NULL,
            is_edited tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            reply_to_id bigint(20) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY is_pinned (is_pinned),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Channel reactions table
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        $sql_reactions = "CREATE TABLE $table_reactions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            reaction_type varchar(20) DEFAULT 'like',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_user (post_id, user_id),
            KEY post_id (post_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // User notifications table
        $table_notifications = $wpdb->prefix . 'alpha_notifications';
        $sql_notifications = "CREATE TABLE $table_notifications (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            type varchar(50) NOT NULL,
            title varchar(255) NOT NULL,
            message text,
            data text,
            is_read tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY is_read (is_read),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_posts);
        dbDelta($sql_reactions);
        dbDelta($sql_notifications);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'asadmindset/v1';
        
        // === User Routes ===
        
        // Get channel posts (user)
        register_rest_route($namespace, '/channel/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_posts'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Get single post (user)
        register_rest_route($namespace, '/channel/posts/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_single_post'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Add reaction (user)
        register_rest_route($namespace, '/channel/posts/(?P<id>\d+)/react', array(
            'methods' => 'POST',
            'callback' => array($this, 'add_reaction'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Remove reaction (user)
        register_rest_route($namespace, '/channel/posts/(?P<id>\d+)/react', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'remove_reaction'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Get notifications (user)
        register_rest_route($namespace, '/notifications', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_notifications'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Mark notification as read (user)
        register_rest_route($namespace, '/notifications/(?P<id>\d+)/read', array(
            'methods' => 'POST',
            'callback' => array($this, 'mark_notification_read'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Mark all notifications as read (user)
        register_rest_route($namespace, '/notifications/read-all', array(
            'methods' => 'POST',
            'callback' => array($this, 'mark_all_notifications_read'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Get unread notification count (user)
        register_rest_route($namespace, '/notifications/unread-count', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_unread_count'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // === Admin Routes ===
        
        // Get all posts (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/posts', array(
            'methods' => 'GET',
            'callback' => array($this, 'admin_get_posts'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
        
        // Create post (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
        
        // Update post (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/posts/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'update_post'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
        
        // Delete post (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/posts/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_post'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
        
        // Pin/Unpin post (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/posts/(?P<id>\d+)/pin', array(
            'methods' => 'POST',
            'callback' => array($this, 'toggle_pin'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
        
        // Get channel stats (admin/channel agent)
        register_rest_route($namespace, '/admin/channel/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_channel_stats'),
            'permission_callback' => array($this, 'check_admin_auth_channel')
        ));
    }
    
    /**
     * Check user authentication
     */
    public function check_user_auth($request) {
        $support = AsadMindset_Support::get_instance();
        return $support->check_user_auth($request);
    }
    
    /**
     * Check admin authentication
     */
    public function check_admin_auth($request) {
        $support = AsadMindset_Support::get_instance();
        return $support->check_admin_auth($request);
    }
    
    /**
     * Check admin auth with channel permission
     */
    public function check_admin_auth_channel($request) {
        $support = AsadMindset_Support::get_instance();
        return $support->check_admin_auth_channel($request);
    }
    
    /**
     * Get user ID from request
     */
    private function get_user_id_from_request($request) {
        $support = AsadMindset_Support::get_instance();
        return $support->get_user_id_from_request($request);
    }
    
    /**
     * Trigger Pusher event
     */
    private function trigger_pusher_event($channel, $event, $data) {
        try {
            $support = AsadMindset_Support::get_instance();
            if (method_exists($support, 'trigger_pusher_event')) {
                $result = $support->trigger_pusher_event($channel, $event, $data);
                error_log('Alpha Channel Pusher: ' . $channel . ' / ' . $event . ' - Result: ' . ($result ? 'success' : 'failed'));
                return $result;
            } else {
                error_log('Alpha Channel Pusher: trigger_pusher_event method not found');
            }
        } catch (Exception $e) {
            error_log('Alpha Channel Pusher Error: ' . $e->getMessage());
        }
        return false;
    }
    
    // ==========================================
    // User Methods
    // ==========================================
    
    /**
     * Get channel posts (for users)
     */
    public function get_posts($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $page = (int) $request->get_param('page') ?: 1;
        $per_page = (int) $request->get_param('per_page') ?: 20;
        $offset = ($page - 1) * $per_page;
        
       $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        // Auto-unpin expired posts
        $wpdb->query(
            "UPDATE $table_posts SET is_pinned = 0, pin_expires_at = NULL 
             WHERE is_pinned = 1 AND pin_expires_at IS NOT NULL AND pin_expires_at < NOW()"
        );
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_posts");
        
        // Get posts (pinned first, then by date)
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, 
                    (SELECT COUNT(*) FROM $table_reactions WHERE post_id = p.id) as reactions_count,
                    (SELECT COUNT(*) FROM $table_reactions WHERE post_id = p.id AND user_id = %d) as user_reacted
             FROM $table_posts p 
             ORDER BY p.is_pinned DESC, p.created_at DESC 
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));
        
        // Increment view count for each post
        foreach ($posts as $post) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_posts SET views_count = views_count + 1 WHERE id = %d",
                $post->id
            ));
        }
        
        $formatted_posts = array_map(function($post) {
    return array(
        'id' => (int) $post->id,
        'content' => $post->content,
        'mediaType' => $post->media_type,
        'mediaUrl' => $post->media_url,
        'mediaDuration' => (int) $post->media_duration,
        'viewsCount' => (int) $post->views_count + 1,
        'reactionsCount' => (int) $post->reactions_count,
        'userReacted' => (bool) $post->user_reacted,
        'isPinned' => (bool) $post->is_pinned,
        'isEdited' => (bool) $post->is_edited,
        'createdAt' => $post->created_at,
        'updatedAt' => $post->updated_at,
        'replyToId' => $post->reply_to_id ? (int) $post->reply_to_id : null
    );
}, $posts);
        
        return rest_ensure_response(array(
            'success' => true,
            'posts' => $formatted_posts,
            'pagination' => array(
                'page' => $page,
                'perPage' => $per_page,
                'total' => (int) $total,
                'totalPages' => ceil($total / $per_page)
            )
        ));
    }
    
    /**
     * Get single post
     */
    public function get_single_post($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        $user_id = $this->get_user_id_from_request($request);
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, 
                    (SELECT COUNT(*) FROM $table_reactions WHERE post_id = p.id) as reactions_count,
                    (SELECT COUNT(*) FROM $table_reactions WHERE post_id = p.id AND user_id = %d) as user_reacted
             FROM $table_posts p 
             WHERE p.id = %d",
            $user_id,
            $post_id
        ));
        
        if (!$post) {
            return new WP_Error('not_found', 'Post not found', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'post' => array(
                'id' => (int) $post->id,
                'content' => $post->content,
                'mediaType' => $post->media_type,
                'mediaUrl' => $post->media_url,
                'mediaDuration' => (int) $post->media_duration,
                'viewsCount' => (int) $post->views_count,
                'reactionsCount' => (int) $post->reactions_count,
                'userReacted' => (bool) $post->user_reacted,
                'isPinned' => (bool) $post->is_pinned,
                'isEdited' => (bool) $post->is_edited,
                'createdAt' => $post->created_at,
                'updatedAt' => $post->updated_at,
                'replyToId' => $post->reply_to_id ? (int) $post->reply_to_id : null
            )
        ));
    }
    
    /**
     * Add reaction to post
     */
    public function add_reaction($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        $user_id = $this->get_user_id_from_request($request);
        $params = $request->get_json_params();
        $reaction_type = isset($params['type']) ? $params['type'] : 'like';
        
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        // Check if already reacted
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_reactions WHERE post_id = %d AND user_id = %d",
            $post_id,
            $user_id
        ));
        
        if ($existing) {
            // Update reaction type
            $wpdb->update($table_reactions,
                array('reaction_type' => $reaction_type),
                array('id' => $existing->id)
            );
        } else {
            // Insert new reaction
            $wpdb->insert($table_reactions, array(
                'post_id' => $post_id,
                'user_id' => $user_id,
                'reaction_type' => $reaction_type
            ));
        }
        
        // Get updated count
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_reactions WHERE post_id = %d",
            $post_id
        ));
        
        // Trigger Pusher event
        $this->trigger_pusher_event('alpha-channel', 'reaction-updated', array(
            'postId' => $post_id,
            'reactionsCount' => (int) $count
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'reactionsCount' => (int) $count
        ));
    }
    
    /**
     * Remove reaction from post
     */
    public function remove_reaction($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        $user_id = $this->get_user_id_from_request($request);
        
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        $wpdb->delete($table_reactions, array(
            'post_id' => $post_id,
            'user_id' => $user_id
        ));
        
        // Get updated count
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_reactions WHERE post_id = %d",
            $post_id
        ));
        
        // Trigger Pusher event
        $this->trigger_pusher_event('alpha-channel', 'reaction-updated', array(
            'postId' => $post_id,
            'reactionsCount' => (int) $count
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'reactionsCount' => (int) $count
        ));
    }
    
    /**
     * Get user notifications
     */
    public function get_notifications($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $page = (int) $request->get_param('page') ?: 1;
        $per_page = (int) $request->get_param('per_page') ?: 20;
        $offset = ($page - 1) * $per_page;
        
        $table = $wpdb->prefix . 'alpha_notifications';
        
        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ));
        
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));
        
        $formatted = array_map(function($n) {
            return array(
                'id' => (int) $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'data' => json_decode($n->data),
                'isRead' => (bool) $n->is_read,
                'createdAt' => $n->created_at
            );
        }, $notifications);
        
        return rest_ensure_response(array(
            'success' => true,
            'notifications' => $formatted,
            'pagination' => array(
                'page' => $page,
                'perPage' => $per_page,
                'total' => (int) $total,
                'totalPages' => ceil($total / $per_page)
            )
        ));
    }
    
    /**
     * Mark notification as read
     */
    public function mark_notification_read($request) {
        global $wpdb;
        
        $notification_id = (int) $request->get_param('id');
        $user_id = $this->get_user_id_from_request($request);
        
        $table = $wpdb->prefix . 'alpha_notifications';
        
        $wpdb->update($table,
            array('is_read' => 1),
            array('id' => $notification_id, 'user_id' => $user_id)
        );
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Mark all notifications as read
     */
    public function mark_all_notifications_read($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        
        $table = $wpdb->prefix . 'alpha_notifications';
        
        $wpdb->update($table,
            array('is_read' => 1),
            array('user_id' => $user_id, 'is_read' => 0)
        );
        
        return rest_ensure_response(array('success' => true));
    }
    
    /**
     * Get unread notification count
     */
    public function get_unread_count($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        
        $table = $wpdb->prefix . 'alpha_notifications';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'count' => (int) $count
        ));
    }
    
    // ==========================================
    // Admin Methods
    // ==========================================
    
    /**
     * Get all posts (admin)
     */
    public function admin_get_posts($request) {
        global $wpdb;
        
        $page = (int) $request->get_param('page') ?: 1;
        $per_page = (int) $request->get_param('per_page') ?: 20;
        $offset = ($page - 1) * $per_page;
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_posts");
        
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, 
                    (SELECT COUNT(*) FROM $table_reactions WHERE post_id = p.id) as reactions_count
             FROM $table_posts p 
             ORDER BY p.is_pinned DESC, p.created_at DESC 
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $formatted_posts = array_map(function($post) {
            return array(
                'id' => (int) $post->id,
                'content' => $post->content,
                'mediaType' => $post->media_type,
                'mediaUrl' => $post->media_url,
                'mediaDuration' => (int) $post->media_duration,
                'viewsCount' => (int) $post->views_count,
                'reactionsCount' => (int) $post->reactions_count,
                'isPinned' => (bool) $post->is_pinned,
                'isEdited' => (bool) $post->is_edited,
                'createdAt' => $post->created_at,
                'updatedAt' => $post->updated_at,
                'replyToId' => $post->reply_to_id ? (int) $post->reply_to_id : null
            );
        }, $posts);
        
        return rest_ensure_response(array(
            'success' => true,
            'posts' => $formatted_posts,
            'pagination' => array(
                'page' => $page,
                'perPage' => $per_page,
                'total' => (int) $total,
                'totalPages' => ceil($total / $per_page)
            )
        ));
    }
    
    /**
     * Create new post (admin)
     */
    public function create_post($request) {
        global $wpdb;
        
        $params = $request->get_json_params();
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        
       $wpdb->insert($table_posts, array(
    'content' => isset($params['content']) ? $params['content'] : '',
    'media_type' => isset($params['mediaType']) ? $params['mediaType'] : null,
    'media_url' => isset($params['mediaUrl']) ? $params['mediaUrl'] : null,
    'media_duration' => isset($params['mediaDuration']) ? (int) $params['mediaDuration'] : 0,
    'is_pinned' => isset($params['isPinned']) ? (int) $params['isPinned'] : 0,
    'reply_to_id' => isset($params['replyToId']) ? (int) $params['replyToId'] : null
));
        
        $post_id = $wpdb->insert_id;
        
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_posts WHERE id = %d",
            $post_id
        ));
        
        $post_data = array(
    'id' => (int) $post->id,
    'content' => $post->content,
    'mediaType' => $post->media_type,
    'mediaUrl' => $post->media_url,
    'mediaDuration' => (int) $post->media_duration,
    'viewsCount' => 0,
    'reactionsCount' => 0,
    'isPinned' => (bool) $post->is_pinned,
    'isEdited' => false,
    'createdAt' => $post->created_at,
    'replyToId' => $post->reply_to_id ? (int) $post->reply_to_id : null
);
        
        // Trigger Pusher event for real-time update
        $this->trigger_pusher_event('alpha-channel', 'new-post', $post_data);
        
        // Create notifications for all users
        $this->create_notification_for_all_users(
            'new_post',
            '📢 پست جدید در کانال آلفا',
            mb_substr($post->content, 0, 100) . (mb_strlen($post->content) > 100 ? '...' : ''),
            array('postId' => $post_id)
        );
        
        return rest_ensure_response(array(
            'success' => true,
            'post' => $post_data
        ));
    }
    
    /**
     * Update post (admin)
     */
    public function update_post($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        
        $update_data = array('is_edited' => 1);
        
        if (isset($params['content'])) {
            $update_data['content'] = $params['content'];
        }
        if (isset($params['mediaType'])) {
            $update_data['media_type'] = $params['mediaType'];
        }
        if (isset($params['mediaUrl'])) {
            $update_data['media_url'] = $params['mediaUrl'];
        }
        if (isset($params['mediaDuration'])) {
            $update_data['media_duration'] = (int) $params['mediaDuration'];
        }
        
        $wpdb->update($table_posts, $update_data, array('id' => $post_id));
        
        // Get updated post
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_posts WHERE id = %d",
            $post_id
        ));
        
        $post_data = array(
            'id' => (int) $post->id,
            'content' => $post->content,
            'mediaType' => $post->media_type,
            'mediaUrl' => $post->media_url,
            'mediaDuration' => (int) $post->media_duration,
            'viewsCount' => (int) $post->views_count,
            'isPinned' => (bool) $post->is_pinned,
            'isEdited' => true,
            'createdAt' => $post->created_at,
            'updatedAt' => $post->updated_at
        );
        
        // Trigger Pusher event
        $this->trigger_pusher_event('alpha-channel', 'post-updated', $post_data);
        
        return rest_ensure_response(array(
            'success' => true,
            'post' => $post_data
        ));
    }
    
    /**
     * Delete post (admin)
     */
    public function delete_post($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        // Delete reactions first
        $wpdb->delete($table_reactions, array('post_id' => $post_id));
        
        // Delete post
        $wpdb->delete($table_posts, array('id' => $post_id));
        
        // Trigger Pusher event
        $this->trigger_pusher_event('alpha-channel', 'post-deleted', array(
            'id' => $post_id
        ));
        
        return rest_ensure_response(array('success' => true));
    }
    
   /**
     * Toggle pin status (admin)
     */
    public function toggle_pin($request) {
        global $wpdb;
        
        $post_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        $duration = isset($params['duration']) ? $params['duration'] : null;
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        
        // Get current pin status
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT is_pinned FROM $table_posts WHERE id = %d",
            $post_id
        ));
        
        $new_status = $current ? 0 : 1;
        $pin_expires_at = null;
        
        // Calculate expiry time if pinning
        if ($new_status && $duration) {
            switch ($duration) {
                case '24h':
                    $pin_expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
                    break;
                case '1w':
                    $pin_expires_at = date('Y-m-d H:i:s', strtotime('+1 week'));
                    break;
                case '30d':
                    $pin_expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
                    break;
            }
        }
        
        $wpdb->update($table_posts,
            array(
                'is_pinned' => $new_status,
                'pin_expires_at' => $pin_expires_at
            ),
            array('id' => $post_id)
        );
        
        // Trigger Pusher event
        $this->trigger_pusher_event('alpha-channel', 'post-pinned', array(
            'id' => $post_id,
            'isPinned' => (bool) $new_status,
            'pinExpiresAt' => $pin_expires_at
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'isPinned' => (bool) $new_status,
            'pinExpiresAt' => $pin_expires_at
        ));
    }
    
    /**
     * Get channel statistics (admin)
     */
    public function get_channel_stats($request) {
        global $wpdb;
        
        $table_posts = $wpdb->prefix . 'alpha_channel_posts';
        $table_reactions = $wpdb->prefix . 'alpha_channel_reactions';
        
        $total_posts = $wpdb->get_var("SELECT COUNT(*) FROM $table_posts");
        $total_views = $wpdb->get_var("SELECT SUM(views_count) FROM $table_posts");
        $total_reactions = $wpdb->get_var("SELECT COUNT(*) FROM $table_reactions");
        $pinned_posts = $wpdb->get_var("SELECT COUNT(*) FROM $table_posts WHERE is_pinned = 1");
        
        // Today's stats
        $today = date('Y-m-d');
        $today_posts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_posts WHERE DATE(created_at) = %s",
            $today
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'stats' => array(
                'totalPosts' => (int) $total_posts,
                'totalViews' => (int) $total_views,
                'totalReactions' => (int) $total_reactions,
                'pinnedPosts' => (int) $pinned_posts,
                'todayPosts' => (int) $today_posts
            )
        ));
    }
    
    /**
     * Create notification for all users
     */
    private function create_notification_for_all_users($type, $title, $message, $data = array()) {
        global $wpdb;
        
        $table_notifications = $wpdb->prefix . 'alpha_notifications';
        
        // Get all users
        $users = get_users(array('fields' => 'ID'));
        
        foreach ($users as $user_id) {
            $wpdb->insert($table_notifications, array(
                'user_id' => $user_id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => json_encode($data)
            ));
        }
        
        // Trigger Pusher event for real-time notification
        $this->trigger_pusher_event('alpha-notifications', 'new-notification', array(
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data
        ));
    }
}

// Initialize
Alpha_Channel::get_instance();