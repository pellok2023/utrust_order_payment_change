<?php
/**
 * 付款驗證模組
 * 負責在付款前檢查金流上限並自動切換金流
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Payment_Validator {
    
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
        
        // 註冊付款前的檢查 hooks
        $this->register_payment_hooks();
    }
    
    /**
     * 註冊付款相關的 hooks
     */
    private function register_payment_hooks() {
        // 在 WooCommerce 付款處理前檢查
        add_action('woocommerce_pre_payment_complete', array($this, 'check_payment_limit_before_payment'), 5, 2);
        
        // 在 NewebPay 付款表單生成前檢查
        add_action('ry_newebpay_gateway_checkout', array($this, 'check_payment_limit_before_newebpay'), 5, 3);
        
        // 在付款方法處理前檢查
        add_filter('woocommerce_available_payment_gateways', array($this, 'validate_payment_gateways'), 10, 1);
        
        // 攔截 NewebPay 的 process_payment
        add_filter('woocommerce_payment_gateway_process_payment', array($this, 'intercept_payment_processing'), 10, 3);
        
        // 在訂單創建後立即檢查
        add_action('woocommerce_new_order', array($this, 'check_payment_limit_on_order_created'), 10, 1);
        
        // 在付款頁面載入前檢查
        add_action('woocommerce_before_checkout_form', array($this, 'check_payment_limit_on_checkout'), 10, 1);
        
        // 攔截 NewebPay API 資訊獲取
        add_filter('ry_newebpay_gateway_get_api_info', array($this, 'override_newebpay_api_info'), 10, 1);
        
        // 攔截 RY WooCommerce Tools 的設定獲取
        add_filter('ry_wt_get_option', array($this, 'override_ry_wt_options'), 10, 2);
        
        // 直接攔截 WordPress 選項獲取
        add_filter('option_RY_WT_newebpay_gateway_MerchantID', array($this, 'override_merchant_id'), 10, 1);
        add_filter('option_RY_WT_newebpay_gateway_HashKey', array($this, 'override_hash_key'), 10, 1);
        add_filter('option_RY_WT_newebpay_gateway_HashIV', array($this, 'override_hash_iv'), 10, 1);
        
        // 添加前端腳本
        add_action('wp_footer', array($this, 'add_frontend_scripts'));
        
        // 註冊 AJAX 處理
        add_action('wp_ajax_utopc_check_payment_limit', array($this, 'ajax_check_payment_limit'));
        add_action('wp_ajax_nopriv_utopc_check_payment_limit', array($this, 'ajax_check_payment_limit'));
    }
    
    /**
     * 在付款完成前檢查金流上限
     */
    public function check_payment_limit_before_payment($order_id, $transaction_id = '') {
        $order = $this->hpos_helper->get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // 檢查是否為 NewebPay 相關的付款方式
        if (!$this->is_newebpay_payment($order)) {
            return;
        }
        
        $order_total = $this->hpos_helper->get_order_total($order);
        
        // 檢查當前金流帳號是否會超過上限
        if ($this->would_exceed_limit($order_total)) {
            $this->log_info("訂單 {$order_id} 付款金額 {$order_total} 會超過當前金流上限，準備切換金流");
            
            // 嘗試切換到可用的金流帳號
            $switch_result = $this->switch_to_available_account($order_total);
            
            if ($switch_result) {
                $this->log_success("已成功切換金流帳號，訂單 {$order_id} 可以繼續付款");
                
                // 重新生成付款表單（如果是 NewebPay）
                if ($this->is_newebpay_payment($order)) {
                    $this->regenerate_payment_form($order);
                }
            } else {
                $this->log_error("無法找到可用的金流帳號，訂單 {$order_id} 付款可能失敗");
                $this->add_payment_error_notice($order, '沒有可用的金流帳號，請稍後再試或聯繫客服');
            }
        }
    }
    
    /**
     * 在訂單創建後立即檢查
     */
    public function check_payment_limit_on_order_created($order_id) {
        $order = $this->hpos_helper->get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // 檢查是否為 NewebPay 相關的付款方式
        if (!$this->is_newebpay_payment($order)) {
            return;
        }
        
        $order_total = $this->hpos_helper->get_order_total($order);
        
        // 檢查當前金流帳號是否會超過上限
        if ($this->would_exceed_limit($order_total)) {
            $this->log_info("新訂單 {$order_id} 付款金額 {$order_total} 會超過當前金流上限，準備切換");
            
            // 嘗試切換到可用的金流帳號
            $switch_result = $this->switch_to_available_account($order_total);
            
            if ($switch_result) {
                $this->log_success("已成功切換金流帳號，訂單 {$order_id} 可以繼續付款");
                
                // 更新訂單的付款設定
                $this->update_newebpay_settings_for_order($order);
                
                // 添加訂單備註
                $order->add_order_note('UTOPC: 已自動切換金流帳號以處理此訂單');
                $order->save();
            } else {
                $this->log_error("無法找到可用的金流帳號，訂單 {$order_id} 付款可能失敗");
                $order->add_order_note('UTOPC: 警告 - 沒有可用的金流帳號處理此訂單');
                $order->save();
            }
        }
    }
    
    /**
     * 在付款頁面載入前檢查
     */
    public function check_payment_limit_on_checkout() {
        // 檢查購物車總金額
        if (WC()->cart && !WC()->cart->is_empty()) {
            $cart_total = WC()->cart->get_total('raw');
            
            // 檢查當前金流帳號是否會超過上限
            if ($this->would_exceed_limit($cart_total)) {
                $this->log_info("購物車總金額 {$cart_total} 會超過當前金流上限，準備切換");
                
                // 嘗試切換到可用的金流帳號
                $switch_result = $this->switch_to_available_account($cart_total);
                
                if ($switch_result) {
                    $this->log_success("已成功切換金流帳號，購物車可以繼續結帳");
                    
                    // 顯示通知給用戶
                    wc_add_notice(
                        __('系統已自動切換金流服務商以處理您的訂單，請繼續付款流程。', 'utrust-order-payment-change'),
                        'notice'
                    );
                } else {
                    $this->log_error("無法找到可用的金流帳號，購物車結帳可能失敗");
                    
                    // 顯示錯誤通知
                    wc_add_notice(
                        __('目前沒有可用的金流服務商處理您的訂單，請稍後再試或聯繫客服。', 'utrust-order-payment-change'),
                        'error'
                    );
                }
            }
        }
    }
    
    /**
     * 在 NewebPay 付款表單生成前檢查
     */
    public function check_payment_limit_before_newebpay($args, $order, $gateway) {
        $order_total = $order->get_total();
        
        // 檢查當前金流帳號是否會超過上限
        if ($this->would_exceed_limit($order_total)) {
            $this->log_info("NewebPay 訂單 {$order->get_id()} 付款金額 {$order_total} 會超過當前金流上限");
            
            // 嘗試切換到可用的金流帳號
            $switch_result = $this->switch_to_available_account($order_total);
            
            if ($switch_result) {
                $this->log_success("已成功切換金流帳號，NewebPay 訂單 {$order->get_id()} 可以繼續付款");
                
                // 重新獲取新的金流設定並重新生成表單
                $this->update_newebpay_settings_for_order($order);
                
                // 重新生成付款表單
                $this->regenerate_newebpay_form($order, $gateway);
            } else {
                $this->log_error("無法找到可用的金流帳號，NewebPay 訂單 {$order->get_id()} 付款可能失敗");
                throw new Exception('沒有可用的金流帳號，請稍後再試或聯繫客服');
            }
        }
    }
    
    /**
     * 驗證可用的付款方式
     */
    public function validate_payment_gateways($available_gateways) {
        // 檢查是否有可用的金流帳號
        $has_available_account = $this->has_available_account();
        
        if (!$has_available_account) {
            // 移除 NewebPay 相關的付款方式
            $newebpay_gateways = array(
                'ry_newebpay',
                'ry_newebpay_atm',
                'ry_newebpay_cc',
                'ry_newebpay_cvs',
                'ry_newebpay_webatm'
            );
            
            foreach ($newebpay_gateways as $gateway_id) {
                if (isset($available_gateways[$gateway_id])) {
                    unset($available_gateways[$gateway_id]);
                }
            }
            
            $this->log_warning("沒有可用的金流帳號，已移除 NewebPay 付款方式");
        }
        
        return $available_gateways;
    }
    
    /**
     * 攔截付款處理
     */
    public function intercept_payment_processing($result, $order_id, $gateway) {
        // 只處理 NewebPay 相關的付款方式
        if (!$this->is_newebpay_gateway($gateway->id)) {
            return $result;
        }
        
        $order = $this->hpos_helper->get_order($order_id);
        if (!$order) {
            return $result;
        }
        
        $order_total = $this->hpos_helper->get_order_total($order);
        
        // 檢查是否會超過金流上限
        if ($this->would_exceed_limit($order_total)) {
            $this->log_info("攔截付款處理：訂單 {$order_id} 會超過金流上限");
            
            // 嘗試切換金流
            $switch_result = $this->switch_to_available_account($order_total);
            
            if ($switch_result) {
                $this->log_success("已切換金流，重新處理付款");
                
                // 重新處理付款
                return $gateway->process_payment($order_id);
            } else {
                $this->log_error("無法切換金流，付款失敗");
                
                return array(
                    'result' => 'failure',
                    'messages' => '沒有可用的金流帳號，請稍後再試或聯繫客服'
                );
            }
        }
        
        return $result;
    }
    
    /**
     * 檢查是否會超過金流上限
     */
    private function would_exceed_limit($order_amount) {
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            return true; // 沒有啟用的帳號，視為超過上限
        }
        
        $current_amount = $active_account->monthly_amount;
        $amount_limit = $active_account->amount_limit;
        
        return ($current_amount + $order_amount) > $amount_limit;
    }
    
    /**
     * 檢查是否有可用的金流帳號
     */
    private function has_available_account() {
        $available_account = $this->database->get_next_available_account();
        return !empty($available_account);
    }
    
    /**
     * 檢查是否有帳號可以處理指定金額
     */
    private function has_account_for_amount($amount) {
        return $this->database->has_account_for_amount($amount);
    }
    
    /**
     * 切換到可用的金流帳號
     */
    private function switch_to_available_account($order_amount) {
        // 檢查是否啟用自動切換
        if (get_option('utopc_auto_switch_enabled', 'yes') !== 'yes') {
            $this->log_warning('自動切換功能已停用');
            return false;
        }
        
        // 檢查是否有帳號可以處理此金額
        if (!$this->has_account_for_amount($order_amount)) {
            $this->log_error("沒有金流帳號可以處理金額 {$order_amount} 的訂單");
            return false;
        }
        
        // 取得可以處理此金額的金流帳號
        $target_account = $this->database->get_account_can_handle_amount($order_amount);
        
        if (!$target_account) {
            $this->log_error('無法找到可以處理此金額的金流帳號');
            return false;
        }
        
        // 如果目標帳號就是當前啟用的帳號，不需要切換
        $active_account = $this->database->get_active_account();
        if ($active_account && $active_account->id === $target_account->id) {
            $this->log_info("當前金流帳號 {$target_account->account_name} 可以處理此訂單");
            return true;
        }
        
        // 執行切換到目標帳號
        $result = $this->payment_switcher->manual_switch_to_account($target_account->id);
        
        if (!is_wp_error($result) && $result) {
            $this->log_success("成功切換到金流帳號：{$target_account->account_name}");
            return true;
        } else {
            $this->log_error("切換金流帳號失敗：" . (is_wp_error($result) ? $result->get_error_message() : '未知錯誤'));
            return false;
        }
    }
    
    /**
     * 檢查是否為 NewebPay 付款方式
     */
    private function is_newebpay_payment($order) {
        $payment_method = $order->get_payment_method();
        return $this->is_newebpay_gateway($payment_method);
    }
    
    /**
     * 檢查是否為 NewebPay 付款方式 ID
     */
    private function is_newebpay_gateway($gateway_id) {
        $newebpay_gateways = array(
            'ry_newebpay',
            'ry_newebpay_atm',
            'ry_newebpay_cc',
            'ry_newebpay_cvs',
            'ry_newebpay_webatm'
        );
        
        return in_array($gateway_id, $newebpay_gateways);
    }
    
    /**
     * 重新生成付款表單
     */
    private function regenerate_payment_form($order) {
        // 清除快取
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // 更新訂單的付款設定
        $this->update_newebpay_settings_for_order($order);
        
        $this->log_info("已重新生成訂單 {$order->get_id()} 的付款表單");
    }
    
    /**
     * 重新生成 NewebPay 付款表單
     */
    private function regenerate_newebpay_form($order, $gateway) {
        // 清除快取
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // 更新訂單的付款設定
        $this->update_newebpay_settings_for_order($order);
        
        // 重新生成 MerchantOrderNo
        $active_account = $this->database->get_active_account();
        if ($active_account) {
            $merchant_order_no = $this->generate_merchant_order_no($order->get_id(), $active_account);
            $order->update_meta_data('_newebpay_MerchantOrderNo', $merchant_order_no);
            $order->save_meta_data();
        }
        
        $this->log_info("已重新生成 NewebPay 訂單 {$order->get_id()} 的付款表單");
        
        // 重新導向到付款頁面以重新生成表單
        $this->redirect_to_payment_page($order);
    }
    
    /**
     * 重新導向到付款頁面
     */
    private function redirect_to_payment_page($order) {
        // 添加訂單備註
        $order->add_order_note('UTOPC: 已切換金流帳號，重新導向到付款頁面');
        $order->save();
        
        // 使用 JavaScript 重新導向，避免 PHP header 問題
        echo '<script type="text/javascript">';
        echo 'window.location.href = "' . esc_url($order->get_checkout_payment_url()) . '";';
        echo '</script>';
        echo '<noscript>';
        echo '<meta http-equiv="refresh" content="0;url=' . esc_url($order->get_checkout_payment_url()) . '">';
        echo '</noscript>';
        echo '<p>' . __('正在重新導向到付款頁面...', 'utrust-order-payment-change') . '</p>';
        exit;
    }
    
    /**
     * AJAX 檢查付款上限
     */
    public function ajax_check_payment_limit() {
        check_ajax_referer('utopc_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order_total = floatval($_POST['order_total']);
        
        if ($order_id && $order_total) {
            $order = $this->hpos_helper->get_order($order_id);
            
            if ($order && $this->is_newebpay_payment($order)) {
                // 檢查是否會超過金流上限
                if ($this->would_exceed_limit($order_total)) {
                    $this->log_info("AJAX 檢查：訂單 {$order_id} 會超過金流上限");
                    
                    // 嘗試切換到可用的金流帳號
                    $switch_result = $this->switch_to_available_account($order_total);
                    
                    if ($switch_result) {
                        $this->log_success("AJAX 切換成功：訂單 {$order_id}");
                        
                        wp_send_json_success(array(
                            'switched' => true,
                            'message' => __('已自動切換金流服務商，請重新載入頁面', 'utrust-order-payment-change')
                        ));
                    } else {
                        wp_send_json_error(array(
                            'message' => __('沒有可用的金流服務商', 'utrust-order-payment-change')
                        ));
                    }
                } else {
                    wp_send_json_success(array(
                        'switched' => false,
                        'message' => __('金流帳號正常', 'utrust-order-payment-change')
                    ));
                }
            }
        }
        
        wp_send_json_error(array(
            'message' => __('無效的請求', 'utrust-order-payment-change')
        ));
    }
    
    /**
     * 添加前端 JavaScript 來處理金流切換
     */
    public function add_frontend_scripts() {
        if (is_checkout() || is_order_received_page()) {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                // 檢查是否需要重新載入付款表單
                if (sessionStorage.getItem('utopc_payment_switched') === 'true') {
                    sessionStorage.removeItem('utopc_payment_switched');
                    
                    // 顯示通知
                    if (typeof wc_add_notice === 'function') {
                        wc_add_notice('<?php _e('系統已自動切換金流服務商，正在更新付款表單...', 'utrust-order-payment-change'); ?>', 'notice');
                    }
                    
                    // 延遲重新載入，讓用戶看到通知
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                }
                
                // 定期檢查付款上限（僅在付款頁面）
                if (window.location.href.indexOf('checkout') !== -1 || window.location.href.indexOf('pay') !== -1) {
                    var checkInterval = setInterval(function() {
                        // 檢查是否有訂單 ID
                        var orderId = $('input[name="order_id"]').val() || $('input[name="order-pay"]').val();
                        var orderTotal = $('.order-total .amount').text().replace(/[^\d.-]/g, '') || $('.cart-total .amount').text().replace(/[^\d.-]/g, '');
                        
                        if (orderId && orderTotal) {
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'utopc_check_payment_limit',
                                    order_id: orderId,
                                    order_total: orderTotal,
                                    nonce: '<?php echo wp_create_nonce('utopc_nonce'); ?>'
                                },
                                success: function(response) {
                                    if (response.success && response.data.switched) {
                                        sessionStorage.setItem('utopc_payment_switched', 'true');
                                        window.location.reload();
                                    }
                                },
                                error: function() {
                                    // 靜默處理錯誤
                                }
                            });
                        }
                    }, 5000); // 每5秒檢查一次
                    
                    // 頁面卸載時清除定時器
                    $(window).on('beforeunload', function() {
                        clearInterval(checkInterval);
                    });
                }
            });
            </script>
            <?php
        }
    }
    
    /**
     * 更新訂單的 NewebPay 設定
     */
    private function update_newebpay_settings_for_order($order) {
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            return;
        }
        
        // 更新訂單的 MerchantOrderNo 以反映新的金流設定
        $merchant_order_no = $this->generate_merchant_order_no($order->get_id(), $active_account);
        $order->update_meta_data('_newebpay_MerchantOrderNo', $merchant_order_no);
        $order->save_meta_data();
        
        $this->log_info("已更新訂單 {$order->get_id()} 的 NewebPay 設定");
    }
    
    /**
     * 生成新的 MerchantOrderNo
     */
    private function generate_merchant_order_no($order_id, $account) {
        $prefix = get_option('newebpay_gateway_order_prefix', '');
        return $prefix . $order_id . '_' . $account->id . '_' . time();
    }
    
    /**
     * 攔截 NewebPay API 資訊獲取
     */
    public function override_newebpay_api_info($api_info) {
        $active_account = $this->database->get_active_account();
        
        if ($active_account) {
            return array(
                $active_account->merchant_id,
                $active_account->hash_key,
                $active_account->hash_iv
            );
        }
        
        return $api_info;
    }
    
    /**
     * 攔截 RY WooCommerce Tools 的設定獲取
     */
    public function override_ry_wt_options($value, $option_name) {
        $active_account = $this->database->get_active_account();
        
        if (!$active_account) {
            return $value;
        }
        
        switch ($option_name) {
            case 'newebpay_gateway_MerchantID':
                return $active_account->merchant_id;
            case 'newebpay_gateway_HashKey':
                return $active_account->hash_key;
            case 'newebpay_gateway_HashIV':
                return $active_account->hash_iv;
        }
        
        return $value;
    }
    
    /**
     * 攔截 MerchantID 選項獲取
     */
    public function override_merchant_id($value) {
        $active_account = $this->database->get_active_account();
        return $active_account ? $active_account->merchant_id : $value;
    }
    
    /**
     * 攔截 HashKey 選項獲取
     */
    public function override_hash_key($value) {
        $active_account = $this->database->get_active_account();
        return $active_account ? $active_account->hash_key : $value;
    }
    
    /**
     * 攔截 HashIV 選項獲取
     */
    public function override_hash_iv($value) {
        $active_account = $this->database->get_active_account();
        return $active_account ? $active_account->hash_iv : $value;
    }
    
    /**
     * 添加付款錯誤通知
     */
    private function add_payment_error_notice($order, $message) {
        wc_add_notice($message, 'error');
        
        // 添加訂單備註
        $order->add_order_note('UTOPC: ' . $message);
        $order->save();
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
            'module' => 'payment_validator'
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
