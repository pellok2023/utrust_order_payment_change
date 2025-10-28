<?php
/**
 * 藍新金流回傳處理器
 * 解決多金流帳號切換後的驗證問題
 * 當 RY Tools 驗證失敗時，直接接管處理流程
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_NewebPay_Response_Handler {
    
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
        
        // 記錄初始化
        error_log('[UTOPC] 🚀 UTOPC_NewebPay_Response_Handler initialized');
        
        // 攔截 RY Tools 的驗證失敗，接管處理流程
        add_action('woocommerce_api_ry_newebpay_callback', [$this, 'handle_callback'], 1);
        
        error_log('[UTOPC] ✅ Hook registered: woocommerce_api_ry_newebpay_callback with priority 1');
    }
    
    /**
     * 處理藍新金流回傳
     */
    public function handle_callback() {
        $this->log('🔵 UTOPC handle_callback() started');
        
        if (empty($_POST)) {
            $this->log('🔴 No POST data');
            return;
        }
        
        try {
            $ipn_info = wp_unslash($_POST);
            $merchant_id = isset($ipn_info['MerchantID']) ? $ipn_info['MerchantID'] : '';
            $trade_info = isset($ipn_info['TradeInfo']) ? $ipn_info['TradeInfo'] : '';
            
            $this->log('📨 Received callback', [
                'merchant_id' => $merchant_id,
                'status' => $ipn_info['Status'] ?? 'N/A',
                'has_trade_info' => !empty($trade_info)
            ]);
            
            if (empty($merchant_id) || empty($trade_info)) {
                $this->log('❌ Missing required data');
                $this->die_error();
                return;
            }
            
            // 移除我們的 hook，避免重複執行
            remove_action('woocommerce_api_ry_newebpay_callback', [$this, 'handle_callback'], 1);
            
            // 直接接管處理流程
            $this->log('✅ Taking over callback processing');
            $this->process_callback_with_correct_account($ipn_info);
            
        } catch (Exception $e) {
            $this->log('❌ Exception in handle_callback', ['error' => $e->getMessage()], 'error');
            $this->die_error();
        }
    }
    
    /**
     * 使用正確的金流帳號處理回傳
     */
    private function process_callback_with_correct_account($ipn_info) {
        $merchant_id = $ipn_info['MerchantID'] ?? '';
        $trade_info = $ipn_info['TradeInfo'] ?? '';
        
        $this->log('🔍 Finding correct account for processing');
        
        // 找到正確的金流帳號
        $account = $this->find_correct_account($merchant_id, $trade_info);
        
        if (!$account) {
            $this->log('❌ Cannot find correct account', null, 'error');
            $this->die_error();
            return;
        }
        
        $this->log('✅ Found correct account', [
            'account_name' => $account->account_name,
            'merchant_id' => $account->merchant_id
        ]);
        
        // 使用正確的金流資訊解密並處理
        $this->process_with_account($ipn_info, $account);
    }
    
    /**
     * 使用指定帳號處理回傳
     */
    private function process_with_account($ipn_info, $account) {
        try {
            // 解密 TradeInfo
            $decrypted_string = $this->decrypt_trade_info($ipn_info['TradeInfo'], $account);
            
            if (!$decrypted_string) {
                $this->log('❌ Failed to decrypt TradeInfo', null, 'error');
                $this->die_error();
                return;
            }
            
            // 解析 JSON
            $decrypted_info = json_decode($decrypted_string);
            if (!$decrypted_info) {
                $this->log('❌ Failed to parse JSON', [
                    'json_error' => json_last_error_msg(),
                    'json_error_code' => json_last_error(),
                    'first_100_chars' => substr($decrypted_string, 0, 100)
                ], 'error');
                $this->die_error();
                return;
            }
            
            $this->log('✅ Successfully parsed JSON', [
                'status' => $decrypted_info->Status ?? 'N/A',
                'has_result' => isset($decrypted_info->Result),
                'message' => $decrypted_info->Message ?? 'N/A'
            ]);
            
            // 處理不同的 JSON 結構
            $merchant_order_no = null;
            $status = null;
            
            if (isset($decrypted_info->Result) && isset($decrypted_info->Result->MerchantOrderNo)) {
                $merchant_order_no = $decrypted_info->Result->MerchantOrderNo;
                $status = $decrypted_info->Status ?? 'N/A';
            } elseif (isset($decrypted_info->MerchantOrderNo)) {
                $merchant_order_no = $decrypted_info->MerchantOrderNo;
                $status = $decrypted_info->Status ?? 'N/A';
            }
            
            $this->log('✅ Extracted order info', [
                'merchant_order_no' => $merchant_order_no,
                'status' => $status
            ]);
            
            // 處理訂單狀態更新
            $this->process_order_status($decrypted_info, $account);
            
        } catch (Exception $e) {
            $this->log('❌ Exception in process_with_account', ['error' => $e->getMessage()], 'error');
            $this->die_error();
        }
    }
    
    /**
     * 處理訂單狀態更新（參考 RY Tools 的 doing_callback）
     */
    private function process_order_status($ipn_info, $account) {
        // 取得訂單 ID
        $order_id = $this->get_order_id($ipn_info);
        
        if (!$order_id) {
            $this->log('❌ Cannot get order ID', null, 'error');
            $this->die_error();
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log('❌ Order not found', ['order_id' => $order_id], 'error');
            $this->die_error();
            return;
        }
        
        $this->log('✅ Found order', [
            'order_id' => $order->get_id(),
            'current_status' => $order->get_status(),
            'is_cancelled' => $order->get_status() === 'cancelled',
            'is_refunded' => $order->get_status() === 'refunded'
        ]);
        
        // 如果是已取消或已退款的訂單，不處理付款
        if ($order->get_status() === 'cancelled' || $order->get_status() === 'refunded') {
            $this->log('⚠️ Order is cancelled or refunded, skipping payment processing', [
                'order_id' => $order->get_id(),
                'status' => $order->get_status()
            ]);
            $this->die_success();
            return;
        }
        
        // 取得付款狀態
        $payment_status = $this->get_payment_status($ipn_info);
        $this->log('📊 Payment status', ['status' => $payment_status]);
        
        // 更新交易 ID
        $transaction_id = $this->get_transaction_id($ipn_info);
        if ($transaction_id && $order->get_transaction_id() != $transaction_id) {
            $order->set_transaction_id($transaction_id);
            $order->save();
            $this->log('✅ Updated transaction ID', ['transaction_id' => $transaction_id]);
        }
        
        // 根據付款狀態處理訂單
        $this->handle_payment_status($order, $ipn_info, $payment_status);
        
        // 觸發相關 actions
        do_action('ry_newebpay_gateway_response_status_' . $payment_status, $ipn_info->Result ?? null, $order);
        do_action('ry_newebpay_gateway_response', $ipn_info->Result ?? null, $order);
        
        $this->log('✅ Order processing completed', [
            'order_id' => $order->get_id(),
            'new_status' => $order->get_status()
        ]);
        
        $this->die_success();
    }
    
    /**
     * 處理付款狀態（參考 RY Tools 的 payment_status_SUCCESS）
     */
    private function handle_payment_status($order, $ipn_info, $payment_status) {
        if ($payment_status === 'SUCCESS') {
            $this->log('💰 Processing SUCCESS payment');
            
            // 處理超商取貨資訊
            if (isset($ipn_info->StoreCode)) {
                $this->handle_cvs_store_info($order, $ipn_info);
            }
            
            // 處理付款完成
            if (!$order->is_paid()) {
                // 檢查不同的 JSON 結構
                $pay_time = null;
                $bank_code = null;
                $code_no = null;
                $barcode_1 = null;
                
                if (isset($ipn_info->Result)) {
                    $pay_time = $ipn_info->Result->PayTime ?? null;
                    $bank_code = $ipn_info->Result->BankCode ?? null;
                    $code_no = $ipn_info->Result->CodeNo ?? null;
                    $barcode_1 = $ipn_info->Result->Barcode_1 ?? null;
                } else {
                    $pay_time = $ipn_info->PayTime ?? null;
                    $bank_code = $ipn_info->BankCode ?? null;
                    $code_no = $ipn_info->CodeNo ?? null;
                    $barcode_1 = $ipn_info->Barcode_1 ?? null;
                }
                
                $this->log('🔍 Payment method detection', [
                    'has_pay_time' => !empty($pay_time),
                    'has_bank_code' => !empty($bank_code),
                    'has_code_no' => !empty($code_no),
                    'has_barcode_1' => !empty($barcode_1),
                    'pay_time' => $pay_time
                ]);
                
                if (!empty($pay_time)) {
                    // 信用卡付款
                    $order->add_order_note(__('Payment completed', 'ry-woocommerce-tools'));
                    $order->payment_complete();
                    $this->log('✅ Credit card payment completed');
                } elseif (!empty($bank_code)) {
                    // ATM 付款
                    $this->handle_atm_payment($order, $ipn_info);
                } elseif (!empty($code_no)) {
                    // 超商付款
                    $this->handle_cvs_payment($order, $ipn_info);
                } elseif (!empty($barcode_1)) {
                    // 條碼付款
                    $this->handle_barcode_payment($order, $ipn_info);
                } else {
                    $this->log('⚠️ No payment method detected, completing payment anyway', [
                        'order_status' => $order->get_status(),
                        'is_paid' => $order->is_paid()
                    ]);
                    // 如果沒有檢測到特定付款方式，但狀態是 SUCCESS，仍然完成付款
                    $order->add_order_note(__('Payment completed', 'ry-woocommerce-tools'));
                    $order->payment_complete();
                }
            } else {
                $this->log('ℹ️ Order already paid', [
                    'order_status' => $order->get_status(),
                    'is_paid' => $order->is_paid()
                ]);
            }
        } else {
            // 其他狀態處理
            $this->log('⚠️ Unknown payment status', ['status' => $payment_status]);
            if ($order->is_paid()) {
                $order->add_order_note(__('Payment failed within paid order', 'ry-woocommerce-tools'));
            } else {
                $order->update_status('failed', sprintf(
                    __('Payment failed: %1$s', 'ry-woocommerce-tools'),
                    $payment_status
                ));
            }
        }
    }
    
    /**
     * 處理 ATM 付款
     */
    private function handle_atm_payment($order, $ipn_info) {
        // 處理不同的 JSON 結構
        $result = isset($ipn_info->Result) ? $ipn_info->Result : $ipn_info;
        
        $expireDate = new DateTime($result->ExpireDate . ' ' . $result->ExpireTime, new DateTimeZone('Asia/Taipei'));
        
        $order->update_meta_data('_newebpay_atm_BankCode', $result->BankCode);
        $order->update_meta_data('_newebpay_atm_vAccount', $result->CodeNo);
        $order->update_meta_data('_newebpay_atm_ExpireDate', $expireDate->format(DATE_ATOM));
        $order->save_meta_data();
        
        $order->update_status('on-hold');
        $this->log('✅ ATM payment info saved');
    }
    
    /**
     * 處理超商付款
     */
    private function handle_cvs_payment($order, $ipn_info) {
        $expireDate = new DateTime($ipn_info->ExpireDate . ' ' . $ipn_info->ExpireTime, new DateTimeZone('Asia/Taipei'));
        
        $order->update_meta_data('_newebpay_cvs_PaymentNo', $ipn_info->CodeNo);
        $order->update_meta_data('_newebpay_cvs_ExpireDate', $expireDate->format(DATE_ATOM));
        $order->save_meta_data();
        
        $order->update_status('on-hold');
        $this->log('✅ CVS payment info saved');
    }
    
    /**
     * 處理條碼付款
     */
    private function handle_barcode_payment($order, $ipn_info) {
        $expireDate = new DateTime($ipn_info->ExpireDate . ' ' . $ipn_info->ExpireTime, new DateTimeZone('Asia/Taipei'));
        
        $order->update_meta_data('_newebpay_barcode_Barcode1', $ipn_info->Barcode_1);
        $order->update_meta_data('_newebpay_barcode_Barcode2', $ipn_info->Barcode_2);
        $order->update_meta_data('_newebpay_barcode_Barcode3', $ipn_info->Barcode_3);
        $order->update_meta_data('_newebpay_barcode_ExpireDate', $expireDate->format(DATE_ATOM));
        $order->save_meta_data();
        
        $order->update_status('on-hold');
        $this->log('✅ Barcode payment info saved');
    }
    
    /**
     * 處理超商取貨資訊
     */
    private function handle_cvs_store_info($order, $ipn_info) {
        if ($order->get_meta('_shipping_cvs_store_ID') == '') {
            $order->set_shipping_company('');
            $order->set_shipping_address_2('');
            $order->set_shipping_city('');
            $order->set_shipping_state('');
            $order->set_shipping_postcode('');
            $order->set_shipping_last_name('');
            $order->set_shipping_first_name($ipn_info->CVSCOMName);
            
            $order->add_order_note(sprintf(
                __('CVS store %1$s (%2$s)', 'ry-woocommerce-tools'),
                $ipn_info->StoreName,
                $ipn_info->StoreCode
            ));
            
            $order->update_meta_data('_shipping_cvs_store_ID', $ipn_info->StoreCode);
            $order->update_meta_data('_shipping_cvs_store_name', $ipn_info->StoreName);
            $order->update_meta_data('_shipping_cvs_store_address', $ipn_info->StoreAddr);
            $order->update_meta_data('_shipping_cvs_store_type', $ipn_info->StoreType);
            
            if (version_compare(WC_VERSION, '5.6.0', '<')) {
                $order->update_meta_data('_shipping_phone', $ipn_info->CVSCOMPhone);
            } else {
                $order->set_shipping_phone($ipn_info->CVSCOMPhone);
            }
            
            $order->set_shipping_address_1($ipn_info->StoreAddr);
            $order->save();
            
            // 處理超商物流資訊
            $shipping_list = $order->get_meta('_newebpay_shipping_info', true);
            if (!is_array($shipping_list)) {
                $shipping_list = [];
            }
            if (!isset($shipping_list[$ipn_info->TradeNo])) {
                $shipping_list[$ipn_info->TradeNo] = [];
            }
            $shipping_list[$ipn_info->TradeNo]['ID'] = $ipn_info->TradeNo;
            $shipping_list[$ipn_info->TradeNo]['Type'] = $ipn_info->StoreType;
            $shipping_list[$ipn_info->TradeNo]['PaymentNo'] = $ipn_info->TradeNo;
            $shipping_list[$ipn_info->TradeNo]['store_ID'] = $ipn_info->StoreCode;
            $shipping_list[$ipn_info->TradeNo]['create'] = (string) new WC_DateTime();
            $shipping_list[$ipn_info->TradeNo]['edit'] = (string) new WC_DateTime();
            $shipping_list[$ipn_info->TradeNo]['amount'] = $ipn_info->Amt;
            $shipping_list[$ipn_info->TradeNo]['IsCollection'] = $ipn_info->TradeType;
            
            $order->update_meta_data('_newebpay_shipping_info', $shipping_list);
            $order->save_meta_data();
            
            if ($ipn_info->TradeType == '1') {
                if ($order->get_status() == 'pending') {
                    $order->update_status('processing');
                }
            }
            
            $this->log('✅ CVS store info saved');
        }
    }
    
    /**
     * 嘗試用所有金流帳號解密，找到能成功的
     */
    private function find_correct_account($merchant_id, $trade_info) {
        $this->log('🔎 Trying to find account by MerchantID', ['merchant_id' => $merchant_id]);
        
        // 先嘗試用 MerchantID 直接找
        $account = $this->get_account_by_merchant_id($merchant_id);
        
        if ($account) {
            $this->log('📋 Found account by MerchantID', [
                'account_id' => $account->id,
                'account_name' => $account->account_name
            ]);
            
            if ($this->try_decrypt_with_account($trade_info, $account)) {
                $this->log('✅ Account can decrypt successfully', ['account_name' => $account->account_name]);
                return $account;
            }
        }
        
        // 如果找不到或解不了，嘗試所有帳號
        $this->log('🔍 Trying all accounts to decrypt');
        $all_accounts = $this->database->get_all_accounts();
        
        if ($all_accounts) {
            $this->log('📊 Total accounts to test', ['count' => count($all_accounts)]);
            
            foreach ($all_accounts as $test_account) {
                $this->log('🧪 Testing account', [
                    'account_id' => $test_account->id,
                    'account_name' => $test_account->account_name,
                    'merchant_id' => $test_account->merchant_id
                ]);
                
                if ($this->try_decrypt_with_account($trade_info, $test_account)) {
                    $this->log('✅ Found correct account', [
                        'account_name' => $test_account->account_name,
                        'original_merchant_id' => $merchant_id
                    ]);
                    return $test_account;
                }
            }
        } else {
            $this->log('❌ No accounts available in database', null, 'error');
        }
        
        $this->log('❌ No suitable account found', null, 'error');
        return null;
    }
    
    /**
     * 嘗試解密並記錄詳細資訊
     */
    private function try_decrypt_with_account($trade_info, $account) {
        try {
            $this->log('🔧 Starting decryption process', [
                'account_name' => $account->account_name,
                'trade_info_length' => strlen($trade_info)
            ]);
            
            $decrypted = $this->decrypt_trade_info($trade_info, $account);
            
            if ($decrypted === false) {
                $this->log('❌ Decryption returned false');
                return false;
            }
            
            $this->log('🔍 Decryption result', [
                'is_object' => is_object($decrypted),
                'is_array' => is_array($decrypted),
                'is_string' => is_string($decrypted),
                'is_null' => is_null($decrypted),
                'type' => gettype($decrypted)
            ]);
            
            if (is_string($decrypted)) {
                $this->log('📝 Raw decrypted string', [
                    'length' => strlen($decrypted),
                    'first_100_chars' => substr($decrypted, 0, 100),
                    'last_100_chars' => substr($decrypted, -100)
                ]);
                
                // 嘗試解析 JSON
                $json_data = json_decode($decrypted);
                $json_error = json_last_error_msg();
                
                $this->log('🔍 JSON parsing', [
                    'json_error' => $json_error,
                    'json_error_code' => json_last_error(),
                    'is_object' => is_object($json_data),
                    'is_array' => is_array($json_data)
                ]);
                
                // 檢查藍新回傳的 JSON 結構
                if ($json_data && isset($json_data->Result) && isset($json_data->Result->MerchantOrderNo)) {
                    $this->log('✅ Decryption successful (Result structure)', [
                        'merchant_order_no' => $json_data->Result->MerchantOrderNo,
                        'status' => $json_data->Status ?? 'N/A',
                        'trade_no' => $json_data->Result->TradeNo ?? 'N/A'
                    ]);
                    return true;
                } elseif ($json_data && isset($json_data->MerchantOrderNo)) {
                    $this->log('✅ Decryption successful (direct structure)', [
                        'merchant_order_no' => $json_data->MerchantOrderNo,
                        'status' => $json_data->Status ?? 'N/A'
                    ]);
                    return true;
                } else {
                    $this->log('⚠️ JSON parsing failed or missing MerchantOrderNo', [
                        'has_result' => isset($json_data->Result),
                        'has_merchant_order_no' => isset($json_data->MerchantOrderNo),
                        'has_result_merchant_order_no' => isset($json_data->Result->MerchantOrderNo)
                    ]);
                }
            } elseif (is_object($decrypted) && isset($decrypted->MerchantOrderNo)) {
                $this->log('✅ Decryption successful (already object)', [
                    'merchant_order_no' => $decrypted->MerchantOrderNo,
                    'status' => $decrypted->Status ?? 'N/A'
                ]);
                return true;
            } else {
                $this->log('⚠️ Unexpected decryption result type');
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->log('⚠️ Decryption exception', ['error' => $e->getMessage()], 'warning');
            return false;
        }
    }
    
    /**
     * 檢查能否用指定帳號解密
     */
    private function can_decrypt_with_account($trade_info, $account) {
        try {
            $decrypted = $this->decrypt_trade_info($trade_info, $account);
            return $decrypted && isset($decrypted->MerchantOrderNo);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 解密 TradeInfo（參考藍新官方文件）
     */
    private function decrypt_trade_info($trade_info, $account) {
        $this->log('🔧 Decrypting with account', [
            'account_name' => $account->account_name,
            'hash_key_length' => strlen($account->hash_key),
            'hash_iv_length' => strlen($account->hash_iv),
            'hash_key_preview' => substr($account->hash_key, 0, 10),
            'hash_iv_preview' => substr($account->hash_iv, 0, 10)
        ]);
        
        // 使用 hex2bin 轉換
        $string = hex2bin($trade_info);
        
        // 先解密，再去除 padding（按照藍新官方文件順序）
        if (function_exists('openssl_decrypt')) {
            $decrypt_string = openssl_decrypt(
                $string,
                'AES-256-CBC',
                $account->hash_key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $account->hash_iv
            );
        } else {
            return false;
        }
        
        if ($decrypt_string) {
            $this->log('🔍 After decryption, before padding removal', [
                'length' => strlen($decrypt_string),
                'last_byte' => ord(substr($decrypt_string, -1))
            ]);
            
            // 去除 PKCS7 padding（按照藍新官方文件）
            $decrypt_string = $this->strip_padding($decrypt_string);
            
            if ($decrypt_string !== false) {
                $this->log('✅ Padding removed successfully', [
                    'final_length' => strlen($decrypt_string),
                    'first_100_chars' => substr($decrypt_string, 0, 100)
                ]);
                
                return $decrypt_string;
            } else {
                $this->log('❌ Padding removal failed');
                return false;
            }
        }
        
        $this->log('❌ Decryption failed');
        return false;
    }
    
    /**
     * 去除 PKCS7 padding（參考藍新官方文件）
     */
    private function strip_padding($string) {
        $slast = ord(substr($string, -1));
        $slastc = chr($slast);
        $pcheck = substr($string, -$slast);
        
        if (preg_match("/$slastc{" . $slast . "}/", $string)) {
            $string = substr($string, 0, strlen($string) - $slast);
            return $string;
        } else {
            return false;
        }
    }
    
    /**
     * 根據 MerchantID 取得金流帳號
     */
    private function get_account_by_merchant_id($merchant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'utopc_payment_accounts';
        
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE merchant_id = %s LIMIT 1",
            $merchant_id
        ));
        
        return $account;
    }
    
    /**
     * 取得訂單 ID
     */
    private function get_order_id($ipn_info) {
        $merchant_order_no = null;
        
        // 處理不同的 JSON 結構
        if (isset($ipn_info->Result) && isset($ipn_info->Result->MerchantOrderNo)) {
            $merchant_order_no = $ipn_info->Result->MerchantOrderNo;
            $this->log('📋 Found MerchantOrderNo in Result', ['merchant_order_no' => $merchant_order_no]);
        } elseif (isset($ipn_info->MerchantOrderNo)) {
            $merchant_order_no = $ipn_info->MerchantOrderNo;
            $this->log('📋 Found MerchantOrderNo in root', ['merchant_order_no' => $merchant_order_no]);
        }
        
        if (empty($merchant_order_no)) {
            $this->log('❌ No MerchantOrderNo found', [
                'has_result' => isset($ipn_info->Result),
                'has_merchant_order_no' => isset($ipn_info->MerchantOrderNo),
                'has_result_merchant_order_no' => isset($ipn_info->Result->MerchantOrderNo)
            ], 'error');
            return false;
        }
        
        // 移除前綴取得訂單 ID
        $prefix = RY_WT::get_option('newebpay_gateway_order_prefix', '');
        $this->log('🔍 Processing order ID', [
            'merchant_order_no' => $merchant_order_no,
            'prefix' => $prefix,
            'prefix_length' => strlen($prefix)
        ]);
        
        if ($prefix && strpos($merchant_order_no, $prefix) === 0) {
            $order_id = substr($merchant_order_no, strlen($prefix));
            $this->log('✅ Removed prefix', [
                'original' => $merchant_order_no,
                'prefix' => $prefix,
                'order_id' => $order_id
            ]);
        } else {
            $order_id = $merchant_order_no;
            $this->log('ℹ️ No prefix to remove', [
                'merchant_order_no' => $merchant_order_no,
                'order_id' => $order_id
            ]);
        }
        
        $final_order_id = intval($order_id);
        $this->log('🎯 Final order ID', [
            'string_order_id' => $order_id,
            'int_order_id' => $final_order_id
        ]);
        
        return $final_order_id;
    }
    
    /**
     * 取得付款狀態
     */
    private function get_payment_status($ipn_info) {
        return $ipn_info->Status ?? 'UNKNOWN';
    }
    
    /**
     * 取得交易 ID
     */
    private function get_transaction_id($ipn_info) {
        // 處理不同的 JSON 結構
        if (isset($ipn_info->Result) && isset($ipn_info->Result->TradeNo)) {
            return $ipn_info->Result->TradeNo;
        } elseif (isset($ipn_info->TradeNo)) {
            return $ipn_info->TradeNo;
        }
        
        return '';
    }
    
    /**
     * 成功回應
     */
    private function die_success() {
        status_header(200);
        echo '1|OK';
        exit;
    }
    
    /**
     * 錯誤回應
     */
    private function die_error() {
        status_header(400);
        echo '0|Error';
        exit;
    }
    
    /**
     * 統一日誌記錄方法
     */
    private function log($message, $context = null, $level = 'info') {
        $log_message = sprintf('[UTOPC Handler] %s', $message);
        
        if ($context !== null && is_array($context)) {
            $log_message .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        } elseif ($context !== null) {
            $log_message .= ' | ' . $context;
        }
        
        // 使用 RY Tools 的日誌系統
        if (class_exists('RY_NewebPay_Gateway')) {
            RY_NewebPay_Gateway::log($log_message, $level);
        } else {
            // 如果 RY Tools 還沒載入，使用 WordPress 錯誤日誌
            error_log($log_message);
        }
    }
}