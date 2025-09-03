<?php
/**
 * 資料庫模組
 * 負責建立資料表和處理資料庫操作
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Database {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'utopc_payment_accounts';
    }
    
    /**
     * 檢查資料表是否存在
     */
    public static function table_exists() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'utopc_payment_accounts';
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        
        return $result === $table_name;
    }
    
    /**
     * 建立資料表
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'utopc_payment_accounts';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            account_name varchar(255) NOT NULL,
            merchant_id varchar(255) NOT NULL,
            hash_key varchar(255) NOT NULL,
            hash_iv varchar(255) NOT NULL,
            amount_limit decimal(15,2) NOT NULL DEFAULT 0.00,
            monthly_amount decimal(15,2) NOT NULL DEFAULT 0.00,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // 建立索引
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_is_active ON $table_name (is_active)");
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_monthly_amount ON $table_name (monthly_amount)");
    }
    
    /**
     * 確保資料表存在，如果不存在則建立
     */
    public static function ensure_tables_exist() {
        if (!self::table_exists()) {
            self::create_tables();
        }
    }
    
    /**
     * 檢查並創建預設金流帳號
     */
    public static function check_and_create_default_account() {
        $instance = self::get_instance();
        
        // 檢查是否已有金流帳號
        if ($instance->has_accounts()) {
            error_log('UTOPC: 已存在金流帳號，跳過預設帳號創建');
            return;
        }
        
        // 取得現有的 NewebPay 設定（支援多種來源）
        $newebpay_settings = $instance->get_existing_newebpay_settings();
        
        if (empty($newebpay_settings['merchant_id']) || 
            empty($newebpay_settings['hash_key']) || 
            empty($newebpay_settings['hash_iv'])) {
            error_log('UTOPC: 未找到有效的 NewebPay 設定，跳過預設帳號創建');
            return;
        }
        
        // 檢查是否為測試模式
        $is_test_mode = $instance->is_newebpay_test_mode();
        
        // 創建預設帳號
        $default_account_data = array(
            'account_name' => $is_test_mode ? '預設金流帳號 (測試模式)' : '預設金流帳號',
            'merchant_id' => $newebpay_settings['merchant_id'],
            'hash_key' => $newebpay_settings['hash_key'],
            'hash_iv' => $newebpay_settings['hash_iv'],
            'amount_limit' => 100000.00, // 預設金額上限 10 萬
            'monthly_amount' => 0.00,
            'is_active' => 1 // 設為啟用
        );
        
        $result = $instance->add_account($default_account_data);
        
        if (!is_wp_error($result)) {
            error_log('UTOPC: 已自動創建預設金流帳號，ID: ' . $result . 
                     ' (來源: ' . $newebpay_settings['source'] . 
                     ($is_test_mode ? ', 測試模式' : '') . ')');
            
            // 設定通知標記
            update_option('utopc_default_account_created', true);
            update_option('utopc_default_account_created_time', current_time('mysql'));
            update_option('utopc_default_account_source', $newebpay_settings['source']);
        } else {
            error_log('UTOPC: 創建預設金流帳號失敗: ' . $result->get_error_message());
        }
    }
    
    /**
     * 檢查是否已有金流帳號
     */
    private function has_accounts() {
        global $wpdb;
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        return $count > 0;
    }
    
    /**
     * 檢查 RY WooCommerce Tools 是否啟用
     */
    private function is_ry_woocommerce_tools_active() {
        // 檢查類別是否存在
        if (class_exists('RY_WooCommerce_Tools')) {
            return true;
        }
        
        // 檢查函數是否存在
        if (function_exists('ry_woocommerce_tools_init')) {
            return true;
        }
        
        // 檢查外掛是否啟用（通過檢查設定選項）
        $merchant_id = get_option('RY_WT_newebpay_gateway_MerchantID', '');
        if (!empty($merchant_id)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 檢查是否為 NewebPay 測試模式
     */
    private function is_newebpay_test_mode() {
        // 檢查 RY WooCommerce Tools 的測試模式設定
        if ($this->is_ry_woocommerce_tools_active()) {
            $test_mode = get_option('RY_WT_newebpay_gateway_testmode', 'no');
            if ($test_mode === 'yes') {
                return true;
            }
        }
        
        // 檢查其他可能的測試模式設定
        $test_mode_options = array(
            'newebpay_test_mode',
            'woocommerce_newebpay_test_mode',
            'newebpay_gateway_test_mode'
        );
        
        foreach ($test_mode_options as $option) {
            $test_mode = get_option($option, 'no');
            if ($test_mode === 'yes' || $test_mode === '1' || $test_mode === true) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 取得現有的 NewebPay 設定
     */
    private function get_existing_newebpay_settings() {
        $settings = array(
            'merchant_id' => '',
            'hash_key' => '',
            'hash_iv' => '',
            'source' => 'unknown'
        );
        
        // 優先檢查 RY WooCommerce Tools
        if ($this->is_ry_woocommerce_tools_active()) {
            $ry_merchant_id = get_option('RY_WT_newebpay_gateway_MerchantID', '');
            $ry_hash_key = get_option('RY_WT_newebpay_gateway_HashKey', '');
            $ry_hash_iv = get_option('RY_WT_newebpay_gateway_HashIV', '');
            
            if (!empty($ry_merchant_id) && !empty($ry_hash_key) && !empty($ry_hash_iv)) {
                $settings = array(
                    'merchant_id' => $ry_merchant_id,
                    'hash_key' => $ry_hash_key,
                    'hash_iv' => $ry_hash_iv,
                    'source' => 'RY WooCommerce Tools'
                );
                return $settings;
            }
        }
        
        // 檢查其他可能的 NewebPay 設定來源
        $other_sources = array(
            // 其他外掛可能的設定鍵
            'newebpay_merchant_id' => 'Other Plugin',
            'woocommerce_newebpay_merchant_id' => 'WooCommerce Settings',
            'newebpay_gateway_merchant_id' => 'Generic Gateway'
        );
        
        foreach ($other_sources as $option_key => $source_name) {
            $merchant_id = get_option($option_key, '');
            $hash_key = get_option(str_replace('merchant_id', 'hash_key', $option_key), '');
            $hash_iv = get_option(str_replace('merchant_id', 'hash_iv', $option_key), '');
            
            if (!empty($merchant_id) && !empty($hash_key) && !empty($hash_iv)) {
                $settings = array(
                    'merchant_id' => $merchant_id,
                    'hash_key' => $hash_key,
                    'hash_iv' => $hash_iv,
                    'source' => $source_name
                );
                break;
            }
        }
        
        return $settings;
    }
    
    /**
     * 新增金流帳號
     */
    public function add_account($data) {
        global $wpdb;
        
        $defaults = array(
            'account_name' => '',
            'merchant_id' => '',
            'hash_key' => '',
            'hash_iv' => '',
            'amount_limit' => 0.00,
            'monthly_amount' => 0.00,
            'is_active' => 0
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // 驗證必填欄位
        if (empty($data['account_name']) || empty($data['merchant_id']) || 
            empty($data['hash_key']) || empty($data['hash_iv'])) {
            return new WP_Error('missing_fields', '必填欄位不能為空');
        }
        
        // 如果設為啟用，先停用其他帳號
        if ($data['is_active']) {
            $this->deactivate_all_accounts();
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'account_name' => sanitize_text_field($data['account_name']),
                'merchant_id' => sanitize_text_field($data['merchant_id']),
                'hash_key' => sanitize_text_field($data['hash_key']),
                'hash_iv' => sanitize_text_field($data['hash_iv']),
                'amount_limit' => floatval($data['amount_limit']),
                'monthly_amount' => floatval($data['monthly_amount']),
                'is_active' => intval($data['is_active'])
            ),
            array('%s', '%s', '%s', '%s', '%f', '%f', '%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', '資料庫錯誤：' . $wpdb->last_error);
        }
        
        $account_id = $wpdb->insert_id;
        
        // 如果設為啟用，同步更新 RY WooCommerce Tools 的設定
        if ($data['is_active']) {
            $new_account = $this->get_account($account_id);
            if ($new_account) {
                $this->sync_newebpay_settings($new_account);
            }
        }
        
        return $account_id;
    }
    
    /**
     * 更新金流帳號
     */
    public function update_account($id, $data) {
        global $wpdb;
        
        $account = $this->get_account($id);
        if (!$account) {
            return new WP_Error('not_found', '帳號不存在');
        }
        
        // 如果設為啟用，先停用其他帳號
        if (isset($data['is_active']) && $data['is_active']) {
            $this->deactivate_all_accounts();
        }
        
        $update_data = array();
        $update_format = array();
        
        if (isset($data['account_name'])) {
            $update_data['account_name'] = sanitize_text_field($data['account_name']);
            $update_format[] = '%s';
        }
        
        if (isset($data['merchant_id'])) {
            $update_data['merchant_id'] = sanitize_text_field($data['merchant_id']);
            $update_format[] = '%s';
        }
        
        if (isset($data['hash_key'])) {
            $update_data['hash_key'] = sanitize_text_field($data['hash_key']);
            $update_format[] = '%s';
        }
        
        if (isset($data['hash_iv'])) {
            $update_data['hash_iv'] = sanitize_text_field($data['hash_iv']);
            $update_format[] = '%s';
        }
        
        if (isset($data['amount_limit'])) {
            $update_data['amount_limit'] = floatval($data['amount_limit']);
            $update_format[] = '%f';
        }
        
        if (isset($data['monthly_amount'])) {
            $update_data['monthly_amount'] = floatval($data['monthly_amount']);
            $update_format[] = '%f';
        }
        
        if (isset($data['is_active'])) {
            $update_data['is_active'] = intval($data['is_active']);
            $update_format[] = '%d';
        }
        
        if (empty($update_data)) {
            return new WP_Error('no_data', '沒有資料需要更新');
        }
        
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            array('id' => $id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', '資料庫錯誤：' . $wpdb->last_error);
        }
        
        // 如果設為啟用，同步更新 RY WooCommerce Tools 的設定
        if (isset($data['is_active']) && $data['is_active']) {
            $updated_account = $this->get_account($id);
            if ($updated_account) {
                $this->sync_newebpay_settings($updated_account);
            }
        }
        
        return true;
    }
    
    /**
     * 刪除金流帳號
     */
    public function delete_account($id) {
        global $wpdb;
        
        $account = $this->get_account($id);
        if (!$account) {
            return new WP_Error('not_found', '帳號不存在');
        }
        
        // 檢查是否為最後一筆帳號
        $total_accounts = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        
        // 如果是啟用中的帳號且不是最後一筆，不允許刪除
        if ($account->is_active && $total_accounts > 1) {
            return new WP_Error('active_account', '啟用中的帳號不能刪除，請先切換到其他帳號');
        }
        
        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $id),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', '資料庫錯誤：' . $wpdb->last_error);
        }
        
        return true;
    }
    
    /**
     * 取得單一金流帳號
     */
    public function get_account($id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
    }
    
    /**
     * 取得所有金流帳號
     */
    public function get_all_accounts() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY is_active DESC, created_at ASC"
        );
    }
    
    /**
     * 取得目前啟用的金流帳號
     */
    public function get_active_account() {
        global $wpdb;
        
        return $wpdb->get_row(
            "SELECT * FROM {$this->table_name} WHERE is_active = 1 LIMIT 1"
        );
    }
    
    /**
     * 取得下一個可用的金流帳號
     */
    public function get_next_available_account() {
        global $wpdb;
        
        return $wpdb->get_row(
            "SELECT * FROM {$this->table_name} 
             WHERE monthly_amount < amount_limit 
             ORDER BY monthly_amount ASC 
             LIMIT 1"
        );
    }
    
    /**
     * 停用所有帳號
     */
    public function deactivate_all_accounts() {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('is_active' => 0),
            array('is_active' => 1),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * 啟用指定帳號
     */
    public function activate_account($id) {
        // 先停用所有帳號
        $this->deactivate_all_accounts();
        
        // 啟用指定帳號
        $result = $this->update_account($id, array('is_active' => 1));
        
        // 如果啟用成功，同步更新 RY WooCommerce Tools 的設定
        if (!is_wp_error($result)) {
            $account = $this->get_account($id);
            if ($account) {
                $this->sync_newebpay_settings($account);
            }
        }
        
        return $result;
    }
    
    /**
     * 更新帳號的當月累計金額
     */
    public function update_monthly_amount($id, $amount) {
        global $wpdb;
        
        $account = $this->get_account($id);
        if (!$account) {
            return false;
        }
        
        $new_amount = $account->monthly_amount + $amount;
        
        return $wpdb->update(
            $this->table_name,
            array('monthly_amount' => $new_amount),
            array('id' => $id),
            array('%f'),
            array('%d')
        );
    }
    
    /**
     * 重置所有帳號的當月累計金額
     */
    public function reset_monthly_amounts() {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('monthly_amount' => 0.00),
            array(),
            array('%f'),
            array()
        );
    }
    
    /**
     * 檢查帳號是否達到金額上限
     */
    public function is_account_limit_reached($id) {
        $account = $this->get_account($id);
        if (!$account) {
            return false;
        }
        
        return $account->monthly_amount >= $account->amount_limit;
    }
    
    /**
     * 同步更新 RY WooCommerce Tools 的 NewebPay 設定
     */
    public function sync_newebpay_settings($account) {
        if (!$account) {
            return false;
        }
        
        // 更新 RY WooCommerce Tools 的設定
        $updated = true;
        
        // 更新 MerchantID
        if (update_option('RY_WT_newebpay_gateway_MerchantID', $account->merchant_id) === false) {
            $updated = false;
        }
        
        // 更新 HashKey
        if (update_option('RY_WT_newebpay_gateway_HashKey', $account->hash_key) === false) {
            $updated = false;
        }
        
        // 更新 HashIV
        if (update_option('RY_WT_newebpay_gateway_HashIV', $account->hash_iv) === false) {
            $updated = false;
        }
        
        if ($updated) {
            error_log('UTOPC: 已同步更新 RY WooCommerce Tools NewebPay 設定 - 帳號: ' . $account->account_name);
        } else {
            error_log('UTOPC: 同步更新 RY WooCommerce Tools NewebPay 設定失敗 - 帳號: ' . $account->account_name);
        }
        
        return $updated;
    }
    
    /**
     * 取得資料表名稱
     */
    public function get_table_name() {
        return $this->table_name;
    }
}
