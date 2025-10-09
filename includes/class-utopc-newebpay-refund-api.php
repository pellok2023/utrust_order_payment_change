<?php
/**
 * 藍新金流退款 API 類別
 * 負責處理與藍新金流退款相關的 API 呼叫和加密邏輯
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_NewebPay_Refund_API {
    
    private static $instance = null;
    
    // API 端點
    private $api_test_url = 'https://ccore.newebpay.com/API/CreditCard/Close';
    private $api_url = 'https://core.newebpay.com/API/CreditCard/Close';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 初始化
    }
    
    /**
     * 處理退款請求
     * 
     * @param WC_Order $order 訂單物件
     * @param float $amount 退款金額
     * @param string $reason 退款原因
     * @param array $account 金流帳號資訊
     * @return bool|WP_Error
     */
    public function refund($order, $amount, $reason = '', $account = null) {
        // 驗證必要參數
        if (!$order || !$amount || $amount <= 0) {
            return new WP_Error('invalid_params', __('無效的退款參數', 'utrust-order-payment-change'));
        }
        
        // 取得金流帳號資訊
        if (!$account) {
            $account = $this->get_account_from_order($order);
        }
        
        if (!$account) {
            return new WP_Error('no_account', __('找不到對應的金流帳號', 'utrust-order-payment-change'));
        }
        
        // 驗證訂單交易資訊
        $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
        $trade_no = $order->get_meta('_newebpay_TradeNo', true);
        
        if (!$merchant_order_no || !$trade_no) {
            return new WP_Error('no_transaction', __('訂單缺少必要的交易資訊', 'utrust-order-payment-change'));
        }
        
        // 記錄退款請求日誌
        $this->log_info('開始處理退款請求', [
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'reason' => $reason,
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no,
            'account_id' => $account->id
        ]);
        
        // 準備退款參數
        $args = $this->prepare_refund_args($order, $amount, $account, $merchant_order_no, $trade_no);
        
        // 呼叫藍新金流 API
        $result = $this->call_refund_api($args, $account);
        
        if (is_wp_error($result)) {
            $this->log_error('退款 API 呼叫失敗', [
                'order_id' => $order->get_id(),
                'error' => $result->get_error_message()
            ]);
            return $result;
        }
        
        // 記錄成功日誌
        $this->log_success('退款成功', [
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'api_response' => $result
        ]);
        
        return true;
    }
    
    /**
     * 從訂單取得金流帳號資訊
     */
    private function get_account_from_order($order) {
        $account_id = $order->get_meta('_utopc_payment_account_id', true);
        
        if (!$account_id) {
            return null;
        }
        
        $database = UTOPC_Database::get_instance();
        return $database->get_account_by_id($account_id);
    }
    
    /**
     * 準備退款參數
     */
    private function prepare_refund_args($order, $amount, $account, $merchant_order_no, $trade_no) {
        $timestamp = new DateTime('now', new DateTimeZone('Asia/Taipei'));
        
        $args = [
            'MerchantID' => $account->merchant_id,
            'Version' => '1.0',
            'RespondType' => 'JSON',
            'TimeStamp' => $timestamp->getTimestamp(),
            'MerchantOrderNo' => $merchant_order_no,
            'TradeNo' => $trade_no,
            'CloseType' => 2, // 1: 請款, 2: 退款
            'IndexType' => 1, // 1: 商店自訂編號, 2: 藍新金流交易序號
            'Amt' => (int) ceil($amount),
        ];
        
        // 生成 CheckValue
        $args['CheckValue'] = $this->generate_check_value($args, $account->hash_key, $account->hash_iv);
        
        return $args;
    }
    
    /**
     * 呼叫退款 API
     */
    private function call_refund_api($args, $account) {
        // 判斷是否為測試模式
        $is_test_mode = $this->is_test_mode();
        $url = $is_test_mode ? $this->api_test_url : $this->api_url;
        
        $this->log_info('呼叫藍新金流退款 API', [
            'url' => $url,
            'is_test_mode' => $is_test_mode,
            'args' => $args
        ]);
        
        // 發送 API 請求
        $response = $this->link_server($url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 
                sprintf(__('API 請求失敗: %s', 'utrust-order-payment-change'), $response->get_error_message()));
        }
        
        // 檢查 HTTP 狀態碼
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            return new WP_Error('api_http_error', 
                sprintf(__('API 回應 HTTP 錯誤: %d', 'utrust-order-payment-change'), $http_code));
        }
        
        // 解析回應
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (!is_array($result)) {
            return new WP_Error('api_parse_error', 
                __('無法解析 API 回應', 'utrust-order-payment-change'));
        }
        
        // 檢查 API 回應狀態
        if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
            $message = isset($result['Message']) ? $result['Message'] : '未知錯誤';
            return new WP_Error('api_business_error', 
                sprintf(__('退款失敗: %s', 'utrust-order-payment-change'), $message));
        }
        
        return $result;
    }
    
    /**
     * 生成 CheckValue 驗證碼
     */
    private function generate_check_value($args, $hash_key, $hash_iv) {
        $string = http_build_query([
            'Amt' => $args['Amt'],
            'MerchantID' => $args['MerchantID'],
            'MerchantOrderNo' => $args['MerchantOrderNo'],
        ]);
        $string = 'IV=' . $hash_iv . '&' . $string . '&Key=' . $hash_key;
        return strtoupper(hash('sha256', $string));
    }
    
    /**
     * 發送 API 請求
     */
    private function link_server($url, $args) {
        wc_set_time_limit(40);
        
        return wp_remote_post($url, [
            'timeout' => 30,
            'body' => $args,
            'user-agent' => apply_filters('http_headers_useragent', 'WordPress/' . get_bloginfo('version')),
        ]);
    }
    
    /**
     * 檢查是否為測試模式
     */
    private function is_test_mode() {
        // 檢查 RY WooCommerce Tools 的測試模式設定
        if (function_exists('RY_WT') && method_exists(RY_WT(), 'get_option')) {
            return RY_WT()->get_option('newebpay_gateway_testmode') === 'yes';
        }
        
        // 檢查 WordPress 的測試模式
        return defined('WP_DEBUG') && WP_DEBUG;
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
     * 記錄錯誤日誌
     */
    private function log_error($message, $context = []) {
        $this->log('error', $message, $context);
    }
    
    /**
     * 記錄日誌
     */
    private function log($level, $message, $context = []) {
        $log_message = sprintf('[UTOPC Refund API] %s', $message);
        
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context);
        }
        
        // 使用 WooCommerce 日誌系統
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $log_message, ['source' => 'utopc-refund-api']);
        } else {
            error_log($log_message);
        }
    }
}
