<?php
/**
 * Subscription Module for AsadMindset
 * Handles subscription requests, renewals, discount codes, reminders, admin approval
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
        add_action('init', array($this, 'maybe_create_tables'));
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('asadmindset_subscription_cron', array($this, 'run_cron_tasks'));
        if (!wp_next_scheduled('asadmindset_subscription_cron')) {
            wp_schedule_event(time(), 'hourly', 'asadmindset_subscription_cron');
        }
    }
    
    public function maybe_create_tables() {
        global $wpdb;
        $table_subs = $wpdb->prefix . 'subscriptions';
        $table_codes = $wpdb->prefix . 'discount_codes';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_subs'") != $table_subs) {
            $this->create_tables();
        } else {
            $this->maybe_add_columns();
        }
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_codes'") != $table_codes) {
            $this->create_discount_tables();
        } else {
            // Add status column to discount_codes if missing
            $dc_cols = $wpdb->get_results("SHOW COLUMNS FROM $table_codes LIKE 'status'");
            if (empty($dc_cols)) {
                $wpdb->query("ALTER TABLE $table_codes ADD COLUMN status varchar(20) DEFAULT 'active' AFTER is_active");
            }
        }
    }
    
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $t = $wpdb->prefix . 'subscriptions';
        $sql = "CREATE TABLE $t (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            subscription_number varchar(20) DEFAULT NULL,
            user_id bigint(20) NOT NULL,
            plan_type varchar(20) DEFAULT 'monthly',
            amount decimal(10,2) DEFAULT 0,
            original_amount decimal(10,2) DEFAULT 0,
            discount_code varchar(50) DEFAULT NULL,
            discount_amount decimal(10,2) DEFAULT 0,
            discount_percent int DEFAULT 0,
            payment_proof varchar(500) DEFAULT NULL,
            tx_hash varchar(255) DEFAULT NULL,
            network varchar(20) DEFAULT 'TRC20',
            status varchar(20) DEFAULT 'pending',
            admin_note text DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            approved_by bigint(20) DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            is_manual tinyint(1) DEFAULT 0,
            is_renewal tinyint(1) DEFAULT 0,
            renewed_from bigint(20) DEFAULT NULL,
            exclude_from_revenue tinyint(1) DEFAULT 0,
            cancelled_at datetime DEFAULT NULL,
            reminder_7d_sent tinyint(1) DEFAULT 0,
            reminder_2d_sent tinyint(1) DEFAULT 0,
            reminder_1d_sent tinyint(1) DEFAULT 0,
            winback_1d_sent tinyint(1) DEFAULT 0,
            winback_3d_sent tinyint(1) DEFAULT 0,
            reminder_clicked tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY is_renewal (is_renewal),
            KEY discount_code (discount_code)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function create_discount_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $t1 = $wpdb->prefix . 'discount_codes';
        $sql1 = "CREATE TABLE $t1 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            description text DEFAULT NULL,
            discount_type varchar(20) DEFAULT 'percent',
            discount_value decimal(10,2) DEFAULT 0,
            max_uses int DEFAULT 0,
            used_count int DEFAULT 0,
            min_months int DEFAULT 1,
            max_months int DEFAULT 12,
            per_user_limit int DEFAULT 1,
            valid_from datetime DEFAULT NULL,
            valid_until datetime DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            status varchar(20) DEFAULT 'active',
            created_by bigint(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";
        $t2 = $wpdb->prefix . 'discount_usage';
        $sql2 = "CREATE TABLE $t2 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            discount_code_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            subscription_id bigint(20) DEFAULT NULL,
            original_amount decimal(10,2) DEFAULT 0,
            discount_amount decimal(10,2) DEFAULT 0,
            final_amount decimal(10,2) DEFAULT 0,
            used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY discount_code_id (discount_code_id),
            KEY user_id (user_id)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
    }
    
    private function maybe_add_columns() {
        global $wpdb;
        $table = $wpdb->prefix . 'subscriptions';
        $cols = array(
            'subscription_number' => "varchar(20) DEFAULT NULL AFTER id",
            'original_amount' => "decimal(10,2) DEFAULT 0 AFTER amount",
            'discount_code' => "varchar(50) DEFAULT NULL AFTER original_amount",
            'discount_amount' => "decimal(10,2) DEFAULT 0 AFTER discount_code",
            'discount_percent' => "int DEFAULT 0 AFTER discount_amount",
            'network' => "varchar(20) DEFAULT 'TRC20' AFTER tx_hash",
            'is_manual' => "tinyint(1) DEFAULT 0 AFTER approved_at",
            'is_renewal' => "tinyint(1) DEFAULT 0 AFTER is_manual",
            'renewed_from' => "bigint(20) DEFAULT NULL AFTER is_renewal",
            'exclude_from_revenue' => "tinyint(1) DEFAULT 0 AFTER renewed_from",
            'cancelled_at' => "datetime DEFAULT NULL AFTER exclude_from_revenue",
            'reminder_7d_sent' => "tinyint(1) DEFAULT 0 AFTER cancelled_at",
            'reminder_2d_sent' => "tinyint(1) DEFAULT 0 AFTER reminder_7d_sent",
            'reminder_1d_sent' => "tinyint(1) DEFAULT 0 AFTER reminder_2d_sent",
            'winback_1d_sent' => "tinyint(1) DEFAULT 0 AFTER reminder_1d_sent",
            'winback_3d_sent' => "tinyint(1) DEFAULT 0 AFTER winback_1d_sent",
            'reminder_clicked' => "tinyint(1) DEFAULT 0 AFTER winback_3d_sent",
        );
        foreach ($cols as $name => $def) {
            $c = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE '$name'");
            if (empty($c)) {
                $wpdb->query("ALTER TABLE $table ADD COLUMN $name $def");
            }
        }
        $wpdb->query("UPDATE $table SET subscription_number = CONCAT('SUB-', LPAD(id, 4, '0')) WHERE subscription_number IS NULL OR subscription_number = ''");
    }
    
    private function gen_sub_number($id) {
        return 'SUB-' . str_pad($id, 4, '0', STR_PAD_LEFT);
    }
    
    public function register_routes() {
        $ns = 'asadmindset/v1';
        // User
        register_rest_route($ns, '/subscription/request', array('methods'=>'POST','callback'=>array($this,'request_subscription'),'permission_callback'=>array($this,'check_user_auth')));
        register_rest_route($ns, '/subscription/status', array('methods'=>'GET','callback'=>array($this,'get_subscription_status'),'permission_callback'=>array($this,'check_user_auth')));
        register_rest_route($ns, '/subscription/history', array('methods'=>'GET','callback'=>array($this,'get_subscription_history'),'permission_callback'=>array($this,'check_user_auth')));
        register_rest_route($ns, '/subscription/validate-discount', array('methods'=>'POST','callback'=>array($this,'validate_discount_code'),'permission_callback'=>array($this,'check_user_auth')));
        register_rest_route($ns, '/subscription/reminder-click/(?P<id>\d+)', array('methods'=>'PUT','callback'=>array($this,'track_reminder_click'),'permission_callback'=>array($this,'check_user_auth')));
        // Admin subs (subscriptions permission)
        register_rest_route($ns, '/admin/subscriptions', array('methods'=>'GET','callback'=>array($this,'admin_get_subscriptions'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)', array('methods'=>'GET','callback'=>array($this,'admin_get_subscription'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/stats', array('methods'=>'GET','callback'=>array($this,'admin_get_stats'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/manual', array('methods'=>'POST','callback'=>array($this,'admin_manual_subscription'),'permission_callback'=>array($this,'check_admin_auth_manual_order')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/approve', array('methods'=>'PUT','callback'=>array($this,'admin_approve_subscription'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/reject', array('methods'=>'PUT','callback'=>array($this,'admin_reject_subscription'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/update-status', array('methods'=>'PUT','callback'=>array($this,'admin_update_status'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/notify', array('methods'=>'POST','callback'=>array($this,'admin_notify_user'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/trash', array('methods'=>'PUT','callback'=>array($this,'admin_trash_subscription'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/restore', array('methods'=>'PUT','callback'=>array($this,'admin_restore_subscription'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        register_rest_route($ns, '/admin/subscriptions/(?P<id>\d+)/permanent-delete', array('methods'=>'DELETE','callback'=>array($this,'admin_permanent_delete'),'permission_callback'=>array($this,'check_admin_auth_subscriptions')));
        // Admin discount codes (discounts permission)
        register_rest_route($ns, '/admin/discount-codes', array('methods'=>'GET','callback'=>array($this,'admin_get_discount_codes'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes', array('methods'=>'POST','callback'=>array($this,'admin_create_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)', array('methods'=>'PUT','callback'=>array($this,'admin_update_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)', array('methods'=>'DELETE','callback'=>array($this,'admin_delete_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)/trash', array('methods'=>'PUT','callback'=>array($this,'admin_trash_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)/restore', array('methods'=>'PUT','callback'=>array($this,'admin_restore_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)/permanent-delete', array('methods'=>'DELETE','callback'=>array($this,'admin_permanent_delete_discount_code'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
        register_rest_route($ns, '/admin/discount-codes/(?P<id>\d+)/stats', array('methods'=>'GET','callback'=>array($this,'admin_get_discount_stats'),'permission_callback'=>array($this,'check_admin_auth_discounts')));
    }
    
    // Auth
    public function check_user_auth($r) { $t=$this->get_bearer_token($r); if(!$t) return false; return $this->validate_jwt_token($t)!==false; }
    public function check_admin_auth($r) { $s=AsadMindset_Support::get_instance(); return $s->check_admin_auth($r); }
    public function check_admin_auth_subscriptions($r) { $s=AsadMindset_Support::get_instance(); return $s->check_admin_auth_subscriptions($r); }
    public function check_admin_auth_discounts($r) { $s=AsadMindset_Support::get_instance(); return $s->check_admin_auth_discounts($r); }
    public function check_admin_auth_manual_order($r) { $s=AsadMindset_Support::get_instance(); return $s->check_admin_auth_manual_order($r); }
    private function get_bearer_token($r) { $h=$r->get_header('Authorization'); if($h && preg_match('/Bearer\s(\S+)/',$h,$m)) return $m[1]; return null; }
    private function validate_jwt_token($token) { $sk=defined('JWT_AUTH_SECRET_KEY')?JWT_AUTH_SECRET_KEY:false; if(!$sk) return false; try { $p=explode('.',$token); if(count($p)!==3) return false; $pl=json_decode(base64_decode($p[1]),true); if(!$pl||!isset($pl['data']['user']['id'])) return false; if(isset($pl['exp'])&&$pl['exp']<time()) return false; return $pl['data']['user']['id']; } catch(Exception $e) { return false; } }
    private function get_uid($r) { return $this->validate_jwt_token($this->get_bearer_token($r)); }
    
    private function get_active_sub($uid) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND status='approved' AND expires_at>NOW() ORDER BY expires_at DESC LIMIT 1",$uid));
    }
    
    // ========== USER ENDPOINTS ==========
    
    public function request_subscription($request) {
        global $wpdb;
        $uid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions';
        $pending=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND status='pending' ORDER BY id DESC LIMIT 1",$uid));
        if($pending) return new WP_Error('pending_exists','شما یک درخواست در حال بررسی دارید',array('status'=>400));
        $p=$request->get_json_params();
        $plan_type=sanitize_text_field($p['plan_type']??'monthly');
        $amount=floatval($p['amount']??0);
        $proof=esc_url_raw($p['payment_proof']??'');
        $tx=sanitize_text_field($p['tx_hash']??'');
        $network=sanitize_text_field($p['network']??'TRC20');
        $dc_input=strtoupper(sanitize_text_field($p['discount_code']??''));
        $is_renewal=(bool)($p['is_renewal']??false);
        $renewed_from=isset($p['renewed_from'])?intval($p['renewed_from']):null;
        if(empty($proof)&&empty($tx)) return new WP_Error('no_proof','لطفا هش تراکنش یا تصویر رسید پرداخت را وارد کنید',array('status'=>400));
        if(!empty($tx)){$ex=$wpdb->get_row($wpdb->prepare("SELECT id FROM $t WHERE tx_hash=%s AND status IN ('pending','approved')",$tx)); if($ex) return new WP_Error('duplicate_tx','این هش تراکنش قبلاً ثبت شده است',array('status'=>400));}
        $orig=$amount; $da=0; $dp=0; $vc='';
        if(!empty($dc_input)){
            $dr=$this->apply_discount($dc_input,$uid,$amount,$plan_type);
            if(is_wp_error($dr)) return $dr;
            $amount=$dr['final_amount']; $da=$dr['discount_amount']; $dp=$dr['discount_percent']; $vc=$dr['code'];
        }
        if(!$is_renewal){$act=$this->get_active_sub($uid); if($act){$is_renewal=true;$renewed_from=(int)$act->id;}}
        $wpdb->insert($t,array('user_id'=>$uid,'plan_type'=>$plan_type,'amount'=>$amount,'original_amount'=>$orig,'discount_code'=>$vc?:null,'discount_amount'=>$da,'discount_percent'=>$dp,'payment_proof'=>$proof,'tx_hash'=>$tx,'network'=>$network,'status'=>'pending','is_manual'=>0,'is_renewal'=>$is_renewal?1:0,'renewed_from'=>$renewed_from,'exclude_from_revenue'=>0,'created_at'=>current_time('mysql')));
        $sid=$wpdb->insert_id; $sn=$this->gen_sub_number($sid);
        $wpdb->update($t,array('subscription_number'=>$sn),array('id'=>$sid));
        if(!empty($vc)) $this->record_discount_usage($vc,$uid,$sid,$orig,$da,$amount);
        $this->trigger_pusher('admin-support','new-subscription',array('subscriptionId'=>$sid,'userId'=>$uid,'planType'=>$plan_type,'amount'=>$amount,'network'=>$network,'isRenewal'=>$is_renewal));
        return rest_ensure_response(array('success'=>true,'message'=>'درخواست اشتراک شما ثبت شد و در حال بررسی است','subscriptionId'=>$sid,'subscriptionNumber'=>$sn,'finalAmount'=>$amount,'discountAmount'=>$da));
    }
    
    public function get_subscription_status($request) {
        global $wpdb; $uid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions';
        $active=$this->get_active_sub($uid);
        $pending=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND status='pending' ORDER BY id DESC LIMIT 1",$uid));
        $rejected=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND status='rejected' ORDER BY id DESC LIMIT 1",$uid));
        $res=array('hasActiveSubscription'=>$active!==null,'hasPendingRequest'=>$pending!==null,'activeSubscription'=>null,'pendingRequest'=>null,'lastRejected'=>null);
        if($active){
            $dr=max(0,floor((strtotime($active->expires_at)-time())/86400));
            $res['activeSubscription']=array('id'=>(int)$active->id,'subscriptionNumber'=>$active->subscription_number,'planType'=>$active->plan_type,'startedAt'=>$active->started_at,'expiresAt'=>$active->expires_at,'daysRemaining'=>$dr,'isRenewal'=>(bool)intval($active->is_renewal??0),'showRenewalButton'=>$dr<=7);
        }
        if($pending) $res['pendingRequest']=array('id'=>(int)$pending->id,'planType'=>$pending->plan_type,'amount'=>floatval($pending->amount),'createdAt'=>$pending->created_at);
        if($rejected&&!$active&&!$pending) $res['lastRejected']=array('id'=>(int)$rejected->id,'adminNote'=>$rejected->admin_note,'rejectedAt'=>$rejected->updated_at);
        return rest_ensure_response($res);
    }
    
    public function get_subscription_history($request) {
        global $wpdb; $uid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions';
        $subs=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id=%d AND status!='trashed' ORDER BY id DESC LIMIT 20",$uid));
        $h=array();
        foreach($subs as $s) $h[]=array('id'=>(int)$s->id,'subscriptionNumber'=>$s->subscription_number??null,'planType'=>$s->plan_type,'amount'=>floatval($s->amount),'originalAmount'=>floatval($s->original_amount??$s->amount),'discountCode'=>$s->discount_code??null,'discountAmount'=>floatval($s->discount_amount??0),'network'=>$s->network??'TRC20','status'=>$s->status,'adminNote'=>$s->admin_note,'startedAt'=>$s->started_at,'expiresAt'=>$s->expires_at,'isRenewal'=>(bool)intval($s->is_renewal??0),'createdAt'=>$s->created_at);
        return rest_ensure_response($h);
    }
    
    // Discount validation
    public function validate_discount_code($request) {
        $uid=$this->get_uid($request); $p=$request->get_json_params();
        $code=strtoupper(sanitize_text_field($p['code']??'')); $amt=floatval($p['amount']??0); $pt=sanitize_text_field($p['plan_type']??'');
        $r=$this->apply_discount($code,$uid,$amt,$pt);
        if(is_wp_error($r)) return $r;
        return rest_ensure_response(array('valid'=>true,'code'=>$r['code'],'discountPercent'=>$r['discount_percent'],'discountAmount'=>$r['discount_amount'],'finalAmount'=>$r['final_amount'],'description'=>$r['description']));
    }
    
    private function apply_discount($code,$uid,$amount,$plan_type) {
        global $wpdb; $tc=$wpdb->prefix.'discount_codes'; $tu=$wpdb->prefix.'discount_usage';
        $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM $tc WHERE code=%s AND is_active=1 AND (status IS NULL OR status!='trashed')",$code));
        if(!$d) return new WP_Error('invalid_code','کد تخفیف نامعتبر است',array('status'=>400));
        $now=current_time('mysql');
        if($d->valid_from&&$now<$d->valid_from) return new WP_Error('code_not_started','کد تخفیف هنوز فعال نشده',array('status'=>400));
        if($d->valid_until&&$now>$d->valid_until) return new WP_Error('code_expired','کد تخفیف منقضی شده',array('status'=>400));
        if($d->max_uses>0&&$d->used_count>=$d->max_uses) return new WP_Error('code_exhausted','ظرفیت استفاده تکمیل شده',array('status'=>400));
        if($d->per_user_limit>0){$ts=$wpdb->prefix.'subscriptions';$uu=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tu du INNER JOIN $ts s ON du.subscription_id=s.id WHERE du.discount_code_id=%d AND du.user_id=%d AND s.status IN ('pending','approved')",$d->id,$uid)); if($uu>=$d->per_user_limit) return new WP_Error('user_limit','شما قبلاً از این کد استفاده کرده‌اید',array('status'=>400));}
        $months=1; if(preg_match('/(\d+)/',$plan_type,$m)) $months=intval($m[1]);
        if($months<$d->min_months||$months>$d->max_months) return new WP_Error('plan_not_eligible',"این کد برای {$d->min_months} تا {$d->max_months} ماهه معتبر است",array('status'=>400));
        $da=0;$dp=0;
        if($d->discount_type==='percent'){$dp=intval($d->discount_value);$da=round($amount*$dp/100,2);}else{$da=min(floatval($d->discount_value),$amount);if($amount>0)$dp=round($da/$amount*100);}
        return array('code'=>$code,'discount_percent'=>$dp,'discount_amount'=>$da,'final_amount'=>max(0,$amount-$da),'description'=>$d->description,'discount_id'=>$d->id);
    }
    
    private function record_discount_usage($code,$uid,$sid,$orig,$disc,$final) {
        global $wpdb; $tc=$wpdb->prefix.'discount_codes'; $tu=$wpdb->prefix.'discount_usage';
        $d=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tc WHERE code=%s",$code));
        if(!$d) return;
        $wpdb->insert($tu,array('discount_code_id'=>$d->id,'user_id'=>$uid,'subscription_id'=>$sid,'original_amount'=>$orig,'discount_amount'=>$disc,'final_amount'=>$final,'used_at'=>current_time('mysql')));
        $this->sync_discount_used_count($d->id);
    }
    
    private function revert_discount_usage($code,$uid,$sid) {
        global $wpdb; $tc=$wpdb->prefix.'discount_codes'; $tu=$wpdb->prefix.'discount_usage';
        $d=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tc WHERE code=%s",$code));
        if(!$d) return;
        $wpdb->delete($tu,array('discount_code_id'=>$d->id,'user_id'=>$uid,'subscription_id'=>$sid));
        $this->sync_discount_used_count($d->id);
    }
    
    private function sync_discount_used_count($discount_id) {
        global $wpdb; $tc=$wpdb->prefix.'discount_codes'; $tu=$wpdb->prefix.'discount_usage'; $ts=$wpdb->prefix.'subscriptions';
        $cnt=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tu du INNER JOIN $ts s ON du.subscription_id=s.id WHERE du.discount_code_id=%d AND s.status IN ('pending','approved')",$discount_id));
        $wpdb->update($tc,array('used_count'=>(int)$cnt),array('id'=>$discount_id));
    }
    
    private function sync_sub_discount($sub) {
        if(!empty($sub->discount_code)) {
            global $wpdb; $tc=$wpdb->prefix.'discount_codes';
            $d=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tc WHERE code=%s",$sub->discount_code));
            if($d) $this->sync_discount_used_count($d->id);
        }
    }
    
    public function track_reminder_click($request) {
        global $wpdb; $uid=$this->get_uid($request); $sid=(int)$request->get_param('id'); $t=$wpdb->prefix.'subscriptions';
        $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND user_id=%d",$sid,$uid));
        if(!$s) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        $wpdb->update($t,array('reminder_clicked'=>1),array('id'=>$sid));
        return rest_ensure_response(array('success'=>true));
    }
    
    // ========== ADMIN ENDPOINTS ==========
    
    public function admin_manual_subscription($request) {
        global $wpdb; $aid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions'; $p=$request->get_json_params();
        $email=sanitize_email($p['email']??''); $plan=sanitize_text_field($p['plan_type']??'1_month');
        $days=intval($p['duration_days']??30); $amt=floatval($p['amount']??0);
        $excl=(bool)($p['exclude_from_revenue']??false); $note=sanitize_textarea_field($p['admin_note']??'');
        if(empty($email)||!is_email($email)) return new WP_Error('invalid_email','ایمیل نامعتبر',array('status'=>400));
        $user=get_user_by('email',$email);
        if(!$user) return new WP_Error('user_not_found','کاربری با این ایمیل یافت نشد. ابتدا باید ثبت‌نام کند.',array('status'=>404));
        $active=$this->get_active_sub($user->ID); $ren=false; $rf=null;
        if($active){$started_at=$active->expires_at;$expires_at=date('Y-m-d H:i:s',strtotime($active->expires_at." +{$days} days"));$ren=true;$rf=(int)$active->id;}
        else{$started_at=current_time('mysql');$expires_at=date('Y-m-d H:i:s',strtotime("+{$days} days"));}
        $wpdb->insert($t,array('user_id'=>$user->ID,'plan_type'=>$plan,'amount'=>$amt,'original_amount'=>$amt,'status'=>'approved','admin_note'=>$note,'started_at'=>$started_at,'expires_at'=>$expires_at,'approved_by'=>$aid,'approved_at'=>current_time('mysql'),'is_manual'=>1,'is_renewal'=>$ren?1:0,'renewed_from'=>$rf,'exclude_from_revenue'=>$excl?1:0,'created_at'=>current_time('mysql')));
        $sid=$wpdb->insert_id; $sn=$this->gen_sub_number($sid);
        $wpdb->update($t,array('subscription_number'=>$sn),array('id'=>$sid));
        $this->trigger_pusher('user-'.$user->ID,'subscription-approved',array('subscriptionId'=>$sid,'expiresAt'=>$expires_at,'message'=>'اشتراک شما فعال شد!'));
        
        // ارسال پیام پشتیبانی
        $remaining_manual = 0;
        if($ren && $active) {
            $remaining_manual = max(0, intval((strtotime($active->expires_at) - time()) / 86400));
        }
        $this->send_subscription_notification($user->ID, 'approved', array(
            'plan_type' => $plan,
            'days' => $days,
            'is_renewal' => $ren,
            'remaining_days' => $remaining_manual,
            'expires_at' => $expires_at
        ));
        return rest_ensure_response(array('success'=>true,'message'=>$ren?'تمدید با موفقیت انجام شد (به انتهای اشتراک فعلی اضافه شد)':'اشتراک دستی ایجاد شد','subscription'=>array('id'=>$sid,'subscriptionNumber'=>$sn,'userId'=>$user->ID,'userName'=>$user->display_name,'userEmail'=>$user->user_email,'planType'=>$plan,'amount'=>$amt,'status'=>'approved','startedAt'=>$started_at,'expiresAt'=>$expires_at,'isManual'=>true,'isRenewal'=>$ren,'excludeFromRevenue'=>$excl)));
    }
    
    public function admin_approve_subscription($request) {
        global $wpdb; $sid=(int)$request->get_param('id'); $aid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions';
        $p=$request->get_json_params(); $days=intval($p['duration_days']??30); $note=sanitize_textarea_field($p['admin_note']??'');
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status!=='pending') return new WP_Error('invalid_status','قبلا بررسی شده',array('status'=>400));
        $active=$this->get_active_sub($sub->user_id); $ren=(bool)intval($sub->is_renewal??0);
        if($active&&$active->id!=$sid){$sa=$active->expires_at;$ea=date('Y-m-d H:i:s',strtotime($active->expires_at." +{$days} days"));$ren=true;}
        else{$sa=current_time('mysql');$ea=date('Y-m-d H:i:s',strtotime("+{$days} days"));}
        $ud=array('status'=>'approved','admin_note'=>$note,'started_at'=>$sa,'expires_at'=>$ea,'approved_by'=>$aid,'approved_at'=>current_time('mysql'),'is_renewal'=>$ren?1:0);
        if($active&&!$sub->renewed_from) $ud['renewed_from']=$active->id;
        $wpdb->update($t,$ud,array('id'=>$sid));
        $this->trigger_pusher('user-'.$sub->user_id,'subscription-approved',array('subscriptionId'=>$sid,'expiresAt'=>$ea,'message'=>$ren?'تمدید اشتراک تایید شد!':'اشتراک تایید شد!'));
        
        // ارسال پیام پشتیبانی
        $remaining = 0;
        if($ren && $active) {
            $remaining = max(0, intval((strtotime($active->expires_at) - time()) / 86400));
        }
        $this->send_subscription_notification($sub->user_id, 'approved', array(
            'plan_type' => $sub->plan_type,
            'days' => $days,
            'is_renewal' => $ren,
            'remaining_days' => $remaining,
            'expires_at' => $ea
        ));
        
        $msg='اشتراک تایید شد'; if($ren&&$active) $msg.=' (به انتهای اشتراک فعلی اضافه شد)';
        return rest_ensure_response(array('success'=>true,'message'=>$msg,'subscription'=>array('id'=>$sid,'status'=>'approved','startedAt'=>$sa,'expiresAt'=>$ea,'isRenewal'=>$ren)));
    }
    
    public function admin_reject_subscription($request) {
        global $wpdb; $sid=(int)$request->get_param('id'); $aid=$this->get_uid($request); $t=$wpdb->prefix.'subscriptions';
        $p=$request->get_json_params(); $note=sanitize_textarea_field($p['admin_note']??'درخواست رد شد');
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status!=='pending') return new WP_Error('invalid_status','قبلا بررسی شده',array('status'=>400));
        $wpdb->update($t,array('status'=>'rejected','admin_note'=>$note,'approved_by'=>$aid,'approved_at'=>current_time('mysql')),array('id'=>$sid));
        // Revert discount usage if any
        if(!empty($sub->discount_code)) $this->revert_discount_usage($sub->discount_code, $sub->user_id, $sid);
        $this->trigger_pusher('user-'.$sub->user_id,'subscription-rejected',array('subscriptionId'=>$sid,'reason'=>$note,'message'=>'درخواست اشتراک رد شد'));
        
        // ارسال پیام پشتیبانی
        $this->send_subscription_notification($sub->user_id, 'rejected', array(
            'plan_type' => $sub->plan_type,
            'reason' => $note
        ));
        return rest_ensure_response(array('success'=>true,'message'=>'رد شد','subscription'=>array('id'=>$sid,'status'=>'rejected','adminNote'=>$note)));
    }
    
    public function admin_get_stats($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $ms=date('Y-m-01');
        return rest_ensure_response(array(
            'total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status!='trashed'"),
            'pending'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='pending'"),
            'approved'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='approved'"),
            'rejected'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='rejected'"),
            'expired'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='expired'"),
            'active'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE status='approved' AND expires_at>NOW()"),
            'todayRequests'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE DATE(created_at)=%s AND status!='trashed'",date('Y-m-d'))),
            'monthRevenue'=>floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM $t WHERE status='approved' AND approved_at>=%s AND exclude_from_revenue=0",$ms))),
            'totalRenewals'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE is_renewal=1 AND status!='trashed'"),
            'monthRenewals'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE is_renewal=1 AND status='approved' AND approved_at>=%s",$ms)),
            'monthDiscountTotal'=>floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(discount_amount),0) FROM $t WHERE status='approved' AND approved_at>=%s AND discount_amount>0",$ms))),
        ));
    }
    
    public function admin_get_subscriptions($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions';
        $status=$request->get_param('status'); $pg=max(1,intval($request->get_param('page')?:1)); $pp=max(1,min(50,intval($request->get_param('per_page')?:20))); $off=($pg-1)*$pp;
        $w="1=1";
        if($status==='trashed') $w.=" AND s.status='trashed'";
        elseif($status==='manual') $w.=" AND s.is_manual=1 AND s.status!='trashed'";
        elseif($status==='renewal') $w.=" AND s.is_renewal=1 AND s.status!='trashed'";
        elseif($status==='expired') $w.=" AND s.status='expired'";
        elseif($status&&in_array($status,array('pending','approved','rejected'))) $w.=$wpdb->prepare(" AND s.status=%s",$status);
        else $w.=" AND s.status!='trashed'";
        $total=$wpdb->get_var("SELECT COUNT(*) FROM $t s WHERE $w");
        $subs=$wpdb->get_results($wpdb->prepare("SELECT s.*,u.display_name as uname,u.user_email as uemail FROM $t s LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID WHERE $w ORDER BY CASE s.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 WHEN 'expired' THEN 4 WHEN 'trashed' THEN 5 ELSE 6 END, s.created_at DESC LIMIT %d OFFSET %d",$pp,$off));
        $r=array();
        foreach($subs as $s) $r[]=array('id'=>(int)$s->id,'subscriptionNumber'=>$s->subscription_number??null,'userId'=>(int)$s->user_id,'userName'=>$s->uname?:'کاربر','userEmail'=>$s->uemail,'planType'=>$s->plan_type,'amount'=>floatval($s->amount),'originalAmount'=>floatval($s->original_amount??$s->amount),'discountCode'=>$s->discount_code??null,'discountAmount'=>floatval($s->discount_amount??0),'paymentProof'=>$s->payment_proof,'txHash'=>$s->tx_hash??null,'network'=>$s->network??'TRC20','status'=>$s->status,'adminNote'=>$s->admin_note,'startedAt'=>$s->started_at,'expiresAt'=>$s->expires_at,'approvedBy'=>$s->approved_by?(int)$s->approved_by:null,'approvedAt'=>$s->approved_at,'isManual'=>(bool)intval($s->is_manual??0),'isRenewal'=>(bool)intval($s->is_renewal??0),'renewedFrom'=>$s->renewed_from?(int)$s->renewed_from:null,'excludeFromRevenue'=>(bool)intval($s->exclude_from_revenue??0),'createdAt'=>$s->created_at,'updatedAt'=>$s->updated_at);
        return rest_ensure_response(array('subscriptions'=>$r,'total'=>(int)$total,'page'=>$pg,'perPage'=>$pp,'totalPages'=>ceil($total/$pp)));
    }
    
    public function admin_get_subscription($request) {
        global $wpdb; $sid=(int)$request->get_param('id'); $t=$wpdb->prefix.'subscriptions';
        $s=$wpdb->get_row($wpdb->prepare("SELECT s.*,u.display_name as uname,u.user_email as uemail FROM $t s LEFT JOIN {$wpdb->users} u ON s.user_id=u.ID WHERE s.id=%d",$sid));
        if(!$s) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        return rest_ensure_response(array('id'=>(int)$s->id,'subscriptionNumber'=>$s->subscription_number??null,'userId'=>(int)$s->user_id,'userName'=>$s->uname?:'کاربر','userEmail'=>$s->uemail,'planType'=>$s->plan_type,'amount'=>floatval($s->amount),'originalAmount'=>floatval($s->original_amount??$s->amount),'discountCode'=>$s->discount_code??null,'discountAmount'=>floatval($s->discount_amount??0),'paymentProof'=>$s->payment_proof,'txHash'=>$s->tx_hash??null,'network'=>$s->network??'TRC20','status'=>$s->status,'adminNote'=>$s->admin_note,'startedAt'=>$s->started_at,'expiresAt'=>$s->expires_at,'approvedBy'=>$s->approved_by?(int)$s->approved_by:null,'approvedAt'=>$s->approved_at,'isManual'=>(bool)intval($s->is_manual??0),'isRenewal'=>(bool)intval($s->is_renewal??0),'renewedFrom'=>$s->renewed_from?(int)$s->renewed_from:null,'excludeFromRevenue'=>(bool)intval($s->exclude_from_revenue??0),'reminderClicked'=>(bool)intval($s->reminder_clicked??0),'createdAt'=>$s->created_at,'updatedAt'=>$s->updated_at));
    }
    
    // Admin Discount CRUD
    public function admin_get_discount_codes($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes';
        $status=$request->get_param('status');
        if($status==='trashed') $where="status='trashed'";
        else $where="status!='trashed'";
        $codes=$wpdb->get_results("SELECT * FROM $t WHERE $where ORDER BY created_at DESC"); $r=array();
        foreach($codes as $c) $r[]=array('id'=>(int)$c->id,'code'=>$c->code,'description'=>$c->description,'discountType'=>$c->discount_type,'discountValue'=>floatval($c->discount_value),'maxUses'=>(int)$c->max_uses,'usedCount'=>(int)$c->used_count,'minMonths'=>(int)$c->min_months,'maxMonths'=>(int)$c->max_months,'perUserLimit'=>(int)$c->per_user_limit,'validFrom'=>$c->valid_from,'validUntil'=>$c->valid_until,'isActive'=>(bool)$c->is_active,'status'=>$c->status??'active','createdAt'=>$c->created_at);
        return rest_ensure_response($r);
    }
    
    public function admin_create_discount_code($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes'; $aid=$this->get_uid($request); $p=$request->get_json_params();
        $code=strtoupper(sanitize_text_field($p['code']??''));
        if(empty($code)||strlen($code)<3) return new WP_Error('invalid_code','حداقل ۳ کاراکتر',array('status'=>400));
        if($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE code=%s",$code))) return new WP_Error('code_exists','قبلاً وجود دارد',array('status'=>400));
        $wpdb->insert($t,array('code'=>$code,'description'=>sanitize_textarea_field($p['description']??''),'discount_type'=>in_array($p['discount_type']??'',array('percent','fixed'))?$p['discount_type']:'percent','discount_value'=>floatval($p['discount_value']??0),'max_uses'=>intval($p['max_uses']??0),'used_count'=>0,'min_months'=>max(1,intval($p['min_months']??1)),'max_months'=>min(12,intval($p['max_months']??12)),'per_user_limit'=>intval($p['per_user_limit']??1),'valid_from'=>!empty($p['valid_from'])?sanitize_text_field($p['valid_from']):null,'valid_until'=>!empty($p['valid_until'])?sanitize_text_field($p['valid_until']):null,'is_active'=>1,'created_by'=>$aid,'created_at'=>current_time('mysql')));
        return rest_ensure_response(array('success'=>true,'message'=>'کد تخفیف ایجاد شد','id'=>$wpdb->insert_id,'code'=>$code));
    }
    
    public function admin_update_discount_code($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes'; $id=(int)$request->get_param('id'); $p=$request->get_json_params();
        if(!$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id))) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        $u=array();
        if(isset($p['description'])) $u['description']=sanitize_textarea_field($p['description']);
        if(isset($p['discount_type'])) $u['discount_type']=in_array($p['discount_type'],array('percent','fixed'))?$p['discount_type']:'percent';
        if(isset($p['discount_value'])) $u['discount_value']=floatval($p['discount_value']);
        if(isset($p['max_uses'])) $u['max_uses']=intval($p['max_uses']);
        if(isset($p['min_months'])) $u['min_months']=max(1,intval($p['min_months']));
        if(isset($p['max_months'])) $u['max_months']=min(12,intval($p['max_months']));
        if(isset($p['per_user_limit'])) $u['per_user_limit']=intval($p['per_user_limit']);
        if(isset($p['valid_from'])) $u['valid_from']=!empty($p['valid_from'])?sanitize_text_field($p['valid_from']):null;
        if(isset($p['valid_until'])) $u['valid_until']=!empty($p['valid_until'])?sanitize_text_field($p['valid_until']):null;
        if(isset($p['is_active'])) $u['is_active']=(bool)$p['is_active']?1:0;
        if(empty($u)) return new WP_Error('no_changes','تغییری نیست',array('status'=>400));
        $wpdb->update($t,$u,array('id'=>$id));
        return rest_ensure_response(array('success'=>true,'message'=>'بروزرسانی شد'));
    }
    
    public function admin_delete_discount_code($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes'; $id=(int)$request->get_param('id');
        $code=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id));
        if(!$code) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if(($code->status??'active')==='trashed') return new WP_Error('already_trashed','قبلاً در سطل آشغال',array('status'=>400));
        $wpdb->update($t,array('status'=>'trashed','is_active'=>0),array('id'=>$id));
        return rest_ensure_response(array('success'=>true,'message'=>'به سطل آشغال منتقل شد'));
    }
    
    public function admin_trash_discount_code($request) {
        return $this->admin_delete_discount_code($request);
    }
    
    public function admin_restore_discount_code($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes'; $id=(int)$request->get_param('id');
        $code=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id));
        if(!$code) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if(($code->status??'active')!=='trashed') return new WP_Error('not_trashed','در سطل آشغال نیست',array('status'=>400));
        $wpdb->update($t,array('status'=>'active','is_active'=>1),array('id'=>$id));
        return rest_ensure_response(array('success'=>true,'message'=>'بازیابی شد'));
    }
    
    public function admin_permanent_delete_discount_code($request) {
        global $wpdb; $t=$wpdb->prefix.'discount_codes'; $tu=$wpdb->prefix.'discount_usage'; $id=(int)$request->get_param('id');
        $code=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$id));
        if(!$code) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if(($code->status??'active')!=='trashed') return new WP_Error('not_trashed','فقط موارد سطل آشغال',array('status'=>400));
        $wpdb->delete($tu,array('discount_code_id'=>$id));
        $wpdb->delete($t,array('id'=>$id));
        return rest_ensure_response(array('success'=>true,'message'=>'حذف دائمی شد'));
    }
    
    public function admin_get_discount_stats($request) {
        global $wpdb; $tu=$wpdb->prefix.'discount_usage'; $id=(int)$request->get_param('id');
        $recent=$wpdb->get_results($wpdb->prepare("SELECT du.*,u.display_name,u.user_email FROM $tu du LEFT JOIN {$wpdb->users} u ON du.user_id=u.ID WHERE du.discount_code_id=%d ORDER BY du.used_at DESC LIMIT 20",$id));
        $ul=array(); foreach($recent as $u) $ul[]=array('userName'=>$u->display_name,'userEmail'=>$u->user_email,'originalAmount'=>floatval($u->original_amount),'discountAmount'=>floatval($u->discount_amount),'finalAmount'=>floatval($u->final_amount),'usedAt'=>$u->used_at);
        return rest_ensure_response(array(
            'totalUses'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tu WHERE discount_code_id=%d",$id)),
            'totalDiscount'=>floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(discount_amount),0) FROM $tu WHERE discount_code_id=%d",$id))),
            'totalRevenue'=>floatval($wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(final_amount),0) FROM $tu WHERE discount_code_id=%d",$id))),
            'uniqueUsers'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $tu WHERE discount_code_id=%d",$id)),
            'recentUsage'=>$ul
        ));
    }
    
    // Edit/Trash/Restore/Delete
    public function admin_update_status($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $sid=(int)$request->get_param('id'); $aid=$this->get_uid($request);
        $p=$request->get_json_params(); $ns=sanitize_text_field($p['status']??''); $note=sanitize_textarea_field($p['admin_note']??''); $days=intval($p['duration_days']??30); $cat=sanitize_text_field($p['created_at']??'');
        if(!in_array($ns,array('pending','approved','rejected'))) return new WP_Error('invalid_status','نامعتبر',array('status'=>400));
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status==='trashed') return new WP_Error('is_trashed','ابتدا بازیابی کنید',array('status'=>400));
        $ud=array('status'=>$ns,'admin_note'=>$note,'approved_by'=>$aid,'updated_at'=>current_time('mysql'));
        if(!empty($cat)) $ud['created_at']=$cat;
        if($ns==='approved'){$act=$this->get_active_sub($sub->user_id);if($act&&$act->id!=$sid){$ud['started_at']=$act->expires_at;$ud['expires_at']=date('Y-m-d H:i:s',strtotime($act->expires_at." +{$days} days"));}else{$ud['approved_at']=current_time('mysql');$ud['started_at']=current_time('mysql');$ud['expires_at']=date('Y-m-d H:i:s',strtotime("+{$days} days"));}}
        if($sub->status==='approved'&&$ns!=='approved'){$ud['started_at']=null;$ud['expires_at']=null;}
        $wpdb->update($t,$ud,array('id'=>$sid));
        $this->sync_sub_discount($sub);
        if($ns==='approved') $this->trigger_pusher('user-'.$sub->user_id,'subscription-approved',array('subscriptionId'=>$sid,'expiresAt'=>$ud['expires_at']??'','message'=>'اشتراک تایید شد!'));
        return rest_ensure_response(array('success'=>true,'message'=>'تغییر کرد','status'=>$ns,'expiresAt'=>$ud['expires_at']??null));
    }
    
    public function admin_notify_user($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $sid=(int)$request->get_param('id');
        $p=$request->get_json_params();
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        
        $old_exp = $p['old_expires_at'] ?? null;
        $new_exp = $sub->expires_at;
        $days = intval($p['duration_days'] ?? 30);
        
        $plan_labels = array('1_month'=>'۱ ماهه','3_month'=>'۳ ماهه','6_month'=>'۶ ماهه','12_month'=>'۱ ساله');
        $plan_label = $plan_labels[$sub->plan_type] ?? $sub->plan_type;
        
        // محاسبه تفاوت روزها
        if($old_exp && $new_exp) {
            $old_ts = strtotime($old_exp);
            $new_ts = strtotime($new_exp);
            $diff_days = intval(($new_ts - $old_ts) / 86400);
            
            if($diff_days > 0) {
                $remaining = max(0, intval(($new_ts - time()) / 86400));
                $msg = "📢 اشتراک شما بروزرسانی شد!\n\n";
                $msg .= "➕ {$diff_days} روز به اشتراک شما اضافه شد\n";
                $msg .= "📅 اعتبار کل: {$remaining} روز\n";
                $msg .= "⏰ تاریخ انقضای جدید: " . date('Y/m/d', $new_ts) . "\n\n";
                $msg .= "موفق باشید! 🙏";
            } elseif($diff_days < 0) {
                $remaining = max(0, intval(($new_ts - time()) / 86400));
                $msg = "📢 اشتراک شما بروزرسانی شد.\n\n";
                $msg .= "📅 اعتبار باقی‌مانده: {$remaining} روز\n";
                $msg .= "⏰ تاریخ انقضای جدید: " . date('Y/m/d', $new_ts) . "\n\n";
                $msg .= "در صورت سوال با پشتیبانی در ارتباط باشید.";
            } else {
                $msg = "📢 اطلاعات اشتراک {$plan_label} شما بروزرسانی شد.\n\n";
                $msg .= "⏰ تاریخ انقضا: " . date('Y/m/d', $new_ts);
            }
        } else {
            // اشتراک تازه فعال شده از ویرایش
            $exp_ts = strtotime($new_exp);
            $remaining = max(0, intval(($exp_ts - time()) / 86400));
            $msg = "✅ اشتراک {$plan_label} شما فعال شد!\n\n";
            $msg .= "📅 مدت اعتبار: {$remaining} روز\n";
            $msg .= "⏰ تاریخ انقضا: " . date('Y/m/d', $exp_ts) . "\n\n";
            $msg .= "به جمع آلفاها خوش آمدید! 🎉";
        }
        
        // ارسال پیام پشتیبانی
        $tm = $wpdb->prefix.'support_messages';
        if($wpdb->get_var("SHOW TABLES LIKE '$tm'")==$tm) {
            $cid = $this->get_or_create_conv($sub->user_id);
            if($cid) {
                $wpdb->insert($tm, array(
                    'conversation_id' => $cid,
                    'sender_type' => 'admin',
                    'sender_id' => 0,
                    'message_type' => 'text',
                    'content' => $msg,
                    'status' => 'sent',
                    'created_at' => current_time('mysql')
                ));
                $msg_id = $wpdb->insert_id;
                $this->trigger_pusher('conversation-'.$cid, 'new-message', array(
                    'id' => $msg_id,
                    'sender' => 'admin',
                    'type' => 'text',
                    'content' => $msg,
                    'createdAt' => current_time('mysql')
                ));
            }
        }
        
        return rest_ensure_response(array('success'=>true,'message'=>'پیام ارسال شد'));
    }
    
    public function admin_trash_subscription($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $sid=(int)$request->get_param('id');
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status==='trashed') return new WP_Error('already_trashed','قبلاً در سطل آشغال',array('status'=>400));
        $pi='previous_status:'.$sub->status; if($sub->admin_note) $pi.='|previous_note:'.$sub->admin_note;
        $wpdb->update($t,array('status'=>'trashed','admin_note'=>$pi,'updated_at'=>current_time('mysql')),array('id'=>$sid));
        $this->sync_sub_discount($sub);
        return rest_ensure_response(array('success'=>true,'message'=>'به سطل آشغال منتقل شد'));
    }
    
    public function admin_restore_subscription($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $sid=(int)$request->get_param('id');
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status!=='trashed') return new WP_Error('not_trashed','در سطل آشغال نیست',array('status'=>400));
        $ps='pending';$pn='';
        if($sub->admin_note&&strpos($sub->admin_note,'previous_status:')===0){$parts=explode('|',$sub->admin_note);foreach($parts as $pt){if(strpos($pt,'previous_status:')===0)$ps=str_replace('previous_status:','',$pt);if(strpos($pt,'previous_note:')===0)$pn=str_replace('previous_note:','',$pt);}}
        if(!in_array($ps,array('pending','approved','rejected','expired')))$ps='pending';
        $wpdb->update($t,array('status'=>$ps,'admin_note'=>$pn,'updated_at'=>current_time('mysql')),array('id'=>$sid));
        $this->sync_sub_discount($sub);
        return rest_ensure_response(array('success'=>true,'message'=>'بازیابی شد','restored_status'=>$ps));
    }
    
    public function admin_permanent_delete($request) {
        global $wpdb; $t=$wpdb->prefix.'subscriptions'; $tu=$wpdb->prefix.'discount_usage'; $sid=(int)$request->get_param('id');
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$sid));
        if(!$sub) return new WP_Error('not_found','یافت نشد',array('status'=>404));
        if($sub->status!=='trashed') return new WP_Error('not_trashed','فقط موارد سطل آشغال',array('status'=>400));
        // Delete usage records for this subscription
        $wpdb->delete($tu,array('subscription_id'=>$sid));
        $wpdb->delete($t,array('id'=>$sid),array('%d'));
        // Sync discount count after delete
        if(!empty($sub->discount_code)) {
            $tc=$wpdb->prefix.'discount_codes';
            $d=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tc WHERE code=%s",$sub->discount_code));
            if($d) $this->sync_discount_used_count($d->id);
        }
        return rest_ensure_response(array('success'=>true,'message'=>'حذف دائمی شد'));
    }
    
    // ========== CRON ==========
    public function run_cron_tasks() { $this->mark_expired(); $this->send_reminders(); $this->send_winbacks(); }
    
    private function mark_expired() {
        global $wpdb; $t=$wpdb->prefix.'subscriptions';
        $wpdb->query("UPDATE $t SET status='expired' WHERE status='approved' AND expires_at<=NOW()");
    }
    
    private function send_reminders() {
        global $wpdb; $t=$wpdb->prefix.'subscriptions';
        $reminders=array(array('days'=>7,'field'=>'reminder_7d_sent','msg'=>'اشتراک شما تا ۷ روز دیگر به پایان می‌رسد. برای ادامه دسترسی، تمدید کنید.'),array('days'=>2,'field'=>'reminder_2d_sent','msg'=>'تنها ۲ روز تا پایان اشتراک باقی مانده! همین الان تمدید کنید.'),array('days'=>1,'field'=>'reminder_1d_sent','msg'=>'اشتراک شما فردا منقضی می‌شود! لطفاً تمدید کنید.'));
        foreach($reminders as $r){
            $subs=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status='approved' AND expires_at>NOW() AND DATEDIFF(expires_at,NOW())<=%d AND {$r['field']}=0",$r['days']));
            foreach($subs as $s){
                $this->trigger_pusher('user-'.$s->user_id,'renewal-reminder',array('subscriptionId'=>(int)$s->id,'daysRemaining'=>$r['days'],'message'=>$r['msg'],'type'=>'renewal_reminder'));
                $this->send_system_msg($s->user_id,$r['msg'],'renewal_reminder',$s->id);
                $wpdb->update($t,array($r['field']=>1),array('id'=>$s->id));
            }
        }
    }
    
    private function send_winbacks() {
        global $wpdb; $t=$wpdb->prefix.'subscriptions';
        $wbs=array(array('days'=>1,'field'=>'winback_1d_sent','msg'=>'اشتراک شما به پایان رسید. هنوز می‌توانید تمدید کنید.'),array('days'=>3,'field'=>'winback_3d_sent','msg'=>'دلمان برایتان تنگ شده! اشتراک خود را تمدید کنید.'));
        foreach($wbs as $w){
            $subs=$wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status='expired' AND DATEDIFF(NOW(),expires_at)>=%d AND {$w['field']}=0",$w['days']));
            foreach($subs as $s){
                $this->trigger_pusher('user-'.$s->user_id,'renewal-reminder',array('subscriptionId'=>(int)$s->id,'message'=>$w['msg'],'type'=>'winback'));
                $this->send_system_msg($s->user_id,$w['msg'],'winback',$s->id);
                $wpdb->update($t,array($w['field']=>1),array('id'=>$s->id));
            }
        }
    }
    
    private function send_system_msg($uid,$msg,$type,$sid) {
        global $wpdb; $tm=$wpdb->prefix.'support_messages';
        if($wpdb->get_var("SHOW TABLES LIKE '$tm'")!=$tm) return;
        $data=json_encode(array('type'=>$type,'subscriptionId'=>$sid,'action'=>'renewal'));
        $full=$msg."\n\n[RENEWAL_BUTTON:".$data."]";
        $cid=$this->get_or_create_conv($uid);
        $wpdb->insert($tm,array('conversation_id'=>$cid,'sender_type'=>'system','sender_id'=>0,'message'=>$full,'created_at'=>current_time('mysql')));
    }
    
    // ارسال پیام پشتیبانی برای تایید/رد اشتراک
    private function send_subscription_notification($uid, $type, $data = array()) {
        global $wpdb; $tm=$wpdb->prefix.'support_messages';
        if($wpdb->get_var("SHOW TABLES LIKE '$tm'")!=$tm) return;
        
        $plan_labels = array('1_month'=>'۱ ماهه (۳۰ روز)','3_month'=>'۳ ماهه (۹۰ روز)','6_month'=>'۶ ماهه (۱۸۰ روز)','12_month'=>'۱۲ ماهه (۳۶۵ روز)');
        $plan_type = $data['plan_type'] ?? '';
        $plan_label = $plan_labels[$plan_type] ?? $plan_type;
        
        if($type === 'approved') {
            $days = $data['days'] ?? 30;
            $is_renewal = $data['is_renewal'] ?? false;
            $remaining_days = $data['remaining_days'] ?? 0;
            $total_days = $is_renewal ? ($remaining_days + $days) : $days;
            $expires_at = $data['expires_at'] ?? '';
            
            $exp_formatted = '';
            if($expires_at) {
                $exp_formatted = date('Y/m/d', strtotime($expires_at));
            }
            
            if($is_renewal && $remaining_days > 0) {
                $msg = "✅ تمدید اشتراک شما با موفقیت انجام شد!\n\n";
                $msg .= "📋 نوع اشتراک: {$plan_label}\n";
                $msg .= "📅 {$remaining_days} روز باقی‌مانده + {$days} روز جدید = {$total_days} روز اعتبار\n";
                $msg .= "⏰ تاریخ انقضا: {$exp_formatted}\n\n";
                $msg .= "از اعتماد شما سپاسگزاریم! 🙏";
            } else {
                $msg = "✅ اشتراک شما با موفقیت فعال شد!\n\n";
                $msg .= "📋 نوع اشتراک: {$plan_label}\n";
                $msg .= "📅 مدت اعتبار: {$days} روز\n";
                $msg .= "⏰ تاریخ انقضا: {$exp_formatted}\n\n";
                $msg .= "به جمع آلفاها خوش آمدید! 🎉";
            }
        } elseif($type === 'rejected') {
            $reason = $data['reason'] ?? 'درخواست رد شد';
            $msg = "❌ درخواست اشتراک {$plan_label} شما رد شد.\n\n";
            $msg .= "📝 دلیل: {$reason}\n\n";
            $msg .= "در صورت نیاز می‌توانید مجدداً درخواست ارسال کنید یا همینجا با پشتیبانی صحبت کنید.";
        } else {
            return;
        }
        
        $cid = $this->get_or_create_conv($uid);
        if(!$cid) return;
        
        $wpdb->insert($tm, array(
            'conversation_id' => $cid,
            'sender_type' => 'admin',
            'sender_id' => 0,
            'message_type' => 'text',
            'content' => $msg,
            'status' => 'sent',
            'created_at' => current_time('mysql')
        ));
        $msg_id = $wpdb->insert_id;
        
        // ارسال Pusher به کانال conversation کاربر (مثل پیام ادمین)
        $this->trigger_pusher('conversation-'.$cid, 'new-message', array(
            'id' => $msg_id,
            'sender' => 'admin',
            'type' => 'text',
            'content' => $msg,
            'createdAt' => current_time('mysql')
        ));
    }
    
    private function get_or_create_conv($uid) {
        global $wpdb; $tc=$wpdb->prefix.'support_conversations';
        if($wpdb->get_var("SHOW TABLES LIKE '$tc'")!=$tc) return 0;
        $c=$wpdb->get_row($wpdb->prepare("SELECT id FROM $tc WHERE user_id=%d ORDER BY id DESC LIMIT 1",$uid));
        if($c) return (int)$c->id;
        $wpdb->insert($tc,array('user_id'=>$uid,'status'=>'open','created_at'=>current_time('mysql')));
        return $wpdb->insert_id;
    }
    
    private function trigger_pusher($ch,$ev,$data) {
        if(!defined('PUSHER_APP_ID')||!defined('PUSHER_KEY')||!defined('PUSHER_SECRET')||!defined('PUSHER_CLUSTER')) return;
        $url='https://api-'.PUSHER_CLUSTER.'.pusher.com/apps/'.PUSHER_APP_ID.'/events';
        $body=json_encode(array('name'=>$ev,'channel'=>$ch,'data'=>json_encode($data)));
        $ts=time();$md5=md5($body);
        $str="POST\n/apps/".PUSHER_APP_ID."/events\nauth_key=".PUSHER_KEY."&auth_timestamp=".$ts."&auth_version=1.0&body_md5=".$md5;
        $sig=hash_hmac('sha256',$str,PUSHER_SECRET);
        $qp=http_build_query(array('auth_key'=>PUSHER_KEY,'auth_timestamp'=>$ts,'auth_version'=>'1.0','body_md5'=>$md5,'auth_signature'=>$sig));
        wp_remote_post($url.'?'.$qp,array('method'=>'POST','headers'=>array('Content-Type'=>'application/json'),'body'=>$body,'timeout'=>5));
    }
}

AsadMindset_Subscription::get_instance();