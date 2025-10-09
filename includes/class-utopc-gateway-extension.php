<?php
/**
 * 支付網關擴充類別
 * 透過 WordPress hooks 擴充 RY Tools 支付網關的退款功能
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Gateway_Extension {
    
    private static $instance = null;
    private $refund_manager;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->refund_manager = UTOPC_Refund_Manager::get_instance();
        
        // 註冊 hooks 來擴充支付網關功能
        $this->init_hooks();
    }
    
    /**
     * 取得 Refund Button 實例
     * 
     * @return UTOPC_Refund_Button
     */
    private function get_refund_button() {
        return UTOPC_Refund_Button::get_instance();
    }
    
    /**
     * 初始化 hooks
     */
    private function init_hooks() {
        // 為 NewebPay 相關的支付網關啟用退款支援
        add_filter('woocommerce_payment_gateway_supports', array($this, 'add_refund_support'), 10, 3);
        
        // 攔截退款處理，使用我們的自定義邏輯
        add_filter('woocommerce_payment_gateway_process_refund', array($this, 'intercept_refund_process'), 10, 4);
        
        // 確保退款按鈕顯示
        add_filter('woocommerce_can_refund_order', array($this, 'enable_refund_button'), 10, 2);
        
        // 添加退款相關的訂單動作
        add_filter('woocommerce_order_actions', array($this, 'add_refund_actions'), 10, 1);
    }
    
    /**
     * 為 NewebPay 支付網關添加退款支援
     * 
     * @param bool $supports 是否支援該功能
     * @param string $feature 功能名稱
     * @param WC_Payment_Gateway $gateway 支付網關物件
     * @return bool
     */
    public function add_refund_support($supports, $feature, $gateway) {
        // 只處理退款功能
        if ($feature !== 'refunds') {
            return $supports;
        }
        
        // 檢查是否為 NewebPay 相關的支付網關
        if ($this->is_newebpay_gateway($gateway)) {
            return true;
        }
        
        return $supports;
    }
    
    /**
     * 攔截退款處理，使用我們的自定義邏輯
     * 
     * @param bool $result 處理結果
     * @param int $order_id 訂單 ID
     * @param float $amount 退款金額
     * @param string $reason 退款原因
     * @return bool|WP_Error
     */
    public function intercept_refund_process($result, $order_id, $amount, $reason) {
        // 如果已經有結果，不進行攔截
        if ($result !== false) {
            return $result;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('invalid_order', __('訂單不存在', 'utrust-order-payment-change'));
        }
        
        // 檢查是否為 NewebPay 訂單
        if (!$this->get_refund_button()->is_newebpay_order($order)) {
            return false; // 讓其他處理器處理
        }
        
        // 使用我們的退款管理器處理
        return $this->refund_manager->manual_refund($order_id, $amount, $reason);
    }
    
    /**
     * 啟用退款按鈕
     * 
     * @param bool $can_refund 是否可以退款
     * @param WC_Order $order 訂單物件
     * @return bool
     */
    public function enable_refund_button($can_refund, $order) {
        // 如果已經可以退款，直接返回
        if ($can_refund) {
            return $can_refund;
        }
        
        // 檢查是否為 NewebPay 訂單且已付款
        if ($this->get_refund_button()->is_newebpay_order($order) && $order->is_paid()) {
            // 檢查是否有必要的交易資訊
            $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
            $trade_no = $order->get_meta('_newebpay_TradeNo', true);
            
            if ($merchant_order_no && $trade_no) {
                return true;
            }
        }
        
        return $can_refund;
    }
    
    /**
     * 添加退款相關的訂單動作
     * 
     * @param array $actions 訂單動作陣列
     * @return array
     */
    public function add_refund_actions($actions) {
        global $theorder;
        
        if (!$theorder || !$this->get_refund_button()->is_newebpay_order($theorder)) {
            return $actions;
        }
        
        // 檢查是否已付款且有交易資訊
        if ($theorder->is_paid()) {
            $merchant_order_no = $theorder->get_meta('_newebpay_MerchantOrderNo', true);
            $trade_no = $theorder->get_meta('_newebpay_TradeNo', true);
            
            if ($merchant_order_no && $trade_no) {
                // 添加快速退款動作（可選）
                $actions['utopc_quick_refund'] = __('快速退款 (UTrust)', 'utrust-order-payment-change');
            }
        }
        
        return $actions;
    }
    
    
    
    /**
     * 處理快速退款動作
     * 
     * @param WC_Order $order 訂單物件
     */
    public function handle_quick_refund_action($order) {
        if (!$this->get_refund_button()->is_newebpay_order($order)) {
            return;
        }
        
        // 這裡可以添加快速退款的邏輯
        // 例如：彈出確認對話框或直接執行全額退款
        $this->log_info('快速退款動作被觸發', [
            'order_id' => $order->get_id()
        ]);
    }
    
    /**
     * 檢查是否為 NewebPay 支付網關
     * 
     * @param WC_Payment_Gateway $gateway 支付網關物件
     * @return bool
     */
    private function is_newebpay_gateway($gateway) {
        if (!$gateway || !is_object($gateway)) {
            return false;
        }
        
        $newebpay_gateway_ids = array(
            'ry_newebpay',
            'ry_newebpay_atm',
            'ry_newebpay_cc',
            'ry_newebpay_credit',
            'ry_newebpay_cvs',
            'ry_newebpay_webatm',
            'ry_newebpay_barcode',
            'ry_newebpay_credit_installment'
        );
        
        return in_array($gateway->id, $newebpay_gateway_ids);
    }
    
    
    /**
     * 取得 NewebPay 訂單的交易資訊
     * 
     * @param WC_Order $order 訂單物件
     * @return array|false
     */
    public function get_order_transaction_info($order) {
        if (!$this->get_refund_button()->is_newebpay_order($order)) {
            return false;
        }
        
        $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
        $trade_no = $order->get_meta('_newebpay_TradeNo', true);
        
        if (!$merchant_order_no || !$trade_no) {
            return false;
        }
        
        return array(
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no,
            'payment_method' => $order->get_payment_method(),
            'order_total' => $order->get_total(),
            'total_refunded' => $order->get_total_refunded(),
            'remaining_amount' => $order->get_total() - $order->get_total_refunded()
        );
    }
    
    /**
     * 檢查訂單是否可以退款
     * 
     * @param WC_Order $order 訂單物件
     * @return array 檢查結果
     */
    public function check_refund_eligibility($order) {
        $result = array(
            'can_refund' => false,
            'reasons' => array()
        );
        
        if (!$this->get_refund_button()->is_newebpay_order($order)) {
            $result['reasons'][] = __('非藍新金流訂單', 'utrust-order-payment-change');
            return $result;
        }
        
        if (!$order->is_paid()) {
            $result['reasons'][] = __('訂單尚未付款', 'utrust-order-payment-change');
            return $result;
        }
        
        $transaction_info = $this->get_order_transaction_info($order);
        
        if (!$transaction_info) {
            $result['reasons'][] = __('缺少交易資訊', 'utrust-order-payment-change');
            return $result;
        }
        
        if ($transaction_info['remaining_amount'] <= 0) {
            $result['reasons'][] = __('訂單已完全退款', 'utrust-order-payment-change');
            return $result;
        }
        
        $result['can_refund'] = true;
        $result['max_refund_amount'] = $transaction_info['remaining_amount'];
        $result['transaction_info'] = $transaction_info;
        
        return $result;
    }
    
    /**
     * 記錄資訊日誌
     */
    private function log_info($message, $context = []) {
        $this->log('info', $message, $context);
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
        $log_message = sprintf('[UTOPC Gateway Extension] %s', $message);
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context);
        }
        
        // 使用 WooCommerce 日誌系統
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $log_message, ['source' => 'utopc-gateway-extension']);
        } else {
            error_log($log_message);
        }
    }
}
