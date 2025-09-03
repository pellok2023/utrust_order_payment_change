<?php
/**
 * 月度重置模組
 * 負責每月自動重置金流帳號的當月累計金額
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Monthly_Reset {
    
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
        
        // 註冊 WP-Cron 事件
        add_action('utopc_monthly_reset', array($this, 'perform_monthly_reset'));
        
        // 註冊 AJAX 處理
        add_action('wp_ajax_utopc_manual_reset', array($this, 'ajax_manual_reset'));
        
        // 檢查並設定 WP-Cron 事件
        add_action('init', array($this, 'setup_monthly_cron'));
    }
    
    /**
     * 設定月度 WP-Cron 事件
     */
    public function setup_monthly_cron() {
        if (!wp_next_scheduled('utopc_monthly_reset')) {
            // 設定每月 1 日凌晨 2 點執行
            $next_month = strtotime('first day of next month 02:00:00');
            wp_schedule_event($next_month, 'monthly', 'utopc_monthly_reset');
        }
    }
    
    /**
     * 執行月度重置
     */
    public function perform_monthly_reset() {
        $this->log_info('開始執行月度重置');
        
        try {
            // 1. 備份重置前的資料
            $this->backup_monthly_data();
            
            // 2. 重置所有帳號的當月累計金額
            $result = $this->database->reset_monthly_amounts();
            
            if ($result === false) {
                throw new Exception('重置當月累計金額失敗');
            }
            
            // 3. 記錄重置歷史
            $this->record_reset_history();
            
            // 4. 發送重置通知
            $this->send_reset_notification();
            
            // 5. 檢查是否需要切換金流帳號
            $this->check_and_switch_after_reset();
            
            $this->log_success('月度重置執行完成');
            
            return true;
            
        } catch (Exception $e) {
            $this->log_error('月度重置執行失敗：' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 備份月度資料
     */
    private function backup_monthly_data() {
        $accounts = $this->database->get_all_accounts();
        $backup_data = array();
        
        foreach ($accounts as $account) {
            $backup_data[] = array(
                'account_id' => $account->id,
                'account_name' => $account->account_name,
                'monthly_amount' => $account->monthly_amount,
                'amount_limit' => $account->amount_limit,
                'backup_date' => current_time('Y-m-d H:i:s')
            );
        }
        
        // 儲存備份資料
        $backups = get_option('utopc_monthly_backups', array());
        $backups[] = array(
            'date' => current_time('Y-m-d H:i:s'),
            'data' => $backup_data
        );
        
        // 限制備份數量，保留最近 12 個月
        if (count($backups) > 12) {
            $backups = array_slice($backups, -12);
        }
        
        update_option('utopc_monthly_backups', $backups);
        
        $this->log_info('月度資料備份完成，共備份 ' . count($accounts) . ' 個帳號');
    }
    
    /**
     * 記錄重置歷史
     */
    private function record_reset_history() {
        $history = get_option('utopc_reset_history', array());
        
        $history_entry = array(
            'timestamp' => current_time('mysql'),
            'type' => 'monthly_reset',
            'description' => '月度自動重置',
            'accounts_count' => count($this->database->get_all_accounts())
        );
        
        $history[] = $history_entry;
        
        // 限制歷史記錄數量，保留最近 100 筆
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        
        update_option('utopc_reset_history', $history);
    }
    
    /**
     * 發送重置通知
     */
    private function send_reset_notification() {
        // 檢查是否啟用通知
        if (get_option('utopc_enable_notifications', 'yes') !== 'yes') {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf('[%s] 月度重置完成通知', $site_name);
        
        $message = sprintf(
            "您的金流帳號月度重置已完成\n\n" .
            "重置時間：%s\n" .
            "網站名稱：%s\n" .
            "重置類型：月度自動重置\n\n" .
            "所有金流帳號的當月累計金額已歸零，系統將重新開始計算。",
            current_time('Y-m-d H:i:s'),
            $site_name
        );
        
        // 發送郵件
        wp_mail($admin_email, $subject, $message);
        
        $this->log_info("已發送月度重置完成通知郵件到 {$admin_email}");
    }
    
    /**
     * 重置後檢查並切換金流帳號
     */
    private function check_and_switch_after_reset() {
        // 取得所有帳號
        $accounts = $this->database->get_all_accounts();
        
        if (empty($accounts)) {
            return;
        }
        
        // 找到第一個帳號並啟用
        $first_account = $accounts[0];
        
        // 停用所有帳號
        $this->database->deactivate_all_accounts();
        
        // 啟用第一個帳號
        $this->database->activate_account($first_account->id);
        
        $this->log_info("月度重置後，已啟用金流帳號：{$first_account->account_name}");
    }
    
    /**
     * 手動執行月度重置
     */
    public function manual_monthly_reset() {
        // 檢查是否允許手動重置
        if (get_option('utopc_allow_manual_reset', 'yes') !== 'yes') {
            return new WP_Error('not_allowed', '手動重置功能已停用');
        }
        
        // 執行重置
        $result = $this->perform_monthly_reset();
        
        if ($result) {
            $this->log_success('手動月度重置執行完成');
            return true;
        } else {
            return new WP_Error('reset_failed', '手動月度重置執行失敗');
        }
    }
    
    /**
     * AJAX 手動重置
     */
    public function ajax_manual_reset() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('權限不足', 'utrust-order-payment-change'));
        }
        
        $result = $this->manual_monthly_reset();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('月度重置執行成功！', 'utrust-order-payment-change'));
        }
    }
    
    /**
     * 取得月度備份資料
     */
    public function get_monthly_backups() {
        return get_option('utopc_monthly_backups', array());
    }
    
    /**
     * 取得重置歷史
     */
    public function get_reset_history($limit = 50) {
        $history = get_option('utopc_reset_history', array());
        return array_slice($history, -$limit);
    }
    
    /**
     * 清除月度備份
     */
    public function clear_monthly_backups() {
        delete_option('utopc_monthly_backups');
    }
    
    /**
     * 清除重置歷史
     */
    public function clear_reset_history() {
        delete_option('utopc_reset_history');
    }
    
    /**
     * 取得下次重置時間
     */
    public function get_next_reset_time() {
        $next_scheduled = wp_next_scheduled('utopc_monthly_reset');
        
        if ($next_scheduled) {
            return date('Y-m-d H:i:s', $next_scheduled);
        }
        
        return __('未設定', 'utrust-order-payment-change');
    }
    
    /**
     * 取得重置統計資訊
     */
    public function get_reset_stats() {
        $backups = $this->get_monthly_backups();
        $history = $this->get_reset_history();
        
        return array(
            'total_backups' => count($backups),
            'total_resets' => count($history),
            'last_reset' => !empty($history) ? end($history)['timestamp'] : __('無', 'utrust-order-payment-change'),
            'next_reset' => $this->get_next_reset_time()
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
            'module' => 'monthly_reset'
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
}
