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
        
        // 監控訂單建立，立即記錄金流資訊
        add_action('woocommerce_new_order', array($this, 'handle_new_order'), 10, 1);
        add_action('woocommerce_checkout_order_processed', array($this, 'handle_checkout_order_processed'), 10, 1);
        
        // 監控訂單狀態變更
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_completed'), 10, 1);
        
        // 監控訂單狀態變更（從其他狀態變更為完成）
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 10, 4);
        
        // 監控退款事件（新增）
        add_action('woocommerce_order_refunded', array($this, 'handle_order_refunded'), 10, 2);
        add_action('woocommerce_refund_created', array($this, 'handle_refund_created'), 10, 2);
    }
    
    /**
     * 處理新訂單建立
     */
    public function handle_new_order($order_id) {
        $this->record_payment_info_for_order($order_id);
    }
    
    /**
     * 處理結帳訂單處理完成
     */
    public function handle_checkout_order_processed($order_id) {
        $this->record_payment_info_for_order($order_id);
    }
    
    /**
     * 為訂單記錄金流資訊
     */
    private function record_payment_info_for_order($order_id) {
        $order = $this->hpos_helper->get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // 檢查是否為 NewebPay 相關付款方式
        if (!$this->is_newebpay_order($order)) {
            return;
        }
        
        // 檢查是否已經記錄過金流資訊
        if ($order->get_meta('_utopc_payment_account_id')) {
            return;
        }
        
        // 取得目前啟用的金流帳號
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            $this->log_error("訂單 {$order_id} 建立，但沒有啟用的金流帳號");
            return;
        }
        
        // 記錄訂單使用的金流帳戶資訊
        $this->record_payment_account_info($order, $active_account);
        
        $this->log_success("訂單 {$order_id} 建立，已記錄金流帳號資訊：{$active_account->account_name}");
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
        
        // 確保訂單有記錄金流資訊（如果沒有則記錄）
        if (!$order->get_meta('_utopc_payment_account_id')) {
            $this->record_payment_account_info($order, $active_account);
        } else {
            // 如果已有金流資訊，使用原本記錄的帳號來更新金額
            $original_account_id = $order->get_meta('_utopc_payment_account_id');
            $original_account = $this->database->get_account($original_account_id);
            if ($original_account) {
                $active_account = $original_account;
            }
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
     * 處理退款訂單（舊版邏輯，保留向後相容）
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
     * 處理訂單退款事件（新版邏輯）
     * 
     * @param int $order_id 訂單 ID
     * @param int $refund_id 退款 ID
     */
    public function handle_order_refunded($order_id, $refund_id) {
        $order = $this->hpos_helper->get_order($order_id);
        $refund = wc_get_order($refund_id);
        
        if (!$order || !$refund) {
            $this->log_error("無法取得訂單或退款物件", [
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ]);
            return;
        }
        
        // 檢查是否為藍新金流訂單
        if (!$this->is_newebpay_order($order)) {
            $this->log_info("非藍新金流訂單，跳過處理", [
                'order_id' => $order_id,
                'payment_method' => $order->get_payment_method()
            ]);
            return;
        }
        
        // 取得退款金額
        $refund_amount = $refund->get_amount();
        
        if ($refund_amount <= 0) {
            $this->log_warning("退款金額無效", [
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'amount' => $refund_amount
            ]);
            return;
        }
        
        // 取得訂單當時使用的金流帳號
        $account = $this->get_order_payment_account($order);
        
        if (!$account) {
            // 如果沒有記錄，使用目前啟用的帳號
            $account = $this->database->get_active_account();
            
            if (!$account) {
                $this->log_error("無法取得金流帳號資訊", [
                    'order_id' => $order_id,
                    'refund_id' => $refund_id
                ]);
                return;
            }
        }
        
        // 從對應帳號的當月累計金額中扣除退款金額
        $result = $this->database->update_monthly_amount($account->id, -$refund_amount);
        
        if ($result === false) {
            $this->log_error("更新金流帳號金額失敗", [
                'order_id' => $order_id,
                'account_id' => $account->id,
                'amount' => $refund_amount
            ]);
            return;
        }
        
        // 記錄退款歷史
        $this->record_refund_history($order, $refund, $account, $refund_amount);
        
        // 記錄成功日誌
        $this->log_success("退款處理完成", [
            'order_id' => $order_id,
            'refund_id' => $refund_id,
            'amount' => $refund_amount,
            'account_id' => $account->id,
            'account_name' => $account->account_name
        ]);
    }
    
    /**
     * 處理退款建立事件（備用）
     * 
     * @param int $refund_id 退款 ID
     * @param array $args 退款參數
     */
    public function handle_refund_created($refund_id, $args) {
        $refund = wc_get_order($refund_id);
        
        if (!$refund) {
            return;
        }
        
        $order = wc_get_order($refund->get_parent_id());
        
        if (!$order || !$this->is_newebpay_order($order)) {
            return;
        }
        
        // 延遲執行，確保訂單狀態已更新
        wp_schedule_single_event(time() + 5, 'utopc_process_delayed_refund', array($order->get_id(), $refund_id));
    }
    
    /**
     * 記錄訂單使用的金流帳戶資訊
     */
    private function record_payment_account_info($order, $account) {
        // 記錄金流帳戶 ID
        $order->update_meta_data('_utopc_payment_account_id', $account->id);
        
        // 記錄金流帳戶名稱
        $order->update_meta_data('_utopc_payment_account_name', $account->account_name);
        
        // 記錄金流公司名稱
        if (!empty($account->company_name)) {
            $order->update_meta_data('_utopc_payment_company_name', $account->company_name);
        }
        
        // 記錄商戶 ID
        $order->update_meta_data('_utopc_payment_merchant_id', $account->merchant_id);
        
        // 儲存訂單
        $order->save();
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
     * 記錄日誌（支援上下文）
     */
    private function log_message($level, $message, $context = []) {
        if (!get_option('utopc_enable_logging', 'yes')) {
            return;
        }
        
        $log_message = $message;
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context);
        }
        
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $log_message
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
    
    /**
     * 檢查是否為藍新金流訂單
     * 
     * @param WC_Order $order 訂單物件
     * @return bool
     */
    private function is_newebpay_order($order) {
        if (!$order || !is_object($order)) {
            return false;
        }
        
        $payment_method = $order->get_payment_method();
        
        $newebpay_methods = array(
            'ry_newebpay',
            'ry_newebpay_atm',
            'ry_newebpay_cc',
            'ry_newebpay_cvs',
            'ry_newebpay_webatm',
            'ry_newebpay_barcode',
            'ry_newebpay_credit_installment'
        );
        
        return in_array($payment_method, $newebpay_methods);
    }
    
    /**
     * 取得訂單的金流帳號資訊
     * 
     * @param WC_Order $order 訂單物件
     * @return object|null
     */
    private function get_order_payment_account($order) {
        $account_id = $order->get_meta('_utopc_payment_account_id', true);
        
        if (!$account_id) {
            return null;
        }
        
        return $this->database->get_account_by_id($account_id);
    }
    
    /**
     * 記錄退款歷史
     * 
     * @param WC_Order $order 訂單物件
     * @param WC_Order_Refund $refund 退款物件
     * @param object $account 金流帳號物件
     * @param float $amount 退款金額
     */
    private function record_refund_history($order, $refund, $account, $amount) {
        $result = $this->database->record_refund_history(
            $order->get_id(),
            $account->id,
            $amount,
            $refund->get_reason(),
            'success',
            ''
        );
        
        if (is_wp_error($result)) {
            $this->log_error("記錄退款歷史失敗", [
                'order_id' => $order->get_id(),
                'error' => $result->get_error_message()
            ]);
        }
    }
    
    /**
     * 記錄警告日誌
     */
    private function log_warning($message, $context = []) {
        $this->log_message('WARNING', $message, $context);
    }
    
}
