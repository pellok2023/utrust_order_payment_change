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
        add_action('wp_ajax_utopc_calculate_monthly', array($this, 'ajax_calculate_monthly'));
        add_action('wp_ajax_utopc_confirm_deletion', array($this, 'ajax_confirm_deletion'));
        add_action('wp_ajax_utopc_set_default', array($this, 'ajax_set_default'));
        add_action('wp_ajax_utopc_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_utopc_get_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_utopc_clear_logs', array($this, 'ajax_clear_logs'));
        add_action('wp_ajax_utopc_update_old_orders', array($this, 'ajax_update_old_orders'));
        add_action('admin_notices', array($this, 'show_default_account_notice'));
        add_shortcode('utopc_company_info', array($this, 'company_info_shortcode'));
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
            'company_name' => sanitize_text_field($_POST['company_name']),
            'tax_id' => sanitize_text_field($_POST['tax_id']),
            'address' => sanitize_textarea_field($_POST['address']),
            'phone' => sanitize_text_field($_POST['phone']),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'is_default' => isset($_POST['is_default']) ? 1 : 0
        );
        
        $result = $this->database->add_account($data);
        
        if (is_wp_error($result)) {
            $this->log_error("新增金流帳號失敗：{$data['account_name']} - {$result->get_error_message()}", 'admin');
            wp_send_json_error($result->get_error_message());
        } else {
            $this->log_success("成功新增金流帳號：{$data['account_name']} (ID: $result)", 'admin');
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
            'amount_limit' => floatval($_POST['amount_limit']),
            'monthly_amount' => floatval($_POST['monthly_amount']),
            'company_name' => sanitize_text_field($_POST['company_name']),
            'tax_id' => sanitize_text_field($_POST['tax_id']),
            'address' => sanitize_textarea_field($_POST['address']),
            'phone' => sanitize_text_field($_POST['phone']),
            'is_default' => isset($_POST['is_default']) ? 1 : 0
        );
        
        $account = $this->database->get_account($id);
        $account_name = $account ? $account->account_name : '未知帳號';
        
        $result = $this->database->update_account($id, $data);
        
        if (is_wp_error($result)) {
            $this->log_error("更新金流帳號失敗：{$account_name} - {$result->get_error_message()}", 'admin');
            wp_send_json_error($result->get_error_message());
        } else {
            $this->log_success("成功更新金流帳號：{$account_name} (ID: $id)", 'admin');
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
        $account = $this->database->get_account($id);
        $account_name = $account ? $account->account_name : '未知帳號';
        
        $this->log_info("開始刪除金流帳號：{$account_name} (ID: $id)", 'admin');
        
        $result = $this->database->delete_account($id);
        
        if (is_wp_error($result)) {
            $this->log_error("刪除金流帳號失敗：{$account_name} - {$result->get_error_message()}", 'admin');
            wp_send_json_error($result->get_error_message());
        } else {
            $this->log_success("成功刪除金流帳號：{$account_name} (ID: $id)", 'admin');
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
        $account = $this->database->get_account($id);
        
        if (!$account) {
            $this->log_error("嘗試啟用不存在的金流帳號，ID: $id", 'admin');
            wp_send_json_error(__('帳號不存在', 'utrust-order-payment-change'));
        }
        
        $this->log_info("開始啟用金流帳號：{$account->account_name} (ID: $id)", 'admin');
        
        $result = $this->database->activate_account($id);
        
        if (is_wp_error($result)) {
            $this->log_error("啟用金流帳號失敗：{$account->account_name} - {$result->get_error_message()}", 'admin');
            wp_send_json_error($result->get_error_message());
        } else {
            $this->log_success("成功啟用金流帳號：{$account->account_name} (ID: $id)", 'admin');
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
     * AJAX 計算當月金流使用量
     */
    public function ajax_calculate_monthly() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        try {
            $results = $this->database->calculate_monthly_usage();
            
            $message = __('當月金流使用量計算完成！', 'utrust-order-payment-change');
            $details = array();
            $log_info = array();
            
            // 取得日誌檔案資訊
            $log_files = $this->get_log_files_info();
            $log_info[] = sprintf(__('掃描了 %d 個日誌檔案', 'utrust-order-payment-change'), count($log_files));
            
            foreach ($results as $result) {
                $status = $result['update_success'] ? __('成功', 'utrust-order-payment-change') : __('失敗', 'utrust-order-payment-change');
                $details[] = sprintf(
                    __('帳號：%s，MerchantID：%s，當月金額：NT$ %s，更新：%s', 'utrust-order-payment-change'),
                    $result['account_name'],
                    $result['merchant_id'],
                    number_format($result['monthly_amount'], 2),
                    $status
                );
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'details' => $details,
                'log_info' => $log_info,
                'results' => $results
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(__('計算失敗：', 'utrust-order-payment-change') . $e->getMessage());
        }
    }
    
    /**
     * 取得日誌檔案資訊
     */
    private function get_log_files_info() {
        $log_files = array();
        
        // 檢查主要的日誌目錄
        $log_dirs = array(
            WP_CONTENT_DIR . '/uploads/wc-logs/',
            WP_CONTENT_DIR . '/updraft/uploads-old/wc-logs/'
        );
        
        foreach ($log_dirs as $log_dir) {
            if (!is_dir($log_dir)) {
                continue;
            }
            
            $files = glob($log_dir . 'ry_newebpay_gateway-*.log');
            $log_files = array_merge($log_files, $files);
        }
        
        return $log_files;
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
     * 取得預設金流狀態標籤
     */
    public function get_default_status_label($is_default) {
        if ($is_default) {
            return '<span class="utopc-status default">' . __('預設金流', 'utrust-order-payment-change') . '</span>';
        } else {
            return '<span class="utopc-status not-default">' . __('一般金流', 'utrust-order-payment-change') . '</span>';
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
     * AJAX 設定預設金流
     */
    public function ajax_set_default() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $id = intval($_POST['id']);
        $account = $this->database->get_account($id);
        
        if (!$account) {
            $this->log_error("嘗試設定不存在的金流帳號為預設，ID: $id", 'admin');
            wp_send_json_error(__('帳號不存在', 'utrust-order-payment-change'));
        }
        
        $this->log_info("開始設定預設金流：{$account->account_name} (ID: $id)", 'admin');
        
        // 先取消所有帳號的預設狀態
        $this->database->unset_default_accounts();
        $this->log_info("已取消所有帳號的預設狀態", 'admin');
        
        // 設定指定帳號為預設
        $result = $this->database->update_account($id, array('is_default' => 1));
        
        if (is_wp_error($result)) {
            $this->log_error("設定預設金流失敗：{$account->account_name} - {$result->get_error_message()}", 'admin');
            wp_send_json_error($result->get_error_message());
        } else {
            $this->log_success("成功設定預設金流：{$account->account_name} (ID: $id)", 'admin');
            wp_send_json_success(__('預設金流設定成功！', 'utrust-order-payment-change'));
        }
    }
    
    
    /**
     * 公司資訊 Shortcode
     */
    public function company_info_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_company_name' => 'true',
            'show_tax_id' => 'true',
            'show_phone' => 'true',
            'show_customer_service' => 'true',
            'show_business_cooperation' => 'true',
            'show_copyright' => 'true',
            'use_default' => 'false', // 是否強制使用預設資訊
            'class' => 'utopc-company-info'
        ), $atts);
        
        // 預設公司資訊（又上財務規劃顧問股份有限公司）
        $default_company_info = array(
            'company_name' => '又上財務規劃顧問股份有限公司',
            'tax_id' => '83242378',
            'phone' => '02-2509-2809',
            'customer_service' => 'service@utrustcorp.com',
            'business_cooperation' => 'info_bd@utrustcorp.com',
            'copyright_year' => '2025'
        );
        
        // 取得當前金流帳戶資訊
        $company_info = null;
        if ($atts['use_default'] !== 'true') {
            $company_info = $this->database->get_active_account_company_info();
        }
        
        // 如果沒有啟用的帳戶或強制使用預設值，則使用預設資訊
        if ($company_info === null) {
            $company_info = $default_company_info;
        } else {
            // 合併預設資訊，確保所有必要欄位都有值
            $company_info = array_merge($default_company_info, $company_info);
        }
        
        $html = '<div class="' . esc_attr($atts['class']) . '" style="color: white; text-align: left; ">';
        
        // 聯絡我們標題
        $html .= '<div class="contact-title" style="font-size: 16px; color: white;"><strong style="font-size: 14pt;">聯絡我們</strong></div>';
        
        // 版權資訊
        if ($atts['show_copyright'] === 'true') {
            $html .= '<div class="copyright" style="font-size: 16px; color: white;">' . $company_info['copyright_year'] . ' © ' . $company_info['company_name'] . '</div>';
        }
        
        // 電話
        if ($atts['show_phone'] === 'true' && !empty($company_info['phone'])) {
            $html .= '<div class="phone" style="font-size: 16px; color: white;">' . esc_html($company_info['phone']) . '</div>';
        }
        
        // 客服信箱
        if ($atts['show_customer_service'] === 'true' && !empty($company_info['customer_service'])) {
            $html .= '<div class="customer-service" style="font-size: 16px; color: white;">客服：<a href="mailto:' . esc_attr($company_info['customer_service']) . '" style="color: white; text-decoration: none;">' . esc_html($company_info['customer_service']) . '</a></div>';
        }
        
        // 商業合作信箱
        if ($atts['show_business_cooperation'] === 'true' && !empty($company_info['business_cooperation'])) {
            $html .= '<div class="business-cooperation" style="font-size: 16px; color: white;">商業合作：<a href="mailto:' . esc_attr($company_info['business_cooperation']) . '" style="color: white; text-decoration: none;">' . esc_html($company_info['business_cooperation']) . '</a></div>';
        }
        
        // 統一編號
        if ($atts['show_tax_id'] === 'true' && !empty($company_info['tax_id'])) {
            $html .= '<div class="tax-id" style="font-size: 16px; color: white;">統一編號：' . esc_html($company_info['tax_id']) . '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * AJAX 儲存設定
     */
    public function ajax_save_settings() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        // 儲存自動切換設定
        $auto_switch_enabled = isset($_POST['auto_switch_enabled']) ? 'yes' : 'no';
        update_option('utopc_auto_switch_enabled', $auto_switch_enabled);
        
        // 儲存通知設定
        $notifications_enabled = isset($_POST['notifications_enabled']) ? 'yes' : 'no';
        update_option('utopc_enable_notifications', $notifications_enabled);
        
        // 儲存日誌設定
        $logging_enabled = isset($_POST['logging_enabled']) ? 'yes' : 'no';
        update_option('utopc_enable_logging', $logging_enabled);
        
        wp_send_json_success(__('設定儲存成功！', 'utrust-order-payment-change'));
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
    
    /**
     * AJAX 取得日誌
     */
    public function ajax_get_logs() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $logs = get_option('utopc_logs', array());
        
        // 按時間倒序排列（最新的在前）
        $logs = array_reverse($logs);
        
        // 限制顯示數量
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        $logs = array_slice($logs, 0, $limit);
        
        $html = '';
        
        if (empty($logs)) {
            $html = '<p>' . __('目前沒有日誌記錄', 'utrust-order-payment-change') . '</p>';
        } else {
            $html .= '<div class="utopc-logs-list">';
            $html .= '<table class="wp-list-table widefat fixed striped">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th>' . __('時間', 'utrust-order-payment-change') . '</th>';
            $html .= '<th>' . __('等級', 'utrust-order-payment-change') . '</th>';
            $html .= '<th>' . __('模組', 'utrust-order-payment-change') . '</th>';
            $html .= '<th>' . __('訊息', 'utrust-order-payment-change') . '</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';
            
            foreach ($logs as $log) {
                $level_class = strtolower($log['level']);
                $html .= '<tr>';
                $html .= '<td>' . esc_html($log['timestamp']) . '</td>';
                $html .= '<td><span class="utopc-log-level ' . $level_class . '">' . esc_html($log['level']) . '</span></td>';
                $html .= '<td>' . esc_html($log['module']) . '</td>';
                $html .= '<td>' . esc_html($log['message']) . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }
        
        wp_send_json_success(array(
            'html' => $html, 
            'count' => count($logs),
            'logs' => $logs  // 新增原始日誌資料
        ));
    }
    
    /**
     * AJAX 清除日誌
     */
    public function ajax_clear_logs() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        delete_option('utopc_logs');
        
        wp_send_json_success(__('日誌已清除', 'utrust-order-payment-change'));
    }
    
    /**
     * 記錄日誌
     */
    private function log_message($level, $message, $module = 'admin') {
        if (!get_option('utopc_enable_logging', 'yes')) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'module' => $module
        );
        
        $logs = get_option('utopc_logs', array());
        $logs[] = $log_entry;
        
        // 限制日誌數量，保留最近 1000 筆
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('utopc_logs', $logs);
    }
    
    private function log_success($message, $module = 'admin') {
        $this->log_message('SUCCESS', $message, $module);
    }
    
    private function log_error($message, $module = 'admin') {
        $this->log_message('ERROR', $message, $module);
    }
    
    private function log_info($message, $module = 'admin') {
        $this->log_message('INFO', $message, $module);
    }
    
    private function log_warning($message, $module = 'admin') {
        $this->log_message('WARNING', $message, $module);
    }
    
    /**
     * AJAX 更新舊訂單的金流公司資訊
     */
    public function ajax_update_old_orders() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        try {
            $result = $this->update_old_orders_batch($batch_size, $offset);
            
            wp_send_json_success(array(
                'message' => sprintf(__('已處理 %d 筆訂單，更新了 %d 筆', 'utrust-order-payment-change'), 
                    $result['processed'], $result['updated']),
                'processed' => $result['processed'],
                'updated' => $result['updated'],
                'has_more' => $result['has_more'],
                'next_offset' => $result['next_offset']
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(__('更新失敗：', 'utrust-order-payment-change') . $e->getMessage());
        }
    }
    
    /**
     * 批量更新舊訂單
     */
    private function update_old_orders_batch($batch_size = 50, $offset = 0) {
        global $wpdb;
        
        // 取得目前啟用的金流帳戶
        $active_account = $this->database->get_active_account();
        if (!$active_account) {
            throw new Exception(__('沒有啟用的金流帳戶', 'utrust-order-payment-change'));
        }
        
        // 查詢沒有金流公司資訊的 NewebPay 訂單
        $query = $wpdb->prepare("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
            LEFT JOIN {$wpdb->postmeta} pm_company ON p.ID = pm_company.post_id AND pm_company.meta_key = '_utopc_payment_company_name'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND pm_method.meta_key = '_payment_method'
            AND pm_method.meta_value LIKE 'ry_newebpay%'
            AND (pm_company.meta_value IS NULL OR pm_company.meta_value = '')
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", $batch_size, $offset);
        
        $order_ids = $wpdb->get_col($query);
        
        $updated_count = 0;
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            
            // 更新訂單的金流公司資訊
            $order->update_meta_data('_utopc_payment_account_id', $active_account->id);
            $order->update_meta_data('_utopc_payment_account_name', $active_account->account_name);
            $order->update_meta_data('_utopc_payment_merchant_id', $active_account->merchant_id);
            
            if (!empty($active_account->company_name)) {
                $order->update_meta_data('_utopc_payment_company_name', $active_account->company_name);
            }
            
            $order->save();
            $updated_count++;
        }
        
        // 檢查是否還有更多訂單需要處理
        $total_query = $wpdb->prepare("
            SELECT COUNT(p.ID) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
            LEFT JOIN {$wpdb->postmeta} pm_company ON p.ID = pm_company.post_id AND pm_company.meta_key = '_utopc_payment_company_name'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND pm_method.meta_key = '_payment_method'
            AND pm_method.meta_value LIKE 'ry_newebpay%'
            AND (pm_company.meta_value IS NULL OR pm_company.meta_value = '')
        ");
        
        $total_remaining = $wpdb->get_var($total_query);
        $has_more = $total_remaining > 0;
        $next_offset = $offset + count($order_ids);
        
        // 記錄日誌
        $this->log_success("批量更新舊訂單：處理了 " . count($order_ids) . " 筆，更新了 {$updated_count} 筆");
        
        return array(
            'processed' => count($order_ids),
            'updated' => $updated_count,
            'has_more' => $has_more,
            'next_offset' => $next_offset
        );
    }
}
