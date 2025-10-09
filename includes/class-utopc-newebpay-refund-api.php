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
    private $cancel_api_test_url = 'https://ccore.newebpay.com/API/CreditCard/Cancel';
    private $cancel_api_url = 'https://core.newebpay.com/API/CreditCard/Cancel';
    private $refund_api_test_url = 'https://ccore.newebpay.com/API/CreditCard/Close';
    private $refund_api_url = 'https://core.newebpay.com/API/CreditCard/Close';
    
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
        $trade_no = $order->get_transaction_id();
        
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
        
        // 先嘗試取消授權，如果失敗再嘗試退款
        $result = $this->try_cancel_authorization($order, $amount, $account, $merchant_order_no, $trade_no);
        
        if (is_wp_error($result)) {
            $this->log_info('取消授權失敗，嘗試退款', [
                'order_id' => $order->get_id(),
                'cancel_error' => $result->get_error_message()
            ]);
            
            // 嘗試退款
            $result = $this->try_refund($order, $amount, $account, $merchant_order_no, $trade_no);
            
            if (is_wp_error($result)) {
                $this->log_error('退款 API 呼叫失敗', [
                    'order_id' => $order->get_id(),
                    'error' => $result->get_error_message()
                ]);
                return $result;
            }
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
     * 嘗試取消授權
     */
    private function try_cancel_authorization($order, $amount, $account, $merchant_order_no, $trade_no) {
        $this->log_info('嘗試取消授權', [
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no
        ]);
        
        // 準備取消授權參數
        $args = $this->prepare_cancel_args($order, $amount, $account, $merchant_order_no, $trade_no);
        
        // 呼叫取消授權 API
        return $this->call_cancel_api($args, $account);
    }
    
    /**
     * 嘗試退款
     */
    private function try_refund($order, $amount, $account, $merchant_order_no, $trade_no) {
        $this->log_info('嘗試退款', [
            'order_id' => $order->get_id(),
            'amount' => $amount,
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no
        ]);
        
        // 準備退款參數
        $args = $this->prepare_refund_args($order, $amount, $account, $merchant_order_no, $trade_no);
        
        // 呼叫退款 API
        return $this->call_refund_api($args, $account);
    }
    
    /**
     * 準備取消授權參數
     * 根據藍新金流官方文件 4.4.1 規範
     */
    private function prepare_cancel_args($order, $amount, $account, $merchant_order_no, $trade_no) {
        // 準備要加密的資料（PostData_ 內容）
        $data_to_encrypt = http_build_query([
            'RespondType' => 'JSON',
            'Version' => '1.0',  // 取消授權 API 使用 1.0
            'Amt' => (int) ceil($amount),
            'MerchantOrderNo' => $merchant_order_no,
            'TradeNo' => $trade_no,
            'IndexType' => '1',  // 1: 商店訂單編號, 2: 藍新金流交易序號
            'TimeStamp' => time(),
        ]);
        
        // 使用 AES-256-CBC 加密（官方文件要求）
        $encrypted_data = bin2hex(openssl_encrypt(
            $data_to_encrypt, 
            "AES-256-CBC", 
            $account->hash_key, 
            OPENSSL_RAW_DATA, 
            $account->hash_iv
        ));
        
        // 準備最終的 POST 參數（官方文件格式）
        $post_args = [
            'MerchantID_' => $account->merchant_id,
            'PostData_' => $encrypted_data
        ];
        
        $this->log_info('準備取消授權參數', [
            'merchant_id' => $account->merchant_id,
            'amount' => $amount,
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no,
            'encrypted_data_length' => strlen($encrypted_data)
        ]);
        
        return $post_args;
    }
    
    /**
     * 準備退款參數
     * 根據藍新金流官方文件 4.5.1 規範
     */
    private function prepare_refund_args($order, $amount, $account, $merchant_order_no, $trade_no) {
        // 準備要加密的資料（PostData_ 內容）
        $data_to_encrypt = http_build_query([
            'RespondType' => 'JSON',
            'Version' => '1.1',  // 官方文件要求 1.1
            'Amt' => (int) ceil($amount),
            'MerchantOrderNo' => $merchant_order_no,
            'TimeStamp' => time(),
            'IndexType' => '1',  // 1: 商店訂單編號, 2: 藍新金流交易序號
            'TradeNo' => $trade_no,
            'CloseType' => '2',  // 1: 請款, 2: 退款
        ]);
        
        // 使用 AES-256-CBC 加密（官方文件要求）
        $encrypted_data = bin2hex(openssl_encrypt(
            $data_to_encrypt, 
            "AES-256-CBC", 
            $account->hash_key, 
            OPENSSL_RAW_DATA, 
            $account->hash_iv
        ));
        
        // 準備最終的 POST 參數（官方文件格式）
        $post_args = [
            'MerchantID_' => $account->merchant_id,
            'PostData_' => $encrypted_data
        ];
        
        $this->log_info('準備退款參數', [
            'merchant_id' => $account->merchant_id,
            'amount' => $amount,
            'merchant_order_no' => $merchant_order_no,
            'trade_no' => $trade_no,
            'encrypted_data_length' => strlen($encrypted_data)
        ]);
        
        return $post_args;
    }
    
    /**
     * 呼叫取消授權 API
     * 根據藍新金流官方文件 4.4.2 回應格式處理
     */
    private function call_cancel_api($args, $account) {
        // 判斷是否為測試模式
        $is_test_mode = $this->is_test_mode();
        $url = $is_test_mode ? $this->cancel_api_test_url : $this->cancel_api_url;
        
        $this->log_info('呼叫藍新金流取消授權 API', [
            'url' => $url,
            'is_test_mode' => $is_test_mode,
            'merchant_id' => $args['MerchantID_'],
            'postdata_length' => strlen($args['PostData_'])
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
        
        // 解析回應（藍新金流返回 JSON 格式）
        $body = wp_remote_retrieve_body($response);
        $this->log_info('取消授權 API 原始回應', ['body' => $body]);
        
        // 解析 JSON 回應
        $result = json_decode($body, true);
        
        if (!is_array($result)) {
            $this->log_error('取消授權 API 回應解析失敗', [
                'body' => $body,
                'json_error' => json_last_error_msg()
            ]);
            return new WP_Error('api_parse_error', 
                __('無法解析 API 回應', 'utrust-order-payment-change'));
        }
        
        // 檢查 API 回應狀態
        if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
            $status = $result['Status'] ?? 'N/A';
            $message = $result['Message'] ?? '無錯誤訊息';
            
            $this->log_error('取消授權 API 失敗', [
                'status' => $status,
                'message' => $message,
                'full_response' => $result
            ]);
            return new WP_Error('api_business_error', 
                sprintf(__('取消授權失敗: %s (%s)', 'utrust-order-payment-change'), $message, $status));
        }
        
        // 取得 Result 中的詳細資訊
        $result_data = $result['Result'] ?? [];
        
        $this->log_success('取消授權 API 成功', [
            'status' => $result['Status'],
            'message' => $result['Message'] ?? 'N/A',
            'merchant_id' => $result_data['MerchantID'] ?? 'N/A',
            'amount' => $result_data['Amt'] ?? 'N/A',
            'merchant_order_no' => $result_data['MerchantOrderNo'] ?? 'N/A',
            'trade_no' => $result_data['TradeNo'] ?? 'N/A',
            'full_result' => $result
        ]);
        
        return $result;
    }
    
    /**
     * 呼叫退款 API
     * 根據藍新金流官方文件 4.5.2 回應格式處理
     */
    private function call_refund_api($args, $account) {
        // 判斷是否為測試模式
        $is_test_mode = $this->is_test_mode();
        $url = $is_test_mode ? $this->refund_api_test_url : $this->refund_api_url;
        
        $this->log_info('呼叫藍新金流退款 API', [
            'url' => $url,
            'is_test_mode' => $is_test_mode,
            'merchant_id' => $args['MerchantID_'],
            'postdata_length' => strlen($args['PostData_'])
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
        
        // 解析回應（藍新金流返回 JSON 格式）
        $body = wp_remote_retrieve_body($response);
        $this->log_info('API 原始回應', ['body' => $body]);
        
        // 解析 JSON 回應
        $result = json_decode($body, true);
        
        if (!is_array($result)) {
            $this->log_error('API 回應解析失敗', [
                'body' => $body,
                'json_error' => json_last_error_msg()
            ]);
            return new WP_Error('api_parse_error', 
                __('無法解析 API 回應', 'utrust-order-payment-change'));
        }
        
        // 檢查 API 回應狀態
        if (!isset($result['Status']) || $result['Status'] !== 'SUCCESS') {
            $status = $result['Status'] ?? 'N/A';
            $message = $result['Message'] ?? '無錯誤訊息';
            
            $this->log_error('退款 API 失敗', [
                'status' => $status,
                'message' => $message,
                'full_response' => $result
            ]);
            return new WP_Error('api_business_error', 
                sprintf(__('退款失敗: %s (%s)', 'utrust-order-payment-change'), $message, $status));
        }
        
        // 取得 Result 中的詳細資訊
        $result_data = $result['Result'] ?? [];
        
        $this->log_success('退款 API 成功', [
            'status' => $result['Status'],
            'message' => $result['Message'] ?? 'N/A',
            'merchant_id' => $result_data['MerchantID'] ?? 'N/A',
            'amount' => $result_data['Amt'] ?? 'N/A',
            'merchant_order_no' => $result_data['MerchantOrderNo'] ?? 'N/A',
            'trade_no' => $result_data['TradeNo'] ?? 'N/A',
            'full_result' => $result
        ]);
        
        return $result;
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
        // 預設為正式模式
        return false;
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
