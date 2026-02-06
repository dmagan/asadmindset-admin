<?php
/**
 * Sub-Admin Management Module for AsadMindset
 * Role-based access control: assign permissions to users (support, channel, subscriptions, discounts, view-only)
 * Includes activity logging and notification system
 */

if (!defined('ABSPATH')) {
    exit;
}

class AsadMindset_SubAdmin {
    
    private static $instance = null;
    
    // All available permissions
    const PERMISSIONS = [
        'support'       => 'پاسخ به پشتیبانی',
        'channel'       => 'مدیریت کانال آلفا',
        'subscriptions' => 'تایید/رد اشتراک‌ها',
        'discounts'     => 'مدیریت کدهای تخفیف',
        'manual_order'  => 'ثبت سفارش دستی',
        'view_only'     => 'فقط مشاهده',
    ];
    
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
        $table_sa = $wpdb->prefix . 'sub_admins';
        $table_log = $wpdb->prefix . 'sub_admin_logs';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_sa'") != $table_sa) {
            $this->create_tables();
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_log'") != $table_log) {
            $this->create_tables();
        }
    }
    
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_sa = $wpdb->prefix . 'sub_admins';
        $sql1 = "CREATE TABLE IF NOT EXISTS $table_sa (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            permissions text NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            added_by bigint(20) NOT NULL,
            label varchar(100) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        
        $table_log = $wpdb->prefix . 'sub_admin_logs';
        $sql2 = "CREATE TABLE IF NOT EXISTS $table_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            action varchar(100) NOT NULL,
            details text DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }
    
    // ─── Auth Helpers ─────────────────────────────────────────────────────
    
    public function check_admin_auth($r) {
        $t = $this->get_bearer_token($r);
        if (!$t) return false;
        $uid = $this->validate_jwt_token($t);
        if (!$uid) return false;
        $u = get_user_by('id', $uid);
        return $u && user_can($u, 'manage_options');
    }
    
    public function get_user_id_from_request($r) {
        $t = $this->get_bearer_token($r);
        return $this->validate_jwt_token($t);
    }
    
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
    
    /**
     * Check if a user has a specific sub-admin permission
     */
    public function user_has_permission($user_id, $permission) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        
        // Main admin always has all permissions
        $user = get_user_by('id', $user_id);
        if ($user && user_can($user, 'manage_options')) {
            return true;
        }
        
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT permissions, is_active FROM $table WHERE user_id = %d", $user_id
        ));
        
        if (!$row || !$row->is_active) return false;
        
        $perms = json_decode($row->permissions, true);
        if (!is_array($perms)) return false;
        
        return in_array($permission, $perms);
    }
    
    /**
     * Check if user is sub-admin OR main admin
     */
    public function is_admin_or_subadmin($r) {
        $uid = $this->get_user_id_from_request($r);
        if (!$uid) return false;
        
        $user = get_user_by('id', $uid);
        if ($user && user_can($user, 'manage_options')) return true;
        
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT is_active FROM $table WHERE user_id = %d AND is_active = 1", $uid
        ));
        
        return !empty($row);
    }
    
    /**
     * Check if user has a specific permission (sub-admin check for route callbacks)
     */
    public function check_permission($r, $permission) {
        $uid = $this->get_user_id_from_request($r);
        if (!$uid) return false;
        return $this->user_has_permission($uid, $permission);
    }
    
    /**
     * Log a sub-admin action
     */
    public function log_action($user_id, $action, $details = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admin_logs';
        $wpdb->insert($table, [
            'user_id'    => $user_id,
            'action'     => $action,
            'details'    => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at' => current_time('mysql'),
        ]);
    }
    
    // ─── Routes ───────────────────────────────────────────────────────────
    
    public function register_routes() {
        $ns = 'asadmindset/v1';
        
        // Get all sub-admins (admin only)
        register_rest_route($ns, '/sub-admins', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_sub_admins'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Add sub-admin (admin only)
        register_rest_route($ns, '/sub-admins', [
            'methods'  => 'POST',
            'callback' => [$this, 'add_sub_admin'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Update sub-admin permissions (admin only)
        register_rest_route($ns, '/sub-admins/(?P<id>\d+)', [
            'methods'  => 'PUT',
            'callback' => [$this, 'update_sub_admin'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Toggle active/deactivate sub-admin (admin only)
        register_rest_route($ns, '/sub-admins/(?P<id>\d+)/toggle', [
            'methods'  => 'POST',
            'callback' => [$this, 'toggle_sub_admin'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Delete sub-admin (admin only)
        register_rest_route($ns, '/sub-admins/(?P<id>\d+)', [
            'methods'  => 'DELETE',
            'callback' => [$this, 'delete_sub_admin'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Get activity logs (admin only)
        register_rest_route($ns, '/sub-admins/logs', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
        
        // Get my permissions (any logged-in user)
        register_rest_route($ns, '/my-permissions', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_my_permissions'],
            'permission_callback' => '__return_true',
        ]);
        
        // Get available permissions list
        register_rest_route($ns, '/sub-admins/permissions-list', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_permissions_list'],
            'permission_callback' => [$this, 'check_admin_auth'],
        ]);
    }
    
    // ─── Endpoints ────────────────────────────────────────────────────────
    
    /**
     * GET /sub-admins — list all sub-admins with user info
     */
    public function get_sub_admins($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        
        $result = [];
        foreach ($rows as $row) {
            $user = get_user_by('id', $row->user_id);
            $result[] = [
                'id'          => (int)$row->id,
                'user_id'     => (int)$row->user_id,
                'email'       => $user ? $user->user_email : '—',
                'name'        => $user ? $user->display_name : '—',
                'permissions' => json_decode($row->permissions, true) ?: [],
                'is_active'   => (bool)$row->is_active,
                'label'       => $row->label,
                'created_at'  => $row->created_at,
                'updated_at'  => $row->updated_at,
            ];
        }
        
        return new WP_REST_Response($result, 200);
    }
    
    /**
     * POST /sub-admins — add a new sub-admin by email
     */
    public function add_sub_admin($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        
        $email = sanitize_email($r->get_param('email'));
        $permissions = $r->get_param('permissions'); // array of permission keys
        $label = sanitize_text_field($r->get_param('label') ?? '');
        
        if (empty($email)) {
            return new WP_REST_Response(['message' => 'ایمیل الزامی است'], 400);
        }
        
        $user = get_user_by('email', $email);
        if (!$user) {
            return new WP_REST_Response(['message' => 'کاربری با این ایمیل یافت نشد'], 404);
        }
        
        // Don't allow adding the main admin as sub-admin
        if (user_can($user, 'manage_options')) {
            return new WP_REST_Response(['message' => 'ادمین اصلی نیاز به تعریف دسترسی ندارد'], 400);
        }
        
        // Check duplicate
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d", $user->ID
        ));
        if ($existing) {
            return new WP_REST_Response(['message' => 'این کاربر قبلاً به عنوان کاربر ارشد تعریف شده است'], 409);
        }
        
        // Validate permissions
        if (!is_array($permissions) || empty($permissions)) {
            return new WP_REST_Response(['message' => 'حداقل یک دسترسی انتخاب کنید'], 400);
        }
        $valid_perms = array_keys(self::PERMISSIONS);
        $permissions = array_values(array_intersect($permissions, $valid_perms));
        if (empty($permissions)) {
            return new WP_REST_Response(['message' => 'دسترسی‌های انتخاب شده نامعتبر هستند'], 400);
        }
        
        $admin_id = $this->get_user_id_from_request($r);
        
        $wpdb->insert($table, [
            'user_id'     => $user->ID,
            'permissions' => json_encode($permissions),
            'is_active'   => 1,
            'added_by'    => $admin_id,
            'label'       => $label,
            'created_at'  => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
        ]);
        
        $insert_id = $wpdb->insert_id;
        
        // Log the action
        $this->log_action($admin_id, 'add_sub_admin', json_encode([
            'target_user' => $user->ID,
            'email'       => $email,
            'permissions' => $permissions,
        ]));
        
        // Send notification to the user via Pusher
        $this->notify_user($user->ID, 'sub_admin_granted', [
            'message'     => 'شما به عنوان کاربر ارشد تعیین شدید',
            'permissions' => $permissions,
        ]);
        
        return new WP_REST_Response([
            'message' => 'کاربر ارشد با موفقیت اضافه شد',
            'id'      => $insert_id,
            'user_id' => $user->ID,
            'email'   => $email,
            'name'    => $user->display_name,
            'permissions' => $permissions,
            'is_active'   => true,
            'label'       => $label,
        ], 201);
    }
    
    /**
     * PUT /sub-admins/{id} — update permissions/label
     */
    public function update_sub_admin($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $id = (int)$r->get_param('id');
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$row) {
            return new WP_REST_Response(['message' => 'کاربر ارشد یافت نشد'], 404);
        }
        
        $permissions = $r->get_param('permissions');
        $label = $r->get_param('label');
        
        $update = ['updated_at' => current_time('mysql')];
        
        if ($permissions !== null) {
            $valid_perms = array_keys(self::PERMISSIONS);
            $permissions = array_values(array_intersect((array)$permissions, $valid_perms));
            if (empty($permissions)) {
                return new WP_REST_Response(['message' => 'حداقل یک دسترسی انتخاب کنید'], 400);
            }
            $update['permissions'] = json_encode($permissions);
        }
        
        if ($label !== null) {
            $update['label'] = sanitize_text_field($label);
        }
        
        $wpdb->update($table, $update, ['id' => $id]);
        
        $admin_id = $this->get_user_id_from_request($r);
        $this->log_action($admin_id, 'update_sub_admin', json_encode([
            'target_id'   => $id,
            'target_user' => $row->user_id,
            'changes'     => $update,
        ]));
        
        // Notify the user about permission change
        $this->notify_user($row->user_id, 'permissions_updated', [
            'message'     => 'دسترسی‌های شما به‌روزرسانی شد',
            'permissions' => $permissions ?? json_decode($row->permissions, true),
        ]);
        
        return new WP_REST_Response(['message' => 'دسترسی‌ها با موفقیت به‌روزرسانی شد'], 200);
    }
    
    /**
     * POST /sub-admins/{id}/toggle — activate/deactivate
     */
    public function toggle_sub_admin($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $id = (int)$r->get_param('id');
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$row) {
            return new WP_REST_Response(['message' => 'کاربر ارشد یافت نشد'], 404);
        }
        
        $new_status = $row->is_active ? 0 : 1;
        $wpdb->update($table, [
            'is_active'  => $new_status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        
        $admin_id = $this->get_user_id_from_request($r);
        $this->log_action($admin_id, $new_status ? 'activate_sub_admin' : 'deactivate_sub_admin', json_encode([
            'target_id'   => $id,
            'target_user' => $row->user_id,
        ]));
        
        $status_text = $new_status ? 'فعال' : 'غیرفعال';
        
        // Notify user
        $this->notify_user($row->user_id, 'status_changed', [
            'message'   => "دسترسی ارشد شما $status_text شد",
            'is_active' => (bool)$new_status,
        ]);
        
        return new WP_REST_Response([
            'message'   => "کاربر ارشد $status_text شد",
            'is_active' => (bool)$new_status,
        ], 200);
    }
    
    /**
     * DELETE /sub-admins/{id} — remove sub-admin
     */
    public function delete_sub_admin($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $id = (int)$r->get_param('id');
        
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$row) {
            return new WP_REST_Response(['message' => 'کاربر ارشد یافت نشد'], 404);
        }
        
        $wpdb->delete($table, ['id' => $id]);
        
        $admin_id = $this->get_user_id_from_request($r);
        $this->log_action($admin_id, 'delete_sub_admin', json_encode([
            'target_id'   => $id,
            'target_user' => $row->user_id,
        ]));
        
        // Notify user
        $this->notify_user($row->user_id, 'sub_admin_revoked', [
            'message' => 'دسترسی ارشد شما لغو شد',
        ]);
        
        return new WP_REST_Response(['message' => 'کاربر ارشد حذف شد'], 200);
    }
    
    /**
     * GET /sub-admins/logs — activity log with pagination
     */
    public function get_logs($r) {
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admin_logs';
        
        $page = max(1, (int)$r->get_param('page'));
        $per_page = min(50, max(10, (int)($r->get_param('per_page') ?? 20)));
        $user_id = $r->get_param('user_id');
        $offset = ($page - 1) * $per_page;
        
        $where = '';
        $params = [];
        if ($user_id) {
            $where = 'WHERE user_id = %d';
            $params[] = (int)$user_id;
        }
        
        $total = (int)$wpdb->get_var(
            $user_id 
                ? $wpdb->prepare("SELECT COUNT(*) FROM $table $where", ...$params)
                : "SELECT COUNT(*) FROM $table"
        );
        
        $query = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$params));
        
        $result = [];
        foreach ($rows as $row) {
            $user = get_user_by('id', $row->user_id);
            $result[] = [
                'id'         => (int)$row->id,
                'user_id'    => (int)$row->user_id,
                'user_name'  => $user ? $user->display_name : '—',
                'user_email' => $user ? $user->user_email : '—',
                'action'     => $row->action,
                'details'    => json_decode($row->details, true),
                'ip_address' => $row->ip_address,
                'created_at' => $row->created_at,
            ];
        }
        
        return new WP_REST_Response([
            'logs'     => $result,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => ceil($total / $per_page),
        ], 200);
    }
    
    /**
     * GET /my-permissions — get current user's sub-admin permissions
     */
    public function get_my_permissions($r) {
        $uid = $this->get_user_id_from_request($r);
        if (!$uid) {
            return new WP_REST_Response(['message' => 'Unauthorized'], 401);
        }
        
        $user = get_user_by('id', $uid);
        
        // Main admin gets all permissions
        if ($user && user_can($user, 'manage_options')) {
            return new WP_REST_Response([
                'is_main_admin' => true,
                'is_sub_admin'  => false,
                'is_active'     => true,
                'permissions'   => array_keys(self::PERMISSIONS),
                'label'         => 'ادمین اصلی',
            ], 200);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'sub_admins';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d", $uid
        ));
        
        if (!$row) {
            return new WP_REST_Response([
                'is_main_admin' => false,
                'is_sub_admin'  => false,
                'is_active'     => false,
                'permissions'   => [],
                'label'         => null,
            ], 200);
        }
        
        return new WP_REST_Response([
            'is_main_admin' => false,
            'is_sub_admin'  => true,
            'is_active'     => (bool)$row->is_active,
            'permissions'   => $row->is_active ? (json_decode($row->permissions, true) ?: []) : [],
            'label'         => $row->label,
        ], 200);
    }
    
    /**
     * GET /sub-admins/permissions-list — available permissions
     */
    public function get_permissions_list($r) {
        $list = [];
        foreach (self::PERMISSIONS as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }
        return new WP_REST_Response($list, 200);
    }
    
    /**
     * Send notification to user via Pusher
     */
    private function notify_user($user_id, $event, $data) {
        if (!defined('PUSHER_KEY') || !defined('PUSHER_SECRET') || !defined('PUSHER_APP_ID')) {
            return;
        }
        
        $channel = "private-user-{$user_id}";
        $payload = json_encode([
            'event' => $event,
            'data'  => $data,
            'time'  => current_time('mysql'),
        ]);
        
        $pusher_url = "https://api-" . PUSHER_CLUSTER . ".pusher.com/apps/" . PUSHER_APP_ID . "/events";
        
        $body = json_encode([
            'name'    => $event,
            'channel' => $channel,
            'data'    => $payload,
        ]);
        
        $timestamp = time();
        $auth_version = '1.0';
        $body_md5 = md5($body);
        
        $string_to_sign = "POST\n/apps/" . PUSHER_APP_ID . "/events\nauth_key=" . PUSHER_KEY . "&auth_timestamp={$timestamp}&auth_version={$auth_version}&body_md5={$body_md5}";
        $auth_signature = hash_hmac('sha256', $string_to_sign, PUSHER_SECRET);
        
        $url = $pusher_url . "?auth_key=" . PUSHER_KEY . "&auth_timestamp={$timestamp}&auth_version={$auth_version}&body_md5={$body_md5}&auth_signature={$auth_signature}";
        
        wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $body,
            'timeout' => 5,
        ]);
    }
}

// Initialize
AsadMindset_SubAdmin::get_instance();