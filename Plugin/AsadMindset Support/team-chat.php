<?php
/**
 * Team Chat Module for AsadMindset
 * Internal messaging between admin and sub-admins (DM + Group)
 * Uses Pusher for real-time messaging
 */

if (!defined('ABSPATH')) {
    exit;
}

class AsadMindset_TeamChat {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('init', array($this, 'maybe_create_tables'));
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    // ─── Tables ───────────────────────────────────────────────────────────
    
    public function maybe_create_tables() {
        global $wpdb;
        $table = $wpdb->prefix . 'team_conversations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            $this->create_tables();
        }
    }
    
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Team conversations (DM or Group)
        $t1 = $wpdb->prefix . 'team_conversations';
        $sql1 = "CREATE TABLE IF NOT EXISTS $t1 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(10) NOT NULL DEFAULT 'direct',
            name varchar(200) DEFAULT NULL,
            avatar_url varchar(500) DEFAULT NULL,
            created_by bigint(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY created_by (created_by)
        ) $charset_collate;";
        
        // Members of each conversation
        $t2 = $wpdb->prefix . 'team_conversation_members';
        $sql2 = "CREATE TABLE IF NOT EXISTS $t2 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            role varchar(20) DEFAULT 'member',
            last_read_at datetime DEFAULT NULL,
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY conv_user (conversation_id, user_id),
            KEY conversation_id (conversation_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // Team messages
        $t3 = $wpdb->prefix . 'team_messages';
        $sql3 = "CREATE TABLE IF NOT EXISTS $t3 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            conversation_id bigint(20) NOT NULL,
            sender_id bigint(20) NOT NULL,
            message_type varchar(20) DEFAULT 'text',
            content text,
            media_url varchar(500) DEFAULT NULL,
            duration int(11) DEFAULT 0,
            is_edited tinyint(1) DEFAULT 0,
            reply_to_id bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id),
            KEY sender_id (sender_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }
    
    // ─── Auth Helpers ─────────────────────────────────────────────────────
    
    private function get_bearer_token($r) {
        $h = $r->get_header('Authorization');
        if ($h && preg_match('/Bearer\s(\S+)/', $h, $m)) return $m[1];
        return null;
    }
    
    private function validate_jwt_token($token) {
        $sk = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;
        if (!$sk) return false;
        try {
            $p = explode('.', $token);
            if (count($p) !== 3) return false;
            $pl = json_decode(base64_decode($p[1]), true);
            if (!$pl || !isset($pl['data']['user']['id'])) return false;
            if (isset($pl['exp']) && $pl['exp'] < time()) return false;
            return $pl['data']['user']['id'];
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function get_user_id_from_request($r) {
        $t = $this->get_bearer_token($r);
        return $this->validate_jwt_token($t);
    }
    
    /**
     * Check if user is admin or active sub-admin (team member)
     */
    public function check_team_auth($r) {
        $uid = $this->get_user_id_from_request($r);
        if (!$uid) return false;
        
        // Main admin
        $user = get_user_by('id', $uid);
        if ($user && user_can($user, 'manage_options')) return true;
        
        // Active sub-admin
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT is_active FROM $table WHERE user_id = %d AND is_active = 1", $uid
        ));
        
        return !empty($row);
    }
    
    /**
     * Check if user is member of a conversation
     */
    private function is_conversation_member($conversation_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'team_conversation_members';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE conversation_id = %d AND user_id = %d",
            $conversation_id, $user_id
        ));
        return !empty($row);
    }
    
    /**
     * Check if user is admin of a group conversation
     */
    private function is_conversation_admin($conversation_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'team_conversation_members';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE conversation_id = %d AND user_id = %d AND role = 'admin'",
            $conversation_id, $user_id
        ));
        return !empty($row);
    }
    
    /**
     * Trigger Pusher event (reuse from main plugin)
     */
    private function trigger_pusher_event($channel, $event, $data) {
        $support = AsadMindset_Support::get_instance();
        return $support->trigger_pusher_event($channel, $event, $data);
    }
    
    // ─── Routes ───────────────────────────────────────────────────────────
    
    public function register_routes() {
        $ns = 'asadmindset/v1';
        
        // Get all team conversations for current user
        register_rest_route($ns, '/team/conversations', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_conversations'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Create a new conversation (DM or Group)
        register_rest_route($ns, '/team/conversations', [
            'methods'  => 'POST',
            'callback' => [$this, 'create_conversation'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Get messages of a conversation
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/messages', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_messages'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Send message in a conversation
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/message', [
            'methods'  => 'POST',
            'callback' => [$this, 'send_message'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Edit a message
        register_rest_route($ns, '/team/messages/(?P<id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'edit_message'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Delete a message
        register_rest_route($ns, '/team/messages/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'delete_message'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Mark conversation as read
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/read', [
            'methods'  => 'POST',
            'callback' => [$this, 'mark_as_read'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Typing indicator
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/typing', [
            'methods'  => 'POST',
            'callback' => [$this, 'typing_indicator'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Get team members (for creating DM/Group)
        register_rest_route($ns, '/team/members', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_team_members'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Update group (name, avatar)
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_conversation'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Add member to group
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/members', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_member'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Remove member from group
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/members/(?P<user_id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'remove_member'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
        
        // Leave group
        register_rest_route($ns, '/team/conversations/(?P<id>\d+)/leave', [
            'methods'  => 'POST',
            'callback' => [$this, 'leave_conversation'],
            'permission_callback' => [$this, 'check_team_auth'],
        ]);
    }
    
    // ─── Callbacks ────────────────────────────────────────────────────────
    
    /**
     * GET /team/conversations — list all conversations for current user
     */
    public function get_conversations($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        $t_msgs = $wpdb->prefix . 'team_messages';
        
        // Get conversations where user is a member
        $conversations = $wpdb->get_results($wpdb->prepare("
            SELECT c.*, 
                   m.user_id as member_user_id,
                   (SELECT content FROM $t_msgs WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message,
                   (SELECT message_type FROM $t_msgs WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_type,
                   (SELECT sender_id FROM $t_msgs WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_sender_id,
                   (SELECT created_at FROM $t_msgs WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message_at,
                   (SELECT COUNT(*) FROM $t_msgs tm WHERE tm.conversation_id = c.id AND tm.created_at > COALESCE(m.last_read_at, '1970-01-01') AND tm.sender_id != %d) as unread_count
            FROM $t_conv c
            INNER JOIN $t_members m ON m.conversation_id = c.id AND m.user_id = %d
            ORDER BY COALESCE(
                (SELECT created_at FROM $t_msgs WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1),
                c.created_at
            ) DESC
        ", $uid, $uid));
        
        $result = [];
        foreach ($conversations as $conv) {
            $item = [
                'id'              => (int) $conv->id,
                'type'            => $conv->type,
                'name'            => $conv->name,
                'avatarUrl'       => $conv->avatar_url,
                'createdBy'       => (int) $conv->created_by,
                'lastMessage'     => $conv->last_message,
                'lastMessageType' => $conv->last_message_type,
                'lastMessageAt'   => $conv->last_message_at,
                'unreadCount'     => (int) $conv->unread_count,
                'createdAt'       => $conv->created_at,
                'updatedAt'       => $conv->updated_at,
                'members'         => [],
            ];
            
            // Get last message sender name
            if ($conv->last_message_sender_id) {
                $sender = get_userdata((int) $conv->last_message_sender_id);
                $item['lastMessageSenderName'] = $sender ? $sender->display_name : 'ناشناس';
                $item['lastMessageSenderId'] = (int) $conv->last_message_sender_id;
            }
            
            // Get members
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, role FROM $t_members WHERE conversation_id = %d",
                $conv->id
            ));
            
            foreach ($members as $mem) {
                $u = get_userdata((int) $mem->user_id);
                $item['members'][] = [
                    'userId'      => (int) $mem->user_id,
                    'displayName' => $u ? $u->display_name : 'ناشناس',
                    'email'       => $u ? $u->user_email : '',
                    'role'        => $mem->role,
                ];
            }
            
            // For DM, set the other person's name as conversation name
            if ($conv->type === 'direct' && count($item['members']) === 2) {
                foreach ($item['members'] as $mem) {
                    if ($mem['userId'] !== $uid) {
                        $item['displayName'] = $mem['displayName'];
                        $item['displayEmail'] = $mem['email'];
                        break;
                    }
                }
            }
            
            $result[] = $item;
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * POST /team/conversations — create DM or Group
     * Body: { type: "direct"|"group", name?: string, memberIds: [1,2,3] }
     */
    public function create_conversation($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $params = $r->get_json_params();
        
        $type = isset($params['type']) ? $params['type'] : 'direct';
        $name = isset($params['name']) ? sanitize_text_field($params['name']) : null;
        $member_ids = isset($params['memberIds']) ? array_map('intval', $params['memberIds']) : [];
        
        // Validate
        if (empty($member_ids)) {
            return new WP_REST_Response(['message' => 'حداقل یک عضو انتخاب کنید'], 400);
        }
        
        // Add creator to members if not already there
        if (!in_array($uid, $member_ids)) {
            $member_ids[] = $uid;
        }
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        // For DM, check if conversation already exists between these two users
        if ($type === 'direct' && count($member_ids) === 2) {
            $other_id = $member_ids[0] === $uid ? $member_ids[1] : $member_ids[0];
            
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT c.id FROM $t_conv c
                INNER JOIN $t_members m1 ON m1.conversation_id = c.id AND m1.user_id = %d
                INNER JOIN $t_members m2 ON m2.conversation_id = c.id AND m2.user_id = %d
                WHERE c.type = 'direct'
                LIMIT 1
            ", $uid, $other_id));
            
            if ($existing) {
                return new WP_REST_Response(['id' => (int) $existing, 'existing' => true], 200);
            }
        }
        
        // For group, name is required
        if ($type === 'group' && empty($name)) {
            return new WP_REST_Response(['message' => 'نام گروه الزامی است'], 400);
        }
        
        // Create conversation
        $wpdb->insert($t_conv, [
            'type'       => $type,
            'name'       => $name,
            'created_by' => $uid,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        $conv_id = $wpdb->insert_id;
        
        // Add members
        foreach ($member_ids as $mid) {
            $role = ($mid === $uid) ? 'admin' : 'member';
            $wpdb->insert($t_members, [
                'conversation_id' => $conv_id,
                'user_id'         => $mid,
                'role'            => $role,
                'joined_at'       => current_time('mysql'),
            ]);
        }
        
        // Notify members via Pusher
        $creator = get_userdata($uid);
        foreach ($member_ids as $mid) {
            if ($mid !== $uid) {
                $this->trigger_pusher_event('team-user-' . $mid, 'new-conversation', [
                    'conversationId' => $conv_id,
                    'type'           => $type,
                    'name'           => $name,
                    'createdBy'      => $uid,
                    'createdByName'  => $creator ? $creator->display_name : 'ناشناس',
                ]);
            }
        }
        
        return new WP_REST_Response(['id' => $conv_id, 'existing' => false], 201);
    }
    
    /**
     * GET /team/conversations/{id}/messages
     */
    public function get_messages($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        
        // Check membership
        if (!$this->is_conversation_member($conv_id, $uid)) {
            return new WP_REST_Response(['message' => 'دسترسی ندارید'], 403);
        }
        
        $t_msgs = $wpdb->prefix . 'team_messages';
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        // Get conversation info
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conv WHERE id = %d", $conv_id));
        if (!$conv) {
            return new WP_REST_Response(['message' => 'مکالمه یافت نشد'], 404);
        }
        
        // Get members
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, role, last_read_at FROM $t_members WHERE conversation_id = %d", $conv_id
        ));
        $members_list = [];
        foreach ($members as $mem) {
            $u = get_userdata((int) $mem->user_id);
            $members_list[] = [
                'userId'      => (int) $mem->user_id,
                'displayName' => $u ? $u->display_name : 'ناشناس',
                'email'       => $u ? $u->user_email : '',
                'role'        => $mem->role,
                'lastReadAt'  => $mem->last_read_at,
            ];
        }
        
        // Get the latest last_read_at of OTHER members (for read receipts)
        $others_read = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(last_read_at) FROM $t_members WHERE conversation_id = %d AND user_id != %d",
            $conv_id, $uid
        ));
        
        // Get messages with pagination
        $page = max(1, (int) $r->get_param('page'));
        $per_page = min(100, max(20, (int) ($r->get_param('per_page') ?? 50)));
        $offset = ($page - 1) * $per_page;
        
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t_msgs WHERE conversation_id = %d", $conv_id
        ));
        
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t_msgs WHERE conversation_id = %d ORDER BY created_at ASC LIMIT %d OFFSET %d",
            $conv_id, $per_page, $offset
        ));
        
        $formatted = array_map(function($msg) use ($wpdb, $t_msgs) {
            $sender = get_userdata((int) $msg->sender_id);
            
            $result = [
                'id'         => (int) $msg->id,
                'type'       => $msg->message_type,
                'content'    => $msg->content,
                'mediaUrl'   => $msg->media_url,
                'duration'   => (int) $msg->duration,
                'senderId'   => (int) $msg->sender_id,
                'senderName' => $sender ? $sender->display_name : 'ناشناس',
                'isEdited'   => (bool) $msg->is_edited,
                'createdAt'  => $msg->created_at,
                'replyTo'    => null,
            ];
            
            if (!empty($msg->reply_to_id)) {
                $reply = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, message_type, content, sender_id FROM $t_msgs WHERE id = %d",
                    $msg->reply_to_id
                ));
                if ($reply) {
                    $reply_sender = get_userdata((int) $reply->sender_id);
                    $result['replyTo'] = [
                        'id'         => (int) $reply->id,
                        'type'       => $reply->message_type,
                        'content'    => $reply->content,
                        'senderId'   => (int) $reply->sender_id,
                        'senderName' => $reply_sender ? $reply_sender->display_name : 'ناشناس',
                    ];
                }
            }
            
            return $result;
        }, $messages);
        
        return new WP_REST_Response([
            'conversation' => [
                'id'               => (int) $conv->id,
                'type'             => $conv->type,
                'name'             => $conv->name,
                'avatarUrl'        => $conv->avatar_url,
                'createdBy'        => (int) $conv->created_by,
                'members'          => $members_list,
                'othersLastReadAt' => $others_read,
            ],
            'messages'   => $formatted,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $per_page,
            'totalPages' => ceil($total / $per_page),
        ], 200);
    }
    
    /**
     * POST /team/conversations/{id}/message — send a message
     */
    public function send_message($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        $params = $r->get_json_params();
        
        if (!$this->is_conversation_member($conv_id, $uid)) {
            return new WP_REST_Response(['message' => 'دسترسی ندارید'], 403);
        }
        
        $t_msgs = $wpdb->prefix . 'team_messages';
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        // Insert message
        $wpdb->insert($t_msgs, [
            'conversation_id' => $conv_id,
            'sender_id'       => $uid,
            'message_type'    => isset($params['type']) ? $params['type'] : 'text',
            'content'         => isset($params['content']) ? $params['content'] : '',
            'media_url'       => isset($params['mediaUrl']) ? $params['mediaUrl'] : null,
            'duration'        => isset($params['duration']) ? (int) $params['duration'] : 0,
            'reply_to_id'     => isset($params['replyToId']) ? (int) $params['replyToId'] : null,
            'created_at'      => current_time('mysql'),
        ]);
        $msg_id = $wpdb->insert_id;
        
        // Update conversation timestamp
        $wpdb->update($t_conv, ['updated_at' => current_time('mysql')], ['id' => $conv_id]);
        
        // Update sender's last_read_at
        $wpdb->update($t_members, 
            ['last_read_at' => current_time('mysql')],
            ['conversation_id' => $conv_id, 'user_id' => $uid]
        );
        
        // Get sender info
        $sender = get_userdata($uid);
        
        // Get replyTo data
        $reply_to_data = null;
        if (!empty($params['replyToId'])) {
            $reply = $wpdb->get_row($wpdb->prepare(
                "SELECT id, message_type, content, sender_id FROM $t_msgs WHERE id = %d",
                $params['replyToId']
            ));
            if ($reply) {
                $reply_sender = get_userdata((int) $reply->sender_id);
                $reply_to_data = [
                    'id'         => (int) $reply->id,
                    'type'       => $reply->message_type,
                    'content'    => $reply->content,
                    'senderId'   => (int) $reply->sender_id,
                    'senderName' => $reply_sender ? $reply_sender->display_name : 'ناشناس',
                ];
            }
        }
        
        $pusher_message = [
            'id'             => $msg_id,
            'type'           => isset($params['type']) ? $params['type'] : 'text',
            'content'        => isset($params['content']) ? $params['content'] : '',
            'mediaUrl'       => isset($params['mediaUrl']) ? $params['mediaUrl'] : null,
            'duration'       => isset($params['duration']) ? (int) $params['duration'] : 0,
            'senderId'       => $uid,
            'senderName'     => $sender ? $sender->display_name : 'ناشناس',
            'isEdited'       => false,
            'createdAt'      => current_time('mysql'),
            'conversationId' => $conv_id,
            'replyTo'        => $reply_to_data,
        ];
        
        // Send to conversation channel
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'new-message', $pusher_message);
        
        // Also notify each member's personal channel (for unread badge updates)
        $members = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $t_members WHERE conversation_id = %d AND user_id != %d",
            $conv_id, $uid
        ));
        $push = new AsadMindset_Push_Notifications();
        $sender_name = $sender ? $sender->display_name : 'تیم';
        $msg_type = isset($params['type']) ? $params['type'] : 'text';
        $msg_content = isset($params['content']) ? $params['content'] : '';
        $msg_body = $msg_type === 'text'
            ? mb_substr($msg_content, 0, 100)
            : ($msg_type === 'image' ? '📷 تصویر' : ($msg_type === 'video' ? '🎬 ویدیو' : '🎤 صوتی'));
        
        foreach ($members as $member_id) {
            $this->trigger_pusher_event('team-user-' . $member_id, 'new-team-message', [
                'conversationId' => $conv_id,
                'message'        => $pusher_message,
            ]);
            
            // Push notification to team member
            $push->send_to_user((int) $member_id, '💬 ' . $sender_name, $msg_body, ['type' => 'team', 'conversationId' => (string) $conv_id, 'url' => '/?open=teamChat&chatId=' . $conv_id]);
        }
        
        return new WP_REST_Response(['success' => true, 'message' => $pusher_message], 200);
    }
    
    /**
     * PUT /team/messages/{id} — edit a message
     */
    public function edit_message($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $msg_id = (int) $r->get_param('id');
        $params = $r->get_json_params();
        
        $t_msgs = $wpdb->prefix . 'team_messages';
        
        $msg = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_msgs WHERE id = %d AND sender_id = %d", $msg_id, $uid
        ));
        
        if (!$msg) {
            return new WP_REST_Response(['message' => 'پیام یافت نشد'], 404);
        }
        
        $wpdb->update($t_msgs, [
            'content'   => $params['content'],
            'is_edited' => 1,
        ], ['id' => $msg_id]);
        
        $this->trigger_pusher_event('team-conversation-' . $msg->conversation_id, 'message-edited', [
            'id'      => $msg_id,
            'content' => $params['content'],
            'isEdited' => true,
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * DELETE /team/messages/{id} — delete a message
     */
    public function delete_message($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $msg_id = (int) $r->get_param('id');
        
        $t_msgs = $wpdb->prefix . 'team_messages';
        
        $msg = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t_msgs WHERE id = %d AND sender_id = %d", $msg_id, $uid
        ));
        
        if (!$msg) {
            return new WP_REST_Response(['message' => 'پیام یافت نشد'], 404);
        }
        
        $wpdb->delete($t_msgs, ['id' => $msg_id]);
        
        $this->trigger_pusher_event('team-conversation-' . $msg->conversation_id, 'message-deleted', [
            'id' => $msg_id,
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * POST /team/conversations/{id}/read — mark conversation as read
     */
    public function mark_as_read($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        $wpdb->update($t_members,
            ['last_read_at' => current_time('mysql')],
            ['conversation_id' => $conv_id, 'user_id' => $uid]
        );
        
        // Notify others that this user has read the messages
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'messages-read', [
            'userId'   => $uid,
            'readAt'   => current_time('mysql'),
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * POST /team/conversations/{id}/typing
     */
    public function typing_indicator($r) {
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        $params = $r->get_json_params();
        
        $sender = get_userdata($uid);
        
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'typing', [
            'userId'      => $uid,
            'userName'    => $sender ? $sender->display_name : 'ناشناس',
            'isTyping'    => isset($params['isTyping']) ? (bool) $params['isTyping'] : true,
            'isRecording' => isset($params['isRecording']) ? (bool) $params['isRecording'] : false,
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * GET /team/members — get list of all team members (admin + sub-admins)
     */
    public function get_team_members($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        
        $result = [];
        
        // Get main admin(s)
        $admins = get_users(['role' => 'administrator']);
        foreach ($admins as $admin) {
            if (user_can($admin, 'manage_options')) {
                $result[] = [
                    'userId'      => (int) $admin->ID,
                    'displayName' => $admin->display_name,
                    'email'       => $admin->user_email,
                    'role'        => 'ادمین اصلی',
                    'isMainAdmin' => true,
                ];
            }
        }
        
        // Get active sub-admins
        $table = $wpdb->prefix . 'sub_admins';
        $sub_admins = $wpdb->get_results(
            "SELECT user_id, label, permissions FROM $table WHERE is_active = 1"
        );
        
        $existing_ids = array_column($result, 'userId');
        
        foreach ($sub_admins as $sa) {
            if (in_array((int) $sa->user_id, $existing_ids)) continue;
            
            $u = get_userdata((int) $sa->user_id);
            if (!$u) continue;
            
            $result[] = [
                'userId'      => (int) $sa->user_id,
                'displayName' => $u->display_name,
                'email'       => $u->user_email,
                'role'        => $sa->label ?: 'کاربر ارشد',
                'isMainAdmin' => false,
            ];
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * PUT /team/conversations/{id} — update group name/avatar
     */
    public function update_conversation($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        $params = $r->get_json_params();
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        
        // Check if group and user is admin of group
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conv WHERE id = %d", $conv_id));
        if (!$conv || $conv->type !== 'group') {
            return new WP_REST_Response(['message' => 'گروه یافت نشد'], 404);
        }
        
        if (!$this->is_conversation_admin($conv_id, $uid)) {
            // Allow main admin to also edit
            $user = get_user_by('id', $uid);
            if (!$user || !user_can($user, 'manage_options')) {
                return new WP_REST_Response(['message' => 'فقط ادمین گروه می‌تواند تغییر دهد'], 403);
            }
        }
        
        $update = [];
        if (isset($params['name'])) $update['name'] = sanitize_text_field($params['name']);
        if (isset($params['avatarUrl'])) $update['avatar_url'] = $params['avatarUrl'];
        
        if (!empty($update)) {
            $wpdb->update($t_conv, $update, ['id' => $conv_id]);
            
            $this->trigger_pusher_event('team-conversation-' . $conv_id, 'conversation-updated', $update);
        }
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * POST /team/conversations/{id}/members — add member to group
     */
    public function add_member($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        $params = $r->get_json_params();
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conv WHERE id = %d AND type = 'group'", $conv_id));
        if (!$conv) {
            return new WP_REST_Response(['message' => 'گروه یافت نشد'], 404);
        }
        
        if (!$this->is_conversation_member($conv_id, $uid)) {
            return new WP_REST_Response(['message' => 'دسترسی ندارید'], 403);
        }
        
        $new_user_id = isset($params['userId']) ? (int) $params['userId'] : 0;
        if (!$new_user_id) {
            return new WP_REST_Response(['message' => 'کاربر مشخص نشده'], 400);
        }
        
        // Check if already member
        if ($this->is_conversation_member($conv_id, $new_user_id)) {
            return new WP_REST_Response(['message' => 'این کاربر قبلاً عضو است'], 400);
        }
        
        $wpdb->insert($t_members, [
            'conversation_id' => $conv_id,
            'user_id'         => $new_user_id,
            'role'            => 'member',
            'joined_at'       => current_time('mysql'),
        ]);
        
        $new_user = get_userdata($new_user_id);
        $adder = get_userdata($uid);
        
        // Notify group
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'member-added', [
            'userId'      => $new_user_id,
            'displayName' => $new_user ? $new_user->display_name : 'ناشناس',
            'addedBy'     => $adder ? $adder->display_name : 'ناشناس',
        ]);
        
        // Notify the new member
        $this->trigger_pusher_event('team-user-' . $new_user_id, 'new-conversation', [
            'conversationId' => $conv_id,
            'type'           => 'group',
            'name'           => $conv->name,
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * DELETE /team/conversations/{id}/members/{user_id} — remove member
     */
    public function remove_member($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        $target_user_id = (int) $r->get_param('user_id');
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conv WHERE id = %d AND type = 'group'", $conv_id));
        if (!$conv) {
            return new WP_REST_Response(['message' => 'گروه یافت نشد'], 404);
        }
        
        // Only group admin or main admin can remove
        if (!$this->is_conversation_admin($conv_id, $uid)) {
            $user = get_user_by('id', $uid);
            if (!$user || !user_can($user, 'manage_options')) {
                return new WP_REST_Response(['message' => 'فقط ادمین گروه می‌تواند عضو حذف کند'], 403);
            }
        }
        
        $wpdb->delete($t_members, [
            'conversation_id' => $conv_id,
            'user_id'         => $target_user_id,
        ]);
        
        $removed_user = get_userdata($target_user_id);
        
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'member-removed', [
            'userId'      => $target_user_id,
            'displayName' => $removed_user ? $removed_user->display_name : 'ناشناس',
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
    
    /**
     * POST /team/conversations/{id}/leave — leave group
     */
    public function leave_conversation($r) {
        global $wpdb;
        $uid = $this->get_user_id_from_request($r);
        $conv_id = (int) $r->get_param('id');
        
        $t_conv = $wpdb->prefix . 'team_conversations';
        $t_members = $wpdb->prefix . 'team_conversation_members';
        
        $conv = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_conv WHERE id = %d AND type = 'group'", $conv_id));
        if (!$conv) {
            return new WP_REST_Response(['message' => 'گروه یافت نشد'], 404);
        }
        
        $wpdb->delete($t_members, [
            'conversation_id' => $conv_id,
            'user_id'         => $uid,
        ]);
        
        $user = get_userdata($uid);
        
        $this->trigger_pusher_event('team-conversation-' . $conv_id, 'member-left', [
            'userId'      => $uid,
            'displayName' => $user ? $user->display_name : 'ناشناس',
        ]);
        
        return new WP_REST_Response(['success' => true], 200);
    }
}

// Initialize
AsadMindset_TeamChat::get_instance();