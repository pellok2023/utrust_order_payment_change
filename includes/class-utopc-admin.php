<?php
/**
 * 管理頁面模組
 * 負責後台管理介面和操作
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Admin {
    
    private static $instance = null;
    private $database;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->database = UTOPC_Database::get_instance();
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_utopc_get_account', array($this, 'ajax_get_account'));
        add_action('wp_ajax_utopc_add_account', array($this, 'ajax_add_account'));
        add_action('wp_ajax_utopc_update_account', array($this, 'ajax_update_account'));
        add_action('wp_ajax_utopc_delete_account', array($this, 'ajax_delete_account'));
        add_action('wp_ajax_utopc_activate_account', array($this, 'ajax_activate_account'));
        add_action('wp_ajax_utopc_reset_monthly', array($this, 'ajax_reset_monthly'));
        add_action('wp_ajax_utopc_confirm_deletion', array($this, 'ajax_confirm_deletion'));
        add_action('admin_notices', array($this, 'show_default_account_notice'));
    }
    
    /**
     * 新增管理選單
     */
    public function add_admin_menu() {
        add_menu_page(
            __('金流管理', 'utrust-order-payment-change'),
            __('金流管理', 'utrust-order-payment-change'),
            'manage_options',
            'utopc-payment-management',
            array($this, 'admin_page'),
            'dashicons-money-alt',
            30
        );
    }
    
    /**
     * 載入管理頁面腳本
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_utopc-payment-management') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        wp_enqueue_script(
            'utopc-admin',
            UTOPC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-dialog'),
            UTOPC_PLUGIN_VERSION,
            true
        );
        
        wp_enqueue_style(
            'utopc-admin',
            UTOPC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            UTOPC_PLUGIN_VERSION
        );
        
        wp_localize_script('utopc-admin', 'utopc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('utopc_nonce'),
            'strings' => array(
                'confirm_delete' => __('確定要刪除此金流帳號嗎？', 'utrust-order-payment-change'),
                'confirm_reset' => __('確定要重置所有帳號的當月累計金額嗎？', 'utrust-order-payment-change'),
                'success' => __('操作成功！', 'utrust-order-payment-change'),
                'error' => __('操作失敗！', 'utrust-order-payment-change')
            )
        ));
    }
    
    /**
     * 管理頁面內容
     */
    public function admin_page() {
        $accounts = $this->database->get_all_accounts();
        $active_account = $this->database->get_active_account();
        
        // 取得當前 newebpay 設定
        $payment_switcher = UTOPC_Payment_Switcher::get_instance();
        $current_newebpay_settings = $payment_switcher->get_current_newebpay_settings();
        
        include UTOPC_PLUGIN_PATH . 'templates/admin-page.php';
    }
    
    /**
     * AJAX 取得帳號資料
     */
    public function ajax_get_account() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $id = intval($_POST['id']);
        $account = $this->database->get_account($id);
        
        if (!$account) {
            wp_send_json_error(__('帳號不存在', 'utrust-order-payment-change'));
        } else {
            wp_send_json_success($account);
        }
    }
    
    /**
     * AJAX 新增帳號
     */
    public function ajax_add_account() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $data = array(
            'account_name' => sanitize_text_field($_POST['account_name']),
            'merchant_id' => sanitize_text_field($_POST['merchant_id']),
            'hash_key' => sanitize_text_field($_POST['hash_key']),
            'hash_iv' => sanitize_text_field($_POST['hash_iv']),
            'amount_limit' => floatval($_POST['amount_limit']),
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        );
        
        $result = $this->database->add_account($data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('金流帳號新增成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * AJAX 更新帳號
     */
    public function ajax_update_account() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $id = intval($_POST['id']);
        $data = array(
            'account_name' => sanitize_text_field($_POST['account_name']),
            'merchant_id' => sanitize_text_field($_POST['merchant_id']),
            'hash_key' => sanitize_text_field($_POST['hash_key']),
            'hash_iv' => sanitize_text_field($_POST['hash_iv']),
            'amount_limit' => floatval($_POST['amount_limit'])
        );
        
        $result = $this->database->update_account($id, $data);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('金流帳號更新成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * AJAX 刪除帳號
     */
    public function ajax_delete_account() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $id = intval($_POST['id']);
        $result = $this->database->delete_account($id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('金流帳號刪除成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * AJAX 啟用帳號
     */
    public function ajax_activate_account() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $id = intval($_POST['id']);
        $result = $this->database->activate_account($id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('金流帳號啟用成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * AJAX 重置當月金額
     */
    public function ajax_reset_monthly() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $result = $this->database->reset_monthly_amounts();
        
        if ($result === false) {
            wp_send_json_error(__('重置失敗！', 'utrust-order-payment-change'));
        } else {
            wp_send_json_success(__('當月累計金額重置成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * 隱碼顯示 MerchantID
     */
    public function mask_merchant_id($merchant_id) {
        if (strlen($merchant_id) <= 4) {
            return str_repeat('*', strlen($merchant_id));
        }
        
        return substr($merchant_id, 0, 2) . str_repeat('*', strlen($merchant_id) - 4) . substr($merchant_id, -2);
    }
    
    /**
     * 格式化金額
     */
    public function format_amount($amount) {
        return number_format($amount, 2);
    }
    
    /**
     * 取得狀態標籤
     */
    public function get_status_label($is_active) {
        if ($is_active) {
            return '<span class="utopc-status active">' . __('使用中', 'utrust-order-payment-change') . '</span>';
        } else {
            return '<span class="utopc-status inactive">' . __('已切換', 'utrust-order-payment-change') . '</span>';
        }
    }
    
    /**
     * AJAX 確認刪除外掛
     */
    public function ajax_confirm_deletion() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        // 設定確認刪除標記
        update_option('utopc_confirmed_deletion', true);
        
        wp_send_json_success(__('已確認刪除，現在可以安全移除外掛。', 'utrust-order-payment-change'));
    }
    
    /**
     * 顯示預設帳號創建通知
     */
    public function show_default_account_notice() {
        // 只在金流管理頁面顯示
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'toplevel_page_utopc-payment-management') {
            return;
        }
        
        // 檢查是否剛創建了預設帳號
        if (get_option('utopc_default_account_created', false)) {
            $created_time = get_option('utopc_default_account_created_time', '');
            $source = get_option('utopc_default_account_source', '');
            
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>' . __('UTrust Order Payment Change', 'utrust-order-payment-change') . ':</strong> ';
            echo __('已自動從現有的 NewebPay 設定創建預設金流帳號。', 'utrust-order-payment-change');
            
            if ($source && $source !== 'unknown') {
                echo ' <em>(' . sprintf(__('來源：%s', 'utrust-order-payment-change'), $source) . ')</em>';
            }
            
            if ($created_time) {
                echo ' <em>(' . sprintf(__('創建時間：%s', 'utrust-order-payment-change'), $created_time) . ')</em>';
            }
            echo '</p>';
            echo '</div>';
            
            // 清除通知標記
            delete_option('utopc_default_account_created');
            delete_option('utopc_default_account_created_time');
            delete_option('utopc_default_account_source');
        }
    }
}
