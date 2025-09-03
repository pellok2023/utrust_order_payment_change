<?php
/**
 * 訂單監控模組
 * 負責監控 WooCommerce 訂單完成並更新金流帳號金額
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Order_Monitor {
    
    private static $instance = null;
    private $database;
    private $payment_switcher;
    private $hpos_helper;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->database = UTOPC_Database::get_instance();
        $this->payment_switcher = UTOPC_Payment_Switcher::get_instance();
        $this->hpos_helper = UTOPC_HPOS_Helper::get_instance();
        
        // 監控訂單狀態變更
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_completed'), 10, 1);
        
        // 監控訂單狀態變更（從其他狀態變更為完成）
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 10, 4);
    }
    
    /**
     * 處理訂單完成
     */
    public function handle_order_completed($order_id) {
        $order = $this->hpos_helper->get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // 檢查是否已經處理過此訂單
        if ($this->is_order_processed($order_id)) {
            return;
        }
        
        // 取得訂單金額 - 支援 HPOS
        $order_total = $this->hpos_helper->get_order_total($order);
        
        // 檢查是否為退款訂單
        if ($order_total < 0) {
            $this->handle_refund_order($order_id, abs($order_total));
            return;
        }
        
        // 取得目前啟用的金流帳號
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            $this->log_error("訂單 {$order_id} 完成，但沒有啟用的金流帳號");
            return;
        }
        
        // 更新金流帳號的當月累計金額
        $result = $this->database->update_monthly_amount($active_account->id, $order_total);
        
        if ($result === false) {
            $this->log_error("更新金流帳號 {$active_account->id} 金額失敗");
            return;
        }
        
        // 標記訂單已處理
        $this->mark_order_processed($order_id);
        
        // 記錄成功日誌
        $this->log_success("訂單 {$order_id} 完成，金額 {$order_total} 已累加到金流帳號 {$active_account->account_name}");
        
        // 檢查是否需要切換金流帳號
        $this->check_and_switch_payment_account($active_account);
    }
    
    /**
     * 取得訂單總金額 - 支援 HPOS
     */
    private function get_order_total($order) {
        // 使用 WooCommerce 的標準方法，自動支援 HPOS
        return $order->get_total();
    }
    
    /**
     * 檢查是否使用 HPOS
     */
    private function is_hpos_enabled() {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
    
    /**
     * 取得訂單 ID - 支援 HPOS
     */
    private function get_order_id($order) {
        if ($this->is_hpos_enabled()) {
            return $order->get_id();
        }
        return $order->get_id();
    }
    
    /**
     * 處理訂單狀態變更
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status, $order) {
        // 只處理狀態變更為 completed 或 processing 的訂單
        if (in_array($new_status, array('completed', 'processing'))) {
            $this->handle_order_completed($order_id);
        }
    }
    
    /**
     * 處理退款訂單
     */
    private function handle_refund_order($order_id, $refund_amount) {
        // 取得目前啟用的金流帳號
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            $this->log_error("退款訂單 {$order_id}，但沒有啟用的金流帳號");
            return;
        }
        
        // 從當月累計金額中扣除退款金額
        $result = $this->database->update_monthly_amount($active_account->id, -$refund_amount);
        
        if ($result === false) {
            $this->log_error("更新退款訂單 {$order_id} 的金流帳號金額失敗");
            return;
        }
        
        // 標記訂單已處理
        $this->mark_order_processed($order_id);
        
        // 記錄成功日誌
        $this->log_success("退款訂單 {$order_id}，金額 {$refund_amount} 已從金流帳號 {$active_account->account_name} 扣除");
    }
    
    /**
     * 檢查並切換金流帳號
     */
    private function check_and_switch_payment_account($current_account) {
        // 檢查是否達到金額上限
        if ($this->database->is_account_limit_reached($current_account->id)) {
            $this->log_info("金流帳號 {$current_account->account_name} 已達到金額上限，準備切換");
            
            // 觸發金流切換
            $this->payment_switcher->switch_to_next_account();
        }
    }
    
    /**
     * 檢查訂單是否已經處理過
     */
    private function is_order_processed($order_id) {
        $processed_orders = get_option('utopc_processed_orders', array());
        return in_array($order_id, $processed_orders);
    }
    
    /**
     * 標記訂單已處理
     */
    private function mark_order_processed($order_id) {
        $processed_orders = get_option('utopc_processed_orders', array());
        
        if (!in_array($order_id, $processed_orders)) {
            $processed_orders[] = $order_id;
            
            // 限制處理過的訂單數量，避免資料庫過大
            if (count($processed_orders) > 10000) {
                $processed_orders = array_slice($processed_orders, -5000);
            }
            
            update_option('utopc_processed_orders', $processed_orders);
        }
    }
    
    /**
     * 記錄成功日誌
     */
    private function log_success($message) {
        $this->log_message('SUCCESS', $message);
    }
    
    /**
     * 記錄錯誤日誌
     */
    private function log_error($message) {
        $this->log_message('ERROR', $message);
    }
    
    /**
     * 記錄資訊日誌
     */
    private function log_info($message) {
        $this->log_message('INFO', $message);
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
            'message' => $message
        );
        
        $logs = get_option('utopc_logs', array());
        $logs[] = $log_entry;
        
        // 限制日誌數量，保留最近 1000 筆
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('utopc_logs', $logs);
    }
    
    /**
     * 取得日誌
     */
    public function get_logs($limit = 100) {
        $logs = get_option('utopc_logs', array());
        return array_slice($logs, -$limit);
    }
    
    /**
     * 清除日誌
     */
    public function clear_logs() {
        delete_option('utopc_logs');
    }
    
    /**
     * 取得訂單統計資訊
     */
    public function get_order_stats() {
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            return array(
                'total_orders' => 0,
                'total_amount' => 0,
                'monthly_amount' => 0,
                'remaining_limit' => 0,
                'account_name' => null
            );
        }
        
        $monthly_amount = $active_account->monthly_amount;
        $amount_limit = $active_account->amount_limit;
        $remaining_limit = max(0, $amount_limit - $monthly_amount);
        
        return array(
            'total_orders' => $this->get_processed_orders_count(),
            'total_amount' => $monthly_amount,
            'monthly_amount' => $monthly_amount,
            'remaining_limit' => $remaining_limit,
            'account_name' => $active_account->account_name
        );
    }
    
    /**
     * 取得已處理訂單數量
     */
    private function get_processed_orders_count() {
        $processed_orders = get_option('utopc_processed_orders', array());
        return count($processed_orders);
    }
}
