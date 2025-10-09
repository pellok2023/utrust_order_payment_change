<?php
/**
 * 退款管理類別
 * 集中處理退款流程、驗證和協調
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Refund_Manager {
    
    private static $instance = null;
    private $database;
    private $refund_api;
    private $order_monitor;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->database = UTOPC_Database::get_instance();
        $this->refund_api = UTOPC_NewebPay_Refund_API::get_instance();
        $this->order_monitor = UTOPC_Order_Monitor::get_instance();
        
        // 註冊退款處理 hooks
        add_action('woocommerce_order_refunded', array($this, 'handle_order_refunded'), 10, 2);
        add_action('woocommerce_refund_created', array($this, 'handle_refund_created'), 10, 2);
    }
    
    /**
     * 處理訂單退款事件
     * 
     * @param int $order_id 訂單 ID
     * @param int $refund_id 退款 ID
     */
    public function handle_order_refunded($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        
        if (!$order || !$refund) {
            $this->log_error('無法取得訂單或退款物件', [
                'order_id' => $order_id,
                'refund_id' => $refund_id
            ]);
            return;
        }
        
        // 檢查是否為藍新金流訂單
        if (!$this->is_newebpay_order($order)) {
            $this->log_info('非藍新金流訂單，跳過處理', [
                'order_id' => $order_id,
                'payment_method' => $order->get_payment_method()
            ]);
            return;
        }
        
        // 取得退款金額
        $refund_amount = $refund->get_amount();
        
        if ($refund_amount <= 0) {
            $this->log_warning('退款金額無效', [
                'order_id' => $order_id,
                'refund_id' => $refund_id,
                'amount' => $refund_amount
            ]);
            return;
        }
        
        // 執行退款處理
        $this->process_refund($order, $refund, $refund_amount);
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
            $this->log_info('非藍新金流訂單，跳過處理', [
                'order_id' => $order_id,
                'payment_method' => $order->get_payment_method()
            ]);
            return;

        }
        
        // 延遲執行，確保訂單狀態已更新
        wp_schedule_single_event(time() + 5, 'utopc_process_delayed_refund', array($order->get_id(), $refund_id));
    }
    
    /**
     * 處理延遲退款
     */
    public function process_delayed_refund($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        
        if (!$order || !$refund) {
            return;
        }
        
        $refund_amount = $refund->get_amount();
        
        if ($refund_amount > 0) {
            $this->process_refund($order, $refund, $refund_amount);
        }
    }
    
    /**
     * 執行退款處理
     * 
     * @param WC_Order $order 訂單物件
     * @param WC_Order_Refund $refund 退款物件
     * @param float $amount 退款金額
     */
    private function process_refund($order, $refund, $amount) {
        $order_id = $order->get_id();
        
        $this->log_info('開始處理退款', [
            'order_id' => $order_id,
            'refund_id' => $refund->get_id(),
            'amount' => $amount
        ]);
        
        // 1. 驗證訂單是否可退款
        $validation_result = $this->validate_refund($order, $amount);
        
        if (is_wp_error($validation_result)) {
            $this->log_error('退款驗證失敗', [
                'order_id' => $order_id,
                'error' => $validation_result->get_error_message()
            ]);
            
            // 在訂單備註中記錄錯誤
            $order->add_order_note(sprintf(
                __('退款處理失敗: %s', 'utrust-order-payment-change'),
                $validation_result->get_error_message()
            ));
            return;
        }
        
        // 2. 取得金流帳號資訊
        $account = $this->get_order_payment_account($order);
        
        if (!$account) {
            $this->log_error('無法取得訂單的金流帳號資訊', [
                'order_id' => $order_id
            ]);
            
            $order->add_order_note(__('退款失敗: 無法取得金流帳號資訊', 'utrust-order-payment-change'));
            return;
        }
        
        // 3. 呼叫藍新金流退款 API
        $api_result = $this->refund_api->refund($order, $amount, '', $account);
        
        if (is_wp_error($api_result)) {
            $this->log_error('藍新金流退款 API 失敗', [
                'order_id' => $order_id,
                'error' => $api_result->get_error_message()
            ]);
            
            $order->add_order_note(sprintf(
                __('藍新金流退款失敗: %s', 'utrust-order-payment-change'),
                $api_result->get_error_message()
            ));
            return;
        }
        
        // 4. 更新金流帳號的當月累計金額
        $this->update_account_monthly_amount($account, -$amount);
        
        // 5. 記錄退款歷史
        $this->record_refund_history($order, $refund, $account, $amount, $api_result);
        
        // 6. 在訂單備註中記錄成功
        $order->add_order_note(sprintf(
            __('退款成功: %s 已從金流帳號 %s 扣除', 'utrust-order-payment-change'),
            wc_price($amount),
            $account->account_name
        ));
        
        $this->log_success('退款處理完成', [
            'order_id' => $order_id,
            'refund_id' => $refund->get_id(),
            'amount' => $amount,
            'account_id' => $account->id
        ]);
    }
    
    /**
     * 驗證訂單是否可退款
     * 
     * @param WC_Order $order 訂單物件
     * @param float $amount 退款金額
     * @return bool|WP_Error
     */
    private function validate_refund($order, $amount) {
        // 檢查訂單狀態
        if (!$order->is_paid()) {
            return new WP_Error('order_not_paid', __('訂單尚未付款，無法退款', 'utrust-order-payment-change'));
        }
        
        // 檢查是否有交易 ID
        $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
        $trade_no = $order->get_transaction_id(); // RY Tools 使用 WooCommerce 標準的 transaction_id
        
        // 至少需要 MerchantOrderNo
        if (!$merchant_order_no) {
            return new WP_Error('no_transaction_id', __('訂單缺少交易資訊 (MerchantOrderNo)，無法退款', 'utrust-order-payment-change'));
        }
        
        // 如果沒有 TradeNo，使用 MerchantOrderNo 作為替代
        if (!$trade_no) {
            $trade_no = $merchant_order_no;
        }
        
        // 檢查退款金額
        if ($amount <= 0) {
            return new WP_Error('invalid_amount', __('退款金額必須大於 0', 'utrust-order-payment-change'));
        }
        
        // 檢查是否超過訂單總額
        $order_total = $order->get_total();
        $total_refunded = $order->get_total_refunded();
        
        if (($total_refunded + $amount) > $order_total) {
            return new WP_Error('refund_exceeds_total', __('退款金額超過訂單總額', 'utrust-order-payment-change'));
        }
        
        return true;
    }
    
    /**
     * 檢查是否為藍新金流訂單
     * 
     * @param WC_Order $order 訂單物件
     * @return bool
     */
    private function is_newebpay_order($order) {
        $payment_method = $order->get_payment_method();
        
        $newebpay_methods = array(
            'ry_newebpay',
            'ry_newebpay_atm',
            'ry_newebpay_cc',
            'ry_newebpay_credit',
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
            // 如果沒有記錄，嘗試從目前啟用的帳號取得
            return $this->database->get_active_account();
        }
        
        return $this->database->get_account_by_id($account_id);
    }
    
    /**
     * 更新金流帳號的當月累計金額
     * 
     * @param object $account 金流帳號物件
     * @param float $amount 金額（正數為增加，負數為扣除）
     */
    private function update_account_monthly_amount($account, $amount) {
        $result = $this->database->update_monthly_amount($account->id, $amount);
        
        if ($result === false) {
            $this->log_error('更新金流帳號金額失敗', [
                'account_id' => $account->id,
                'amount' => $amount
            ]);
        } else {
            $this->log_success('金流帳號金額已更新', [
                'account_id' => $account->id,
                'amount' => $amount,
                'new_monthly_amount' => $account->monthly_amount + $amount
            ]);
        }
    }
    
    /**
     * 記錄退款歷史
     * 
     * @param WC_Order $order 訂單物件
     * @param WC_Order_Refund $refund 退款物件
     * @param object $account 金流帳號物件
     * @param float $amount 退款金額
     * @param mixed $api_result API 回應結果
     */
    private function record_refund_history($order, $refund, $account, $amount, $api_result) {
        $api_response = '';
        if (is_array($api_result)) {
            $api_response = wp_json_encode($api_result);
        }
        
        $result = $this->database->record_refund_history(
            $order->get_id(),
            $account->id,
            $amount,
            $refund->get_reason(),
            'success',
            $api_response
        );
        
        if (is_wp_error($result)) {
            $this->log_error('記錄退款歷史失敗', [
                'order_id' => $order->get_id(),
                'error' => $result->get_error_message()
            ]);
        }
    }
    
    /**
     * 手動執行退款（管理員功能）
     * 
     * @param int $order_id 訂單 ID
     * @param float $amount 退款金額
     * @param string $reason 退款原因
     * @return bool|WP_Error
     */
    public function manual_refund($order_id, $amount, $reason = '') {
        // 檢查權限
        if (!current_user_can('manage_woocommerce')) {
            return new WP_Error('no_permission', __('您沒有權限執行此操作', 'utrust-order-payment-change'));
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('訂單不存在', 'utrust-order-payment-change'));
        }
        
        // 檢查是否為藍新金流訂單
        if (!$this->is_newebpay_order($order)) {
            return new WP_Error('not_newebpay', __('此訂單不是藍新金流訂單', 'utrust-order-payment-change'));
        }
        
        // 驗證退款參數
        $validation_result = $this->validate_refund($order, $amount);
        if (is_wp_error($validation_result)) {
            return $validation_result;
        }
        
        // 取得金流帳號資訊
        $account = $this->get_order_payment_account($order);
        if (!$account) {
            return new WP_Error('no_account', __('無法取得金流帳號資訊', 'utrust-order-payment-change'));
        }
        
        // 呼叫藍新金流退款 API
        $api_result = $this->refund_api->refund($order, $amount, $reason, $account);
        if (is_wp_error($api_result)) {
            return $api_result;
        }
        
        // 建立 WooCommerce 退款物件
        $refund = wc_create_refund(array(
            'order_id' => $order_id,
            'amount' => $amount,
            'reason' => $reason,
            'refund_payment' => false // 我們已經手動處理了金流退款
        ));
        
        if (is_wp_error($refund)) {
            return $refund;
        }
        
        // 更新金流帳號的當月累計金額
        $this->update_account_monthly_amount($account, -$amount);
        
        // 記錄退款歷史
        $this->record_refund_history($order, $refund, $account, $amount, $api_result);
        
        // 在訂單備註中記錄成功
        $order->add_order_note(sprintf(
            __('藍新金流退款成功: %s 已從金流帳號 %s 扣除', 'utrust-order-payment-change'),
            wc_price($amount),
            $account->account_name
        ));
        
        $this->log_success('手動退款處理完成', [
            'order_id' => $order_id,
            'refund_id' => $refund->get_id(),
            'amount' => $amount,
            'account_id' => $account->id
        ]);
        
        return true;
    }
    
    
    /**
     * 記錄資訊日誌
     */
    private function log_info($message, $context = []) {
        $this->log('info', $message, $context);
    }
    
    /**
     * 記錄成功日誌
     */
    private function log_success($message, $context = []) {
        $this->log('success', $message, $context);
    }
    
    /**
     * 記錄警告日誌
     */
    private function log_warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * 記錄錯誤日誌
     */
    private function log_error($message, $context = []) {
        $this->log('error', $message, $context);
    }
    
    /**
     * 記錄日誌
     */
    private function log($level, $message, $context = []) {
        $log_message = sprintf('[UTOPC Refund Manager] %s', $message);
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context);
        }
        
        // 使用 WooCommerce 日誌系統
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $log_message, ['source' => 'utopc-refund-manager']);
        } else {
            error_log($log_message);
        }
    }
}

// 註冊延遲退款處理的 hook
add_action('utopc_process_delayed_refund', array('UTOPC_Refund_Manager', 'process_delayed_refund'), 10, 2);
