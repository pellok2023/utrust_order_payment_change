<?php
/**
 * 藍新金流退款按鈕類別
 * 直接在訂單頁面添加退款按鈕
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Refund_Button {
    
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
        
        // 添加退款按鈕到訂單頁面 - 使用多個 hooks 確保顯示
        add_action('woocommerce_order_item_add_action_buttons', array($this, 'add_refund_button'), 10, 1);
        
        // 處理 AJAX 退款請求
        add_action('wp_ajax_utopc_process_refund', array($this, 'ajax_process_refund'));
        
        // 添加必要的 JavaScript 和 CSS
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
    }
    
    /**
     * 添加退款按鈕到訂單頁面
     * 
     * @param WC_Order $order 訂單物件
     */
    public function add_refund_button($order) {
        if (!$this->is_newebpay_order($order)) {
            return;
        }
        
        // 檢查訂單是否可以退款
        $eligibility = $this->check_refund_eligibility($order);
        
        if (!$eligibility['can_refund']) {
            return;
        }
        
        // 取得交易資訊
        $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
        $trade_no = $order->get_transaction_id();
        
        // 取得金流帳號資訊
        $account_id = $order->get_meta('_utopc_payment_account_id', true);
        $account_name = $order->get_meta('_utopc_payment_account_name', true);
        $company_name = $order->get_meta('_utopc_payment_company_name', true);
        $merchant_id = $order->get_meta('_utopc_payment_merchant_id', true);
        
        // 添加藍新金流退款按鈕
        ?>
        <button type="button" class="button button-primary utopc-newebpay-refund-btn" 
                data-order-id="<?php echo $order->get_id(); ?>"
                data-max-amount="<?php echo $eligibility['max_refund_amount']; ?>"
                data-merchant-order-no="<?php echo esc_attr($merchant_order_no); ?>"
                data-trade-no="<?php echo esc_attr($trade_no); ?>"
                data-account-id="<?php echo esc_attr($account_id); ?>"
                data-account-name="<?php echo esc_attr($account_name); ?>"
                data-company-name="<?php echo esc_attr($company_name); ?>"
                data-merchant-id="<?php echo esc_attr($merchant_id); ?>"
                style="background: #0073aa; border-color: #0073aa; margin-left: 10px;">
            <span class="dashicons dashicons-undo" style="vertical-align: middle; margin-right: 5px;"></span>
            藍新金流退款
        </button>
        <?php
    }
    
    /**
     * 添加必要的 JavaScript 和 CSS
     */
    public function enqueue_scripts($hook) {
        // 只在訂單編輯頁面載入
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        global $post;
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        
        // 載入 jQuery（如果尚未載入）
        wp_enqueue_script('jquery');
        
        // 添加自定義 CSS
        add_action('admin_head', array($this, 'add_custom_styles'));
        
        // 添加自定義 JavaScript
        add_action('admin_footer', array($this, 'add_custom_scripts'));
    }
    
    /**
     * 添加自定義 CSS
     */
    public function add_custom_styles() {
        global $post;
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        ?>
        <style>
        .utopc-newebpay-refund-btn {
            background: #0073aa !important;
            border-color: #0073aa !important;
            color: white !important;
            margin: 5px !important;
        }
        .utopc-newebpay-refund-btn:hover {
            background: #005a87 !important;
            border-color: #005a87 !important;
        }
        .utopc-refund-dialog {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            z-index: 9999;
            min-width: 500px;
            max-width: 90vw;
        }
        .utopc-refund-dialog h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #0073aa;
            padding-bottom: 10px;
        }
        .utopc-refund-dialog .form-field {
            margin: 15px 0;
        }
        .utopc-refund-dialog label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        .utopc-refund-dialog input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .utopc-refund-dialog textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            resize: vertical;
            font-size: 14px;
        }
        .utopc-refund-dialog .info-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .utopc-refund-dialog .info-item {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .utopc-refund-dialog .info-item:last-child {
            border-bottom: none;
        }
        .utopc-refund-dialog .info-label {
            font-weight: bold;
            color: #666;
        }
        .utopc-refund-dialog .info-value {
            color: #333;
            font-family: monospace;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .utopc-refund-dialog .button-group {
            text-align: right;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .utopc-refund-dialog .button-group .button {
            margin-left: 10px;
            padding: 8px 16px;
        }
        .utopc-refund-dialog .button-primary {
            background: #0073aa;
            border-color: #0073aa;
        }
        .utopc-refund-dialog .button-primary:hover {
            background: #005a87;
            border-color: #005a87;
        }
        .utopc-refund-dialog .button-secondary {
            background: #6c757d;
            border-color: #6c757d;
            color: white;
        }
        .utopc-refund-dialog .button-secondary:hover {
            background: #545b62;
            border-color: #4e555b;
        }
        .utopc-refund-dialog .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        .utopc-refund-dialog .error-message {
            color: #dc3545;
            font-size: 12px;
            margin-top: 5px;
        }
        </style>
        <?php
    }
    
    /**
     * 添加自定義 JavaScript
     */
    public function add_custom_scripts() {
        global $post;
        if (!$post || $post->post_type !== 'shop_order') {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // 創建退款對話框
            var refundDialog = $('<div class="utopc-refund-dialog">' +
                '<h3>藍新金流退款</h3>' +
                '<div class="info-section">' +
                    '<div class="info-item">' +
                        '<span class="info-label">金流帳號ID:</span>' +
                        '<span class="info-value" id="dialog-account-id"></span>' +
                    '</div>' +
                    '<div class="info-item">' +
                        '<span class="info-label">金流帳號名稱:</span>' +
                        '<span class="info-value" id="dialog-account-name"></span>' +
                    '</div>' +
                    '<div class="info-item">' +
                        '<span class="info-label">公司名稱:</span>' +
                        '<span class="info-value" id="dialog-company-name"></span>' +
                    '</div>' +
                    '<div class="info-item">' +
                        '<span class="info-label">Merchant ID:</span>' +
                        '<span class="info-value" id="dialog-merchant-id"></span>' +
                    '</div>' +
                    '<div class="info-item">' +
                        '<span class="info-label">Merchant Order No:</span>' +
                        '<span class="info-value" id="dialog-merchant-order-no"></span>' +
                    '</div>' +
                    '<div class="info-item">' +
                        '<span class="info-label">Trade No:</span>' +
                        '<span class="info-value" id="dialog-trade-no"></span>' +
                    '</div>' +
                '</div>' +
                '<div class="form-field">' +
                    '<label for="refund-amount">退款金額 (NT$):</label>' +
                    '<input type="number" id="refund-amount" step="0.01" min="0.01" placeholder="請輸入退款金額">' +
                    '<small id="max-amount-text" style="color: #666; font-size: 12px;"></small>' +
                    '<div class="error-message" id="amount-error" style="display: none;"></div>' +
                '</div>' +
                '<div class="form-field">' +
                    '<label for="refund-reason">退款原因 (選填):</label>' +
                    '<textarea id="refund-reason" rows="3" placeholder="請輸入退款原因..."></textarea>' +
                '</div>' +
                '<div class="button-group">' +
                    '<button type="button" class="button button-secondary" id="cancel-refund">取消</button>' +
                    '<button type="button" class="button button-primary" id="confirm-refund">確認退款</button>' +
                '</div>' +
            '</div>');

            $('body').append(refundDialog);

            // 處理退款按鈕點擊
            $(document).on('click', '.utopc-newebpay-refund-btn', function() {
                var orderId = $(this).data('order-id');
                var maxAmount = $(this).data('max-amount');
                var merchantOrderNo = $(this).data('merchant-order-no');
                var tradeNo = $(this).data('trade-no');
                var accountId = $(this).data('account-id');
                var accountName = $(this).data('account-name');
                var companyName = $(this).data('company-name');
                var merchantId = $(this).data('merchant-id');

                // 填充對話框資料
                $('#dialog-account-id').text(accountId || '無');
                $('#dialog-account-name').text(accountName || '無');
                $('#dialog-company-name').text(companyName || '無');
                $('#dialog-merchant-id').text(merchantId || '無');
                $('#dialog-merchant-order-no').text(merchantOrderNo || '無');
                $('#dialog-trade-no').text(tradeNo || '無');
                $('#refund-amount').val(maxAmount);
                $('#refund-amount').attr('max', maxAmount);
                $('#max-amount-text').text('最大可退款金額: NT$' + parseFloat(maxAmount).toLocaleString());
                $('#refund-reason').val('');
                $('#amount-error').hide();

                refundDialog.show();
            });

            // 處理取消按鈕
            $(document).on('click', '#cancel-refund', function() {
                refundDialog.hide();
            });

            // 處理確認退款按鈕
            $(document).on('click', '#confirm-refund', function() {
                var orderId = $('.utopc-newebpay-refund-btn').data('order-id');
                var amount = parseFloat($('#refund-amount').val());
                var reason = $('#refund-reason').val();
                var maxAmount = parseFloat($('#refund-amount').attr('max'));

                // 清除之前的錯誤訊息
                $('#amount-error').hide();

                // 驗證輸入
                if (!amount || amount <= 0) {
                    $('#amount-error').text('請輸入有效的退款金額').show();
                    return;
                }

                if (amount > maxAmount) {
                    $('#amount-error').text('退款金額不能超過最大可退款金額 NT$' + maxAmount.toLocaleString()).show();
                    return;
                }

                if (!confirm('確定要執行藍新金流退款 NT$' + amount.toLocaleString() + ' 嗎？\n\n此操作無法撤銷！')) {
                    return;
                }

                // 顯示載入狀態
                refundDialog.addClass('loading');
                $('#confirm-refund').prop('disabled', true).text('處理中...');

                // 執行退款
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'utopc_process_refund',
                        order_id: orderId,
                        amount: amount,
                        reason: reason,
                        nonce: '<?php echo wp_create_nonce('utopc_refund_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('退款成功！');
                            location.reload();
                        } else {
                            alert('退款失敗：' + (response.data || '未知錯誤'));
                        }
                    },
                    error: function() {
                        alert('退款請求失敗，請稍後再試');
                    },
                    complete: function() {
                        refundDialog.removeClass('loading');
                        $('#confirm-refund').prop('disabled', false).text('確認退款');
                        refundDialog.hide();
                    }
                });
            });

            // 點擊對話框外部關閉
            $(document).on('click', function(e) {
                if (e.target === refundDialog[0]) {
                    refundDialog.hide();
                }
            });

            // ESC 鍵關閉對話框
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && refundDialog.is(':visible')) {
                    refundDialog.hide();
                }
            });
        });
        </script>
        <?php
    }
    /**
     * 處理 AJAX 退款請求
     */
    public function ajax_process_refund() {
        // 檢查 nonce
        if (!wp_verify_nonce($_POST['nonce'], 'utopc_refund_nonce')) {
            wp_die('安全驗證失敗');
        }
        
        // 檢查權限
        if (!current_user_can('manage_woocommerce')) {
            wp_die('權限不足');
        }
        
        $order_id = intval($_POST['order_id']);
        $amount = floatval($_POST['amount']);
        $reason = sanitize_text_field($_POST['reason']);
        
        $result = $this->refund_manager->manual_refund($order_id, $amount, $reason);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('退款處理完成');
        }
    }
    
    /**
     * 檢查是否為藍新金流訂單
     * 
     * @param WC_Order $order 訂單物件
     * @return bool
     */
    public function is_newebpay_order($order) {
        if (!$order || !is_object($order)) {
            return false;
        }
        
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
     * 檢查訂單是否可以退款
     * 
     * @param WC_Order $order 訂單物件
     * @return array 檢查結果
     */
    private function check_refund_eligibility($order) {
        $result = array(
            'can_refund' => false,
            'reasons' => array(),
            'max_refund_amount' => 0
        );
        
        if (!$this->is_newebpay_order($order)) {
            $result['reasons'][] = __('非藍新金流訂單', 'utrust-order-payment-change');
            return $result;
        }
        
        if (!$order->is_paid()) {
            $result['reasons'][] = __('訂單尚未付款', 'utrust-order-payment-change');
            return $result;
        }
        
        // 檢查交易資訊
        $merchant_order_no = $order->get_meta('_newebpay_MerchantOrderNo', true);
        $trade_no = $order->get_transaction_id(); // RY Tools 使用 WooCommerce 標準的 transaction_id
        
        // 至少需要 MerchantOrderNo
        if (!$merchant_order_no) {
            $result['reasons'][] = __('缺少交易資訊 (MerchantOrderNo)', 'utrust-order-payment-change');
            return $result;
        }
        
        // 如果沒有 TradeNo，使用 MerchantOrderNo 作為替代
        if (!$trade_no) {
            $trade_no = $merchant_order_no;
        }
        
        $remaining_amount = $order->get_total() - $order->get_total_refunded();
        
        if ($remaining_amount <= 0) {
            $result['reasons'][] = __('訂單已完全退款', 'utrust-order-payment-change');
            return $result;
        }
        
        $result['can_refund'] = true;
        $result['max_refund_amount'] = $remaining_amount;
        
        return $result;
    }
}
