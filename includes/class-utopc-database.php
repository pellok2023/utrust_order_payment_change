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
            company_name varchar(255) DEFAULT NULL,
            tax_id varchar(20) DEFAULT NULL,
            address text DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 0,
            is_default tinyint(1) NOT NULL DEFAULT 0,
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
        } else {
            // 檢查並升級現有資料表結構
            self::upgrade_table_if_needed();
        }
    }
    
    /**
     * 升級資料表結構（如果需要）
     */
    public static function upgrade_table_if_needed() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'utopc_payment_accounts';
        
        // 檢查新欄位是否存在
        $columns_to_add = array(
            'company_name' => 'varchar(255) DEFAULT NULL',
            'tax_id' => 'varchar(20) DEFAULT NULL', 
            'address' => 'text DEFAULT NULL',
            'phone' => 'varchar(50) DEFAULT NULL',
            'is_default' => 'tinyint(1) NOT NULL DEFAULT 0'
        );
        
        foreach ($columns_to_add as $column_name => $column_definition) {
            $column_exists = $wpdb->get_results($wpdb->prepare(
                "SHOW COLUMNS FROM $table_name LIKE %s",
                $column_name
            ));
            
            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE $table_name ADD COLUMN $column_name $column_definition");
                error_log("UTOPC: 已添加欄位 $column_name 到資料表 $table_name");
            }
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
            'company_name' => '',
            'tax_id' => '',
            'address' => '',
            'phone' => '',
            'is_active' => 0,
            'is_default' => 0
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
        
        // 如果設為預設金流，先取消其他帳號的預設狀態
        if ($data['is_default']) {
            $this->unset_default_accounts();
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
                'company_name' => sanitize_text_field($data['company_name']),
                'tax_id' => sanitize_text_field($data['tax_id']),
                'address' => sanitize_textarea_field($data['address']),
                'phone' => sanitize_text_field($data['phone']),
                'is_active' => intval($data['is_active']),
                'is_default' => intval($data['is_default'])
            ),
            array('%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%d')
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
        
        // 如果設為預設金流，先取消其他帳號的預設狀態
        if (isset($data['is_default']) && $data['is_default']) {
            $this->unset_default_accounts();
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
        
        if (isset($data['company_name'])) {
            $update_data['company_name'] = sanitize_text_field($data['company_name']);
            $update_format[] = '%s';
        }
        
        if (isset($data['tax_id'])) {
            $update_data['tax_id'] = sanitize_text_field($data['tax_id']);
            $update_format[] = '%s';
        }
        
        if (isset($data['address'])) {
            $update_data['address'] = sanitize_textarea_field($data['address']);
            $update_format[] = '%s';
        }
        
        if (isset($data['phone'])) {
            $update_data['phone'] = sanitize_text_field($data['phone']);
            $update_format[] = '%s';
        }
        
        if (isset($data['is_active'])) {
            $update_data['is_active'] = intval($data['is_active']);
            $update_format[] = '%d';
        }
        
        if (isset($data['is_default'])) {
            $update_data['is_default'] = intval($data['is_default']);
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
     * 取得預設金流帳號
     */
    public function get_default_account() {
        global $wpdb;
        
        return $wpdb->get_row(
            "SELECT * FROM {$this->table_name} WHERE is_default = 1 LIMIT 1"
        );
    }
    
    /**
     * 取得目前啟用帳戶的公司資訊
     */
    public function get_active_account_company_info() {
        global $wpdb;
        
        $account = $wpdb->get_row(
            "SELECT company_name, tax_id, address, phone, account_name, merchant_id 
             FROM {$this->table_name} 
             WHERE is_active = 1 
             LIMIT 1"
        );
        
        if (!$account) {
            return null;
        }
        
        return array(
            'company_name' => $account->company_name ?: '',
            'tax_id' => $account->tax_id ?: '',
            'address' => $account->address ?: '',
            'phone' => $account->phone ?: '',
            'account_name' => $account->account_name ?: '',
            'merchant_id' => $account->merchant_id ?: ''
        );
    }
    
    /**
     * 取得下一個可用的金流帳號
     * 當所有金流都達到上限時，選擇預設金流
     */
    public function get_next_available_account() {
        global $wpdb;
        
        // 首先嘗試找到未達到上限的帳號
        $available_account = $wpdb->get_row(
            "SELECT * FROM {$this->table_name} 
             WHERE monthly_amount < amount_limit 
             ORDER BY monthly_amount ASC 
             LIMIT 1"
        );
        
        // 如果找到可用的帳號，直接返回
        if ($available_account) {
            return $available_account;
        }
        
        // 如果所有帳號都達到上限，返回預設金流帳號
        $default_account = $this->get_default_account();
        if ($default_account) {
            error_log('UTOPC: 所有金流帳號都達到上限，使用預設金流帳號：' . $default_account->account_name);
            return $default_account;
        }
        
        // 如果沒有預設金流帳號，返回第一個帳號作為最後選擇
        $fallback_account = $wpdb->get_row(
            "SELECT * FROM {$this->table_name} 
             ORDER BY created_at ASC 
             LIMIT 1"
        );
        
        if ($fallback_account) {
            error_log('UTOPC: 沒有預設金流帳號，使用第一個帳號作為備選：' . $fallback_account->account_name);
        }
        
        return $fallback_account;
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
     * 取消所有帳號的預設狀態
     */
    public function unset_default_accounts() {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('is_default' => 0),
            array('is_default' => 1),
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
     * 計算當月訂單金流使用量統計
     * 根據 RY WooCommerce Tools 的日誌檔案來統計
     */
    public function calculate_monthly_usage() {
        // 計算一個月前的日期
        $one_month_ago = date('Y-m-d H:i:s', strtotime('-1 month'));
        
        // 取得所有金流帳號
        $accounts = $this->get_all_accounts();
        $results = array();
        
        // 解析日誌檔案
        $log_data = $this->parse_newebpay_logs($one_month_ago);
        
        foreach ($accounts as $account) {
            $merchant_id = $account->merchant_id;
            $account_id = $account->id;
            
            // 根據 merchant_id 計算總金額
            $total_amount = $this->calculate_account_usage_from_logs($merchant_id, $log_data);
            
            // 更新該帳號的 monthly_amount
            $update_result = $this->update_account_monthly_amount($account_id, $total_amount);
            
            $results[] = array(
                'account_id' => $account_id,
                'account_name' => $account->account_name,
                'merchant_id' => $merchant_id,
                'monthly_amount' => $total_amount,
                'update_success' => $update_result
            );
        }
        
        return $results;
    }
    
    /**
     * 解析 NewebPay 日誌檔案
     */
    private function parse_newebpay_logs($start_date) {
        $log_data = array();
        
        // 取得日誌檔案路徑
        $log_files = $this->get_newebpay_log_files();
        
        foreach ($log_files as $log_file) {
            if (!file_exists($log_file)) {
                continue;
            }
            
            $file_content = file_get_contents($log_file);
            $lines = explode("\n", $file_content);
            
            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }
                
                // 解析日誌行
                $log_entry = $this->parse_log_line($line, $start_date);
                if ($log_entry) {
                    $log_data[] = $log_entry;
                }
            }
        }
        
        return $log_data;
    }
    
    /**
     * 取得 NewebPay 日誌檔案列表
     */
    private function get_newebpay_log_files() {
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
     * 解析單一日誌行
     */
    private function parse_log_line($line, $start_date) {
        // 檢查是否包含 "IPN decrypt request"
        if (strpos($line, 'IPN decrypt request') === false) {
            return null;
        }
        
        // 解析時間戳
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2})/', $line, $matches)) {
            return null;
        }
        
        $log_time = $matches[1];
        $log_timestamp = strtotime($log_time);
        $start_timestamp = strtotime($start_date);
        
        // 檢查是否在時間範圍內
        if ($log_timestamp < $start_timestamp) {
            return null;
        }
        
        // 解析日誌內容
        $log_entry = $this->extract_log_data($line);
        if ($log_entry) {
            $log_entry['timestamp'] = $log_time;
            return $log_entry;
        }
        
        return null;
    }
    
    /**
     * 從日誌行中提取資料
     */
    private function extract_log_data($line) {
        // 尋找 MerchantID
        if (!preg_match("/'MerchantID' => '([^']+)'/", $line, $merchant_matches)) {
            return null;
        }
        
        $merchant_id = $merchant_matches[1];
        
        // 尋找 Amt (金額)
        if (!preg_match("/'Amt' => (\d+)/", $line, $amt_matches)) {
            return null;
        }
        
        $amount = intval($amt_matches[1]);
        
        // 尋找 PayTime
        $pay_time = null;
        if (preg_match("/'PayTime' => '([^']+)'/", $line, $paytime_matches)) {
            $pay_time = $paytime_matches[1];
        }
        
        // 尋找 TradeNo
        $trade_no = null;
        if (preg_match("/'TradeNo' => '([^']+)'/", $line, $tradeno_matches)) {
            $trade_no = $tradeno_matches[1];
        }
        
        // 尋找 MerchantOrderNo
        $merchant_order_no = null;
        if (preg_match("/'MerchantOrderNo' => '([^']+)'/", $line, $order_matches)) {
            $merchant_order_no = $order_matches[1];
        }
        
        return array(
            'merchant_id' => $merchant_id,
            'amount' => $amount,
            'pay_time' => $pay_time,
            'trade_no' => $trade_no,
            'merchant_order_no' => $merchant_order_no
        );
    }
    
    /**
     * 根據日誌資料計算指定 merchant_id 的使用量
     */
    private function calculate_account_usage_from_logs($merchant_id, $log_data) {
        $total_amount = 0;
        $processed_trades = array(); // 避免重複計算同一筆交易
        
        foreach ($log_data as $log_entry) {
            // 檢查是否為指定的 merchant_id
            if ($log_entry['merchant_id'] !== $merchant_id) {
                continue;
            }
            
            // 使用 TradeNo 來避免重複計算
            $trade_no = $log_entry['trade_no'];
            if ($trade_no && in_array($trade_no, $processed_trades)) {
                continue;
            }
            
            // 累加金額
            $total_amount += $log_entry['amount'];
            
            // 記錄已處理的交易
            if ($trade_no) {
                $processed_trades[] = $trade_no;
            }
        }
        
        return $total_amount;
    }
    
    /**
     * 取得指定 merchant_id 在指定時間範圍內的訂單總金額
     */
    private function get_monthly_orders_amount($merchant_id, $start_date) {
        global $wpdb;
        
        // 查詢使用 NewebPay 相關金流的訂單
        $newebpay_gateways = array(
            'ry_newebpay',
            'ry_newebpay_atm', 
            'ry_newebpay_cc',
            'ry_newebpay_cvs',
            'ry_newebpay_webatm'
        );
        
        $gateway_placeholders = implode(',', array_fill(0, count($newebpay_gateways), '%s'));
        
        // 查詢訂單，根據 payment method 和 meta 資料中的 merchant 資訊
        // 使用 _order_total 而不是 post_excerpt 來取得正確的訂單金額
        $query = $wpdb->prepare("
            SELECT SUM(CAST(pm_total.meta_value AS DECIMAL(15,2))) as total_amount
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
            INNER JOIN {$wpdb->postmeta} pm_merchant ON p.ID = pm_merchant.post_id
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND p.post_date >= %s
            AND pm_method.meta_key = '_payment_method'
            AND pm_method.meta_value IN ($gateway_placeholders)
            AND pm_merchant.meta_key = '_newebpay_MerchantOrderNo'
            AND pm_merchant.meta_value LIKE %s
            AND pm_total.meta_key = '_order_total'
        ", array_merge([$start_date], $newebpay_gateways, ["%{$merchant_id}%"]));
        
        $result = $wpdb->get_var($query);
        
        // 如果沒有找到，嘗試另一種查詢方式
        if (!$result) {
            $result = $this->get_monthly_orders_amount_alternative($merchant_id, $start_date);
        }
        
        return floatval($result ?: 0);
    }
    
    /**
     * 替代查詢方式：根據當前 merchant_id 設定查詢
     * 這個方法會查詢在指定時間範圍內，當 merchant_id 為當前值時的所有訂單
     */
    private function get_monthly_orders_amount_alternative($merchant_id, $start_date) {
        global $wpdb;
        
        // 查詢在指定時間範圍內，當 RY_WT_newebpay_gateway_MerchantID 為當前 merchant_id 時的訂單
        // 由於我們無法直接從訂單中取得當時使用的 merchant_id，我們採用以下策略：
        // 1. 查詢所有 NewebPay 訂單
        // 2. 根據訂單時間和當前 merchant_id 的啟用時間來判斷
        
        $query = $wpdb->prepare("
            SELECT SUM(CAST(pm_total.meta_value AS DECIMAL(15,2))) as total_amount
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
            INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-on-hold')
            AND p.post_date >= %s
            AND pm_method.meta_key = '_payment_method'
            AND pm_method.meta_value LIKE 'ry_newebpay%'
            AND pm_total.meta_key = '_order_total'
        ", $start_date);
        
        $result = $wpdb->get_var($query);
        
        // 如果查詢到結果，我們需要進一步過濾
        // 這裡我們假設如果當前 merchant_id 是啟用的，那麼最近的訂單都使用這個 merchant_id
        if ($result) {
            // 檢查當前 merchant_id 是否為啟用狀態
            $current_merchant_id = get_option('RY_WT_newebpay_gateway_MerchantID', '');
            if ($current_merchant_id === $merchant_id) {
                // 如果當前 merchant_id 是啟用的，返回查詢結果
                return floatval($result);
            } else {
                // 如果不是當前啟用的 merchant_id，返回 0
                // 因為我們無法準確判斷歷史訂單使用的是哪個 merchant_id
                return 0;
            }
        }
        
        return floatval($result ?: 0);
    }
    
    /**
     * 更新指定帳號的當月累計金額
     */
    private function update_account_monthly_amount($account_id, $amount) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->table_name,
            array('monthly_amount' => $amount),
            array('id' => $account_id),
            array('%f'),
            array('%d')
        );
        
        return $result !== false;
    }
    
    /**
     * 取得資料表名稱
     */
    public function get_table_name() {
        return $this->table_name;
    }
}
