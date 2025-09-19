<?php
/**
 * 金流切換模組
 * 負責自動切換金流帳號和更新 RY WooCommerce Tools 設定
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Payment_Switcher {
    
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
        
        // 註冊 AJAX 處理
        add_action('wp_ajax_utopc_manual_switch', array($this, 'ajax_manual_switch'));
    }
    
    /**
     * 自動切換到下一個可用的金流帳號
     */
    public function switch_to_next_account() {
        // 檢查是否啟用自動切換
        if (get_option('utopc_auto_switch_enabled', 'yes') !== 'yes') {
            $this->log_info('自動切換功能已停用');
            return false;
        }
        
        // 取得下一個可用的金流帳號
        $next_account = $this->database->get_next_available_account();
        
        if (!$next_account) {
            $this->log_warning('沒有可用的金流帳號可以切換');
            return false;
        }
        
        // 檢查是否為預設金流帳號
        $is_default_account = $next_account->is_default == 1;
        $reason = $is_default_account ? '使用預設金流（所有帳號都達到上限）' : '自動切換';
        
        // 執行切換
        $result = $this->perform_switch($next_account, $reason);
        
        if ($result) {
            $this->log_success("成功切換到金流帳號：{$next_account->account_name}（{$reason}）");
            
            // 發送通知
            $this->send_switch_notification($next_account, $reason);
            
            return true;
        } else {
            $this->log_error("切換到金流帳號 {$next_account->account_name} 失敗");
            return false;
        }
    }
    
    /**
     * 執行金流帳號切換
     */
    private function perform_switch($account, $reason = 'auto_switch') {
        try {
            // 1. 停用目前啟用的帳號
            $this->database->deactivate_all_accounts();
            
            // 2. 啟用新的帳號
            $activate_result = $this->database->activate_account($account->id);
            
            if (is_wp_error($activate_result)) {
                throw new Exception($activate_result->get_error_message());
            }
            
            // 3. 更新 RY WooCommerce Tools 設定
            $ry_update_result = $this->update_ry_woocommerce_settings($account);
            
            if (!$ry_update_result) {
                throw new Exception('更新 RY WooCommerce Tools 設定失敗');
            }
            
            // 4. 記錄切換歷史
            $this->record_switch_history($account, $reason);
            
            return true;
            
        } catch (Exception $e) {
            $this->log_error('切換金流帳號時發生錯誤：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新 RY WooCommerce Tools 設定
     */
    private function update_ry_woocommerce_settings($account) {
        try {
            // 更新 RY_WT_newebpay_gateway 全域設定
            $this->update_newebpay_gateway_settings($account);
            
            // 更新各金流方式的設定
            $this->update_payment_methods_settings($account);
            
            // 清除快取
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            $this->log_info("已更新 RY WooCommerce Tools 金流設定");
            return true;
            
        } catch (Exception $e) {
            $this->log_error('更新 RY WooCommerce Tools 設定時發生錯誤：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 更新 RY_WT_newebpay_gateway 全域設定
     */
    private function update_newebpay_gateway_settings($account) {
        // 更新全域 newebpay 設定
        update_option('RY_WT_newebpay_gateway_MerchantID', $account->merchant_id);
        update_option('RY_WT_newebpay_gateway_HashKey', $account->hash_key);
        update_option('RY_WT_newebpay_gateway_HashIV', $account->hash_iv);
        
        $this->log_info("已更新 RY_WT_newebpay_gateway 全域設定");
    }
    
    /**
     * 更新各金流方式的設定
     */
    private function update_payment_methods_settings($account) {
        // 更新金流設定
        $payment_settings = array(
            'merchant_id' => $account->merchant_id,
            'hash_key' => $account->hash_key,
            'hash_iv' => $account->hash_iv
        );
        
        // 更新各金流方式的設定
        $payment_methods = array(
            'ry_newebpay',
            'ry_newebpay_atm',
            'ry_newebpay_cc',
            'ry_newebpay_cvs',
            'ry_newebpay_webatm'
        );
        
        foreach ($payment_methods as $method) {
            $this->update_payment_method_settings($method, $payment_settings);
        }
        
        $this->log_info("已更新各金流方式的設定");
    }
    
    /**
     * 更新特定金流方式的設定
     */
    private function update_payment_method_settings($method, $settings) {
        $option_name = 'woocommerce_' . $method . '_settings';
        $current_settings = get_option($option_name, array());
        
        // 更新金流設定
        $current_settings['merchant_id'] = $settings['merchant_id'];
        $current_settings['hash_key'] = $settings['hash_key'];
        $current_settings['hash_iv'] = $settings['hash_iv'];
        
        // 儲存設定
        update_option($option_name, $current_settings);
    }
    
    /**
     * 記錄切換歷史
     */
    private function record_switch_history($account, $reason = 'auto_switch') {
        $history = get_option('utopc_switch_history', array());
        
        $history_entry = array(
            'timestamp' => current_time('mysql'),
            'account_id' => $account->id,
            'account_name' => $account->account_name,
            'reason' => $reason,
            'is_default' => $account->is_default,
            'monthly_amount' => $account->monthly_amount,
            'amount_limit' => $account->amount_limit
        );
        
        $history[] = $history_entry;
        
        // 限制歷史記錄數量，保留最近 100 筆
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        
        update_option('utopc_switch_history', $history);
    }
    
    /**
     * 手動切換到指定帳號
     */
    public function manual_switch_to_account($account_id) {
        $account = $this->database->get_account($account_id);
        
        if (!$account) {
            return new WP_Error('not_found', '指定的金流帳號不存在');
        }
        
        // 執行切換
        $result = $this->perform_switch($account);
        
        if ($result) {
            $this->log_success("手動切換到金流帳號：{$account->account_name}");
            return true;
        } else {
            return new WP_Error('switch_failed', '切換失敗');
        }
    }
    
    /**
     * AJAX 手動切換
     */
    public function ajax_manual_switch() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $account_id = intval($_POST['account_id']);
        $result = $this->manual_switch_to_account($account_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('金流帳號切換成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * 發送切換通知
     */
    private function send_switch_notification($account, $reason = '自動切換') {
        // 檢查是否啟用通知
        if (get_option('utopc_enable_notifications', 'yes') !== 'yes') {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf('[%s] 金流帳號自動切換通知', $site_name);
        
        $message = sprintf(
            "您的金流帳號已自動切換到：%s\n\n" .
            "切換時間：%s\n" .
            "切換原因：%s\n" .
            "帳號名稱：%s\n" .
            "MerchantID：%s\n" .
            "當月累計金額：%s\n" .
            "金額上限：%s\n" .
            "是否為預設金流：%s\n\n" .
            "此為系統自動切換，如需手動調整請登入後台管理。",
            $site_name,
            current_time('Y-m-d H:i:s'),
            $reason,
            $account->account_name,
            $this->mask_merchant_id($account->merchant_id),
            number_format($account->monthly_amount, 2),
            number_format($account->amount_limit, 2),
            $account->is_default ? '是' : '否'
        );
        
        // 發送郵件
        wp_mail($admin_email, $subject, $message);
        
        $this->log_info("已發送金流帳號切換通知郵件到 {$admin_email}");
    }
    
    /**
     * 隱碼顯示 MerchantID
     */
    private function mask_merchant_id($merchant_id) {
        if (strlen($merchant_id) <= 4) {
            return str_repeat('*', strlen($merchant_id));
        }
        
        return substr($merchant_id, 0, 2) . str_repeat('*', strlen($merchant_id) - 4) . substr($merchant_id, -2);
    }
    
    /**
     * 取得切換歷史
     */
    public function get_switch_history($limit = 50) {
        $history = get_option('utopc_switch_history', array());
        return array_slice($history, -$limit);
    }
    
    /**
     * 清除切換歷史
     */
    public function clear_switch_history() {
        delete_option('utopc_switch_history');
    }
    
    /**
     * 取得當前 newebpay 設定
     */
    public function get_current_newebpay_settings() {
        return array(
            'merchant_id' => get_option('RY_WT_newebpay_gateway_MerchantID', ''),
            'hash_key' => get_option('RY_WT_newebpay_gateway_HashKey', ''),
            'hash_iv' => get_option('RY_WT_newebpay_gateway_HashIV', '')
        );
    }
    
    /**
     * 驗證 newebpay 設定是否正確
     */
    public function validate_newebpay_settings($account) {
        $current_settings = $this->get_current_newebpay_settings();
        
        return (
            $current_settings['merchant_id'] === $account->merchant_id &&
            $current_settings['hash_key'] === $account->hash_key &&
            $current_settings['hash_iv'] === $account->hash_iv
        );
    }
    
    /**
     * 記錄日誌
     */
    private function log_message($level, $message) {
        if (!get_option('utopc_enable_logging', 'yes')) {
            return;
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'module' => 'payment_switcher'
        );
        
        $logs = get_option('utopc_logs', array());
        $logs[] = $log_entry;
        
        // 限制日誌數量，保留最近 1000 筆
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('utopc_logs', $logs);
    }
    
    private function log_success($message) {
        $this->log_message('SUCCESS', $message);
    }
    
    private function log_error($message) {
        $this->log_message('ERROR', $message);
    }
    
    private function log_info($message) {
        $this->log_message('INFO', $message);
    }
    
    private function log_warning($message) {
        $this->log_message('WARNING', $message);
    }
}
