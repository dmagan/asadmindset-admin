<?php
/**
 * Subscription Module for AsadMindset
 * Handles monthly subscription requests and admin approval
 */

if (!defined('ABSPATH')) {
    exit;
}

class AsadMindset_Subscription {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Create tables on activation
        add_action('init', array($this, 'maybe_create_tables'));
        
        // REST API endpoints
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Create database tables if not exists
     */
    public function maybe_create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'subscriptions';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_tables();
        }
    }
    
    /**
     * Create subscription table
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $sql_subscriptions = "CREATE TABLE $table_subscriptions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            plan_type varchar(20) DEFAULT 'monthly',
            amount decimal(10,2) DEFAULT 0,
            payment_proof varchar(500) DEFAULT NULL,
            tx_hash varchar(255) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            admin_note text DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            approved_by bigint(20) DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_subscriptions);
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        $namespace = 'asadmindset/v1';
        
        // === User Routes ===
        
        // Request subscription (upload payment proof)
        register_rest_route($namespace, '/subscription/request', array(
            'methods' => 'POST',
            'callback' => array($this, 'request_subscription'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Get current subscription status
        register_rest_route($namespace, '/subscription/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_subscription_status'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // Get subscription history
        register_rest_route($namespace, '/subscription/history', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_subscription_history'),
            'permission_callback' => array($this, 'check_user_auth')
        ));
        
        // === Admin Routes ===
        
        // Get all subscription requests
        register_rest_route($namespace, '/admin/subscriptions', array(
            'methods' => 'GET',
            'callback' => array($this, 'admin_get_subscriptions'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Get single subscription detail
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'admin_get_subscription'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Approve subscription
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/approve', array(
            'methods' => 'PUT',
            'callback' => array($this, 'admin_approve_subscription'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Reject subscription
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/reject', array(
            'methods' => 'PUT',
            'callback' => array($this, 'admin_reject_subscription'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));

        // Get subscription stats
        register_rest_route($namespace, '/admin/subscriptions/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'admin_get_stats'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));
        
        // Update status (edit)
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/update-status', array(
            'methods' => 'PUT',
            'callback' => array($this, 'admin_update_status'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));

        // Trash (soft delete)
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/trash', array(
            'methods' => 'PUT',
            'callback' => array($this, 'admin_trash_subscription'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));

        // Restore from trash
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/restore', array(
            'methods' => 'PUT',
            'callback' => array($this, 'admin_restore_subscription'),
            'permission_callback' => array($this, 'check_admin_auth')
        ));

        // Permanent delete
        register_rest_route($namespace, '/admin/subscriptions/(?P<id>\d+)/permanent-delete', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'admin_permanent_delete'),
            'permission_callback' => array($this, 'check_admin_auth')
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
     * Get user ID from request
     */
    private function get_user_id_from_request($request) {
        $token = $this->get_bearer_token($request);
        return $this->validate_jwt_token($token);
    }
    
    // ==========================================
    // User Endpoints
    // ==========================================
    
    /**
     * Request subscription (user submits payment proof)
     */
    public function request_subscription($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        // Check if user has pending request
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE user_id = %d AND status = 'pending' ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        if ($pending) {
            return new WP_Error('pending_exists', 'شما یک درخواست در حال بررسی دارید', array('status' => 400));
        }
        
        // Get request data
        $params = $request->get_json_params();
        $plan_type = isset($params['plan_type']) ? sanitize_text_field($params['plan_type']) : 'monthly';
        $amount = isset($params['amount']) ? floatval($params['amount']) : 0;
        $payment_proof = isset($params['payment_proof']) ? esc_url_raw($params['payment_proof']) : '';
        
        $tx_hash = isset($params['tx_hash']) ? sanitize_text_field($params['tx_hash']) : '';

        if (empty($payment_proof) && empty($tx_hash)) {
            return new WP_Error('no_proof', 'لطفا هش تراکنش یا تصویر رسید پرداخت را وارد کنید', array('status' => 400));
        }

        // بررسی تکراری نبودن هش تراکنش
        if (!empty($tx_hash)) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table_subscriptions WHERE tx_hash = %s",
                $tx_hash
            ));
            if ($existing) {
                return new WP_Error('duplicate_tx', 'این هش تراکنش قبلاً ثبت شده است', array('status' => 400));
            }
        }
        
        // Insert subscription request
        $result = $wpdb->insert($table_subscriptions, array(
            'user_id' => $user_id,
            'plan_type' => $plan_type,
            'amount' => $amount,
            'payment_proof' => $payment_proof,
            'tx_hash' => $tx_hash,
            'status' => 'pending',
            'created_at' => current_time('mysql')
        ));
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در ثبت درخواست', array('status' => 500));
        }
        
        $subscription_id = $wpdb->insert_id;
        
        // Trigger Pusher event for admin notification
        $this->trigger_pusher_event('admin-support', 'new-subscription', array(
            'subscriptionId' => $subscription_id,
            'userId' => $user_id,
            'planType' => $plan_type,
            'amount' => $amount
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'درخواست اشتراک شما ثبت شد و در حال بررسی است',
            'subscriptionId' => $subscription_id
        ));
    }
    
    /**
     * Get current subscription status
     */
    public function get_subscription_status($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        // Get active subscription
        $active = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions 
             WHERE user_id = %d AND status = 'approved' AND expires_at > NOW() 
             ORDER BY expires_at DESC LIMIT 1",
            $user_id
        ));
        
        // Get pending request
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions 
             WHERE user_id = %d AND status = 'pending' 
             ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        // Get last rejected (if any)
        $rejected = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions 
             WHERE user_id = %d AND status = 'rejected' 
             ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        $response = array(
            'hasActiveSubscription' => $active !== null,
            'hasPendingRequest' => $pending !== null,
            'activeSubscription' => null,
            'pendingRequest' => null,
            'lastRejected' => null
        );
        
        if ($active) {
            $response['activeSubscription'] = array(
                'id' => (int) $active->id,
                'planType' => $active->plan_type,
                'startedAt' => $active->started_at,
                'expiresAt' => $active->expires_at,
                'daysRemaining' => max(0, floor((strtotime($active->expires_at) - time()) / 86400))
            );
        }
        
        if ($pending) {
            $response['pendingRequest'] = array(
                'id' => (int) $pending->id,
                'planType' => $pending->plan_type,
                'amount' => floatval($pending->amount),
                'createdAt' => $pending->created_at
            );
        }
        
        if ($rejected && !$active && !$pending) {
            $response['lastRejected'] = array(
                'id' => (int) $rejected->id,
                'adminNote' => $rejected->admin_note,
                'rejectedAt' => $rejected->updated_at
            );
        }
        
        return rest_ensure_response($response);
    }
    
    /**
     * Get subscription history
     */
    public function get_subscription_history($request) {
        global $wpdb;
        
        $user_id = $this->get_user_id_from_request($request);
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE user_id = %d ORDER BY id DESC LIMIT 20",
            $user_id
        ));
        
        $history = array();
        foreach ($subscriptions as $sub) {
            $history[] = array(
                'id' => (int) $sub->id,
                'planType' => $sub->plan_type,
                'amount' => floatval($sub->amount),
                'status' => $sub->status,
                'adminNote' => $sub->admin_note,
                'startedAt' => $sub->started_at,
                'expiresAt' => $sub->expires_at,
                'createdAt' => $sub->created_at
            );
        }
        
        return rest_ensure_response($history);
    }
    
    // ==========================================
    // Admin Endpoints
    // ==========================================
    
    /**
     * Get all subscription requests (admin)
     * ✅ UPDATED: Now supports 'trashed' filter and excludes trashed from default view
     */
    public function admin_get_subscriptions($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $status = $request->get_param('status');
        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = max(1, min(50, intval($request->get_param('per_page') ?: 20)));
        $offset = ($page - 1) * $per_page;
        
        // Build query
        $where = "1=1";
        
        // ✅ FIX: Handle 'trashed' status and exclude trashed from normal views
        if ($status === 'trashed') {
            $where .= " AND s.status = 'trashed'";
        } elseif ($status && in_array($status, array('pending', 'approved', 'rejected'))) {
            $where .= $wpdb->prepare(" AND s.status = %s", $status);
        } else {
            // Default (all): exclude trashed items
            $where .= " AND s.status != 'trashed'";
        }
        
        // Get total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions s WHERE $where");
        
        // Get subscriptions with user info
        $subscriptions = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.display_name as user_name, u.user_email as user_email
             FROM $table_subscriptions s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE $where
             ORDER BY 
                CASE s.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'approved' THEN 2 
                    WHEN 'rejected' THEN 3
                    WHEN 'trashed' THEN 4
                    ELSE 5 
                END,
                s.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        $result = array();
        foreach ($subscriptions as $sub) {
            $result[] = array(
                'id' => (int) $sub->id,
                'userId' => (int) $sub->user_id,
                'userName' => $sub->user_name ?: 'کاربر',
                'userEmail' => $sub->user_email,
                'planType' => $sub->plan_type,
                'amount' => floatval($sub->amount),
                'paymentProof' => $sub->payment_proof,
                'status' => $sub->status,
                'adminNote' => $sub->admin_note,
                'startedAt' => $sub->started_at,
                'expiresAt' => $sub->expires_at,
                'approvedBy' => $sub->approved_by ? (int) $sub->approved_by : null,
                'approvedAt' => $sub->approved_at,
                'createdAt' => $sub->created_at,
                'updatedAt' => $sub->updated_at
            );
        }
        
        return rest_ensure_response(array(
            'subscriptions' => $result,
            'total' => (int) $total,
            'page' => $page,
            'perPage' => $per_page,
            'totalPages' => ceil($total / $per_page)
        ));
    }
    
    /**
     * Get single subscription detail (admin)
     */
    public function admin_get_subscription($request) {
        global $wpdb;
        
        $subscription_id = (int) $request->get_param('id');
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, u.display_name as user_name, u.user_email as user_email
             FROM $table_subscriptions s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        return rest_ensure_response(array(
            'id' => (int) $subscription->id,
            'userId' => (int) $subscription->user_id,
            'userName' => $subscription->user_name ?: 'کاربر',
            'userEmail' => $subscription->user_email,
            'planType' => $subscription->plan_type,
            'amount' => floatval($subscription->amount),
            'paymentProof' => $subscription->payment_proof,
            'status' => $subscription->status,
            'adminNote' => $subscription->admin_note,
            'startedAt' => $subscription->started_at,
            'expiresAt' => $subscription->expires_at,
            'approvedBy' => $subscription->approved_by ? (int) $subscription->approved_by : null,
            'approvedAt' => $subscription->approved_at,
            'createdAt' => $subscription->created_at,
            'updatedAt' => $subscription->updated_at
        ));
    }
    
    /**
     * Approve subscription (admin)
     */
    public function admin_approve_subscription($request) {
        global $wpdb;
        
        $subscription_id = (int) $request->get_param('id');
        $admin_id = $this->get_user_id_from_request($request);
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        $params = $request->get_json_params();
        $duration_days = isset($params['duration_days']) ? intval($params['duration_days']) : 30;
        $admin_note = isset($params['admin_note']) ? sanitize_textarea_field($params['admin_note']) : '';
        
        // Get subscription
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        if ($subscription->status !== 'pending') {
            return new WP_Error('invalid_status', 'این درخواست قبلا بررسی شده است', array('status' => 400));
        }
        
        // Calculate dates
        $started_at = current_time('mysql');
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));
        
        // Update subscription
        $result = $wpdb->update(
            $table_subscriptions,
            array(
                'status' => 'approved',
                'admin_note' => $admin_note,
                'started_at' => $started_at,
                'expires_at' => $expires_at,
                'approved_by' => $admin_id,
                'approved_at' => current_time('mysql')
            ),
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در بروزرسانی', array('status' => 500));
        }
        
        // Notify user via Pusher
        $this->trigger_pusher_event('user-' . $subscription->user_id, 'subscription-approved', array(
            'subscriptionId' => $subscription_id,
            'expiresAt' => $expires_at,
            'message' => 'اشتراک شما تایید شد!'
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'اشتراک با موفقیت تایید شد',
            'subscription' => array(
                'id' => $subscription_id,
                'status' => 'approved',
                'startedAt' => $started_at,
                'expiresAt' => $expires_at
            )
        ));
    }
    
    /**
     * Reject subscription (admin)
     */
    public function admin_reject_subscription($request) {
        global $wpdb;
        
        $subscription_id = (int) $request->get_param('id');
        $admin_id = $this->get_user_id_from_request($request);
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        $params = $request->get_json_params();
        $admin_note = isset($params['admin_note']) ? sanitize_textarea_field($params['admin_note']) : 'درخواست رد شد';
        
        // Get subscription
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        if ($subscription->status !== 'pending') {
            return new WP_Error('invalid_status', 'این درخواست قبلا بررسی شده است', array('status' => 400));
        }
        
        // Update subscription
        $result = $wpdb->update(
            $table_subscriptions,
            array(
                'status' => 'rejected',
                'admin_note' => $admin_note,
                'approved_by' => $admin_id,
                'approved_at' => current_time('mysql')
            ),
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در بروزرسانی', array('status' => 500));
        }
        
        // Notify user via Pusher
        $this->trigger_pusher_event('user-' . $subscription->user_id, 'subscription-rejected', array(
            'subscriptionId' => $subscription_id,
            'reason' => $admin_note,
            'message' => 'متاسفانه درخواست اشتراک شما رد شد'
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'درخواست رد شد',
            'subscription' => array(
                'id' => $subscription_id,
                'status' => 'rejected',
                'adminNote' => $admin_note
            )
        ));
    }
    
    /**
     * Get subscription stats (admin)
     */
    public function admin_get_stats($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        
        // ✅ Exclude trashed from total count
        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions WHERE status != 'trashed'");
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions WHERE status = 'pending'");
        $approved = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions WHERE status = 'approved'");
        $rejected = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions WHERE status = 'rejected'");
        $active = $wpdb->get_var("SELECT COUNT(*) FROM $table_subscriptions WHERE status = 'approved' AND expires_at > NOW()");
        
        // Today's stats
        $today = date('Y-m-d');
        $today_requests = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_subscriptions WHERE DATE(created_at) = %s AND status != 'trashed'",
            $today
        ));
        
        // This month revenue
        $month_start = date('Y-m-01');
        $month_revenue = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM $table_subscriptions 
             WHERE status = 'approved' AND approved_at >= %s",
            $month_start
        ));
        
        return rest_ensure_response(array(
            'total' => (int) $total,
            'pending' => (int) $pending,
            'approved' => (int) $approved,
            'rejected' => (int) $rejected,
            'active' => (int) $active,
            'todayRequests' => (int) $today_requests,
            'monthRevenue' => floatval($month_revenue)
        ));
    }
    
    // ==========================================
    // ✅ NEW: Edit, Trash, Restore, Permanent Delete
    // ==========================================
    
    /**
     * Update subscription status (ویرایش وضعیت)
     * Allows changing between: pending, approved, rejected
     */
    public function admin_update_status($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $subscription_id = (int) $request->get_param('id');
        $admin_id = $this->get_user_id_from_request($request);
        
        $params = $request->get_json_params();
        $new_status = isset($params['status']) ? sanitize_text_field($params['status']) : '';
        $admin_note = isset($params['admin_note']) ? sanitize_textarea_field($params['admin_note']) : '';
        $duration_days = isset($params['duration_days']) ? intval($params['duration_days']) : 30;
        
        // Validate status
        $allowed_statuses = array('pending', 'approved', 'rejected');
        if (!in_array($new_status, $allowed_statuses)) {
            return new WP_Error('invalid_status', 'وضعیت نامعتبر', array('status' => 400));
        }
        
        // Get current subscription
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        // Can't edit trashed items
        if ($subscription->status === 'trashed') {
            return new WP_Error('is_trashed', 'ابتدا باید از سطل آشغال بازیابی کنید', array('status' => 400));
        }
        
        // Build update data
        $update_data = array(
            'status'      => $new_status,
            'admin_note'  => $admin_note,
            'approved_by' => $admin_id,
            'updated_at'  => current_time('mysql'),
        );
        
        // If changing to approved, set dates
        if ($new_status === 'approved') {
            $update_data['approved_at'] = current_time('mysql');
            $update_data['started_at']  = current_time('mysql');
            $update_data['expires_at']  = date('Y-m-d H:i:s', strtotime("+{$duration_days} days"));
        }
        
        // If changing FROM approved to something else, clear dates
        if ($subscription->status === 'approved' && $new_status !== 'approved') {
            $update_data['started_at']  = null;
            $update_data['expires_at']  = null;
        }
        
        $result = $wpdb->update(
            $table_subscriptions,
            $update_data,
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در بروزرسانی', array('status' => 500));
        }
        
        // Notify user
        if ($new_status === 'approved') {
            $this->trigger_pusher_event('user-' . $subscription->user_id, 'subscription-approved', array(
                'subscriptionId' => $subscription_id,
                'expiresAt' => $update_data['expires_at'],
                'message' => 'اشتراک شما تایید شد!'
            ));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'وضعیت با موفقیت تغییر کرد',
            'status'  => $new_status
        ));
    }
    
    /**
     * Soft delete - move to trash (انتقال به سطل آشغال)
     */
    public function admin_trash_subscription($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $subscription_id = (int) $request->get_param('id');
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        if ($subscription->status === 'trashed') {
            return new WP_Error('already_trashed', 'این مورد قبلاً در سطل آشغال است', array('status' => 400));
        }
        
        // Save previous status in admin_note for restore
        $previous_info = 'previous_status:' . $subscription->status;
        if ($subscription->admin_note) {
            $previous_info .= '|previous_note:' . $subscription->admin_note;
        }
        
        $result = $wpdb->update(
            $table_subscriptions,
            array(
                'status'     => 'trashed',
                'admin_note' => $previous_info,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در انتقال به سطل آشغال', array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'به سطل آشغال منتقل شد'
        ));
    }
    
    /**
     * Restore from trash (بازیابی از سطل آشغال)
     */
    public function admin_restore_subscription($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $subscription_id = (int) $request->get_param('id');
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        if ($subscription->status !== 'trashed') {
            return new WP_Error('not_trashed', 'این مورد در سطل آشغال نیست', array('status' => 400));
        }
        
        // Extract previous status from admin_note
        $previous_status = 'pending';
        $previous_note = '';
        
        if ($subscription->admin_note && strpos($subscription->admin_note, 'previous_status:') === 0) {
            $parts = explode('|', $subscription->admin_note);
            foreach ($parts as $part) {
                if (strpos($part, 'previous_status:') === 0) {
                    $previous_status = str_replace('previous_status:', '', $part);
                }
                if (strpos($part, 'previous_note:') === 0) {
                    $previous_note = str_replace('previous_note:', '', $part);
                }
            }
        }
        
        // Validate previous status
        if (!in_array($previous_status, array('pending', 'approved', 'rejected'))) {
            $previous_status = 'pending';
        }
        
        $result = $wpdb->update(
            $table_subscriptions,
            array(
                'status'     => $previous_status,
                'admin_note' => $previous_note,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $subscription_id)
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در بازیابی', array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'با موفقیت بازیابی شد',
            'restored_status' => $previous_status
        ));
    }
    
    /**
     * Permanent delete (حذف دائمی - فقط از سطل آشغال)
     */
    public function admin_permanent_delete($request) {
        global $wpdb;
        
        $table_subscriptions = $wpdb->prefix . 'subscriptions';
        $subscription_id = (int) $request->get_param('id');
        
        $subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_subscriptions WHERE id = %d",
            $subscription_id
        ));
        
        if (!$subscription) {
            return new WP_Error('not_found', 'اشتراک یافت نشد', array('status' => 404));
        }
        
        // Only allow permanent delete from trash
        if ($subscription->status !== 'trashed') {
            return new WP_Error('not_trashed', 'فقط موارد در سطل آشغال قابل حذف دائمی هستند', array('status' => 400));
        }
        
        $result = $wpdb->delete(
            $table_subscriptions,
            array('id' => $subscription_id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'خطا در حذف دائمی', array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'به طور دائمی حذف شد'
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
        
        $auth_timestamp = time();
        $auth_version = '1.0';
        $body_md5 = md5($body);
        
        $string_to_sign = "POST\n/apps/" . PUSHER_APP_ID . "/events\n" .
            "auth_key=" . PUSHER_KEY .
            "&auth_timestamp=" . $auth_timestamp .
            "&auth_version=" . $auth_version .
            "&body_md5=" . $body_md5;
        
        $auth_signature = hash_hmac('sha256', $string_to_sign, PUSHER_SECRET);
        
        $query_params = http_build_query(array(
            'auth_key' => PUSHER_KEY,
            'auth_timestamp' => $auth_timestamp,
            'auth_version' => $auth_version,
            'body_md5' => $body_md5,
            'auth_signature' => $auth_signature
        ));
        
        wp_remote_post($url . '?' . $query_params, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json'),
            'body' => $body,
            'timeout' => 5
        ));
    }
}

// Initialize
AsadMindset_Subscription::get_instance();