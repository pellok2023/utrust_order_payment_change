<?php
/**
 * 訂單列表欄位模組
 * 負責在 WooCommerce 訂單列表上增加金流公司欄位
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_Order_Columns {
    
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
        $this->init_hooks();
    }
    
    /**
     * 初始化 hooks
     */
    private function init_hooks() {
        // 只在 WooCommerce 啟用時才執行
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        // 添加自定義欄位到訂單列表
        add_filter('manage_shop_order_posts_columns', array($this, 'add_payment_company_column'), 20);
        add_action('manage_shop_order_posts_custom_column', array($this, 'display_payment_company_column'), 20, 2);
        
        // 支援 HPOS (High-Performance Order Storage)
        add_filter('woocommerce_shop_order_list_table_columns', array($this, 'add_payment_company_column_hpos'), 20);
        add_action('woocommerce_shop_order_list_table_custom_column', array($this, 'display_payment_company_column_hpos'), 20, 2);
        
        // 讓欄位可排序
        add_filter('manage_edit-shop_order_sortable_columns', array($this, 'make_payment_company_column_sortable'));
        
        // 處理排序查詢
        add_action('pre_get_posts', array($this, 'handle_payment_company_column_sorting'));
        
        // 載入樣式
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }
    
    /**
     * 添加金流公司欄位到訂單列表 (傳統訂單儲存)
     */
    public function add_payment_company_column($columns) {
        // 在付款方式欄位後插入金流公司欄位
        $new_columns = array();
        $inserted = false;
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // 在付款方式欄位後插入金流公司欄位
            if ($key === 'order_status' && !$inserted) {
                $new_columns['payment_company'] = __('金流公司', 'utrust-order-payment-change');
                $inserted = true;
            }
        }
        
        // 如果沒有找到 order_status 欄位，則在最後添加
        if (!$inserted) {
            $new_columns['payment_company'] = __('金流公司', 'utrust-order-payment-change');
        }
        
        return $new_columns;
    }
    
    /**
     * 顯示金流公司欄位內容 (傳統訂單儲存)
     */
    public function display_payment_company_column($column, $post_id) {
        if ($column === 'payment_company') {
            $this->display_payment_company_content($post_id);
        }
    }
    
    /**
     * 添加金流公司欄位到訂單列表 (HPOS)
     */
    public function add_payment_company_column_hpos($columns) {
        // 在付款方式欄位後插入金流公司欄位
        $new_columns = array();
        $inserted = false;
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // 在付款方式欄位後插入金流公司欄位
            if ($key === 'order_status' && !$inserted) {
                $new_columns['payment_company'] = __('金流公司', 'utrust-order-payment-change');
                $inserted = true;
            }
        }
        
        // 如果沒有找到 order_status 欄位，則在最後添加
        if (!$inserted) {
            $new_columns['payment_company'] = __('金流公司', 'utrust-order-payment-change');
        }
        
        return $new_columns;
    }
    
    /**
     * 顯示金流公司欄位內容 (HPOS)
     */
    public function display_payment_company_column_hpos($order, $column_name) {
        if ($column_name === 'payment_company') {
            $this->display_payment_company_content($order->get_id());
        }
    }
    
    /**
     * 顯示金流公司內容
     */
    private function display_payment_company_content($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            echo '<span class="utopc-no-data">' . __('無資料', 'utrust-order-payment-change') . '</span>';
            return;
        }
        
        // 取得付款方式
        $payment_method = $order->get_payment_method();
        
        // 檢查是否為 NewebPay 相關付款方式
        if (strpos($payment_method, 'ry_newebpay') === 0) {
            // 取得金流公司資訊
            $company_info = $this->get_payment_company_info($order);
            
            if ($company_info) {
                echo '<div class="utopc-payment-company">';
                echo '<span class="company-name">' . esc_html($company_info['company_name']) . '</span>';
                
                if (!empty($company_info['merchant_id'])) {
                    echo '<br><small class="merchant-id">' . __('商戶ID: ', 'utrust-order-payment-change') . esc_html($company_info['merchant_id']) . '</small>';
                }
                
                if (!empty($company_info['account_name'])) {
                    echo '<small class="account-name">' . esc_html($company_info['account_name']) . '</small>';
                }
                
                echo '</div>';
            } else {
                echo '<span class="utopc-no-data">' . __('NewebPay', 'utrust-order-payment-change') . '</span>';
            }
        } else {
            // 非 NewebPay 付款方式，顯示付款方式名稱
            $payment_method_title = $order->get_payment_method_title();
            echo '<span class="utopc-payment-method">' . esc_html($payment_method_title) . '</span>';
        }
    }
    
    /**
     * 取得付款公司資訊
     */
    private function get_payment_company_info($order) {
        // 嘗試從訂單 meta 中取得金流帳戶資訊
        $account_id = $order->get_meta('_utopc_payment_account_id');
        
        if ($account_id) {
            $account = $this->database->get_account($account_id);
            if ($account) {
                return array(
                    'company_name' => $account->company_name ?: '',
                    'merchant_id' => $account->merchant_id ?: '',
                    'account_name' => $account->account_name ?: ''
                );
            }
        }
        
        // 最後的備選方案
        return array(
            'company_name' => '',
            'merchant_id' => '',
            'account_name' => ''
        );
    }
    
    /**
     * 隱碼顯示 MerchantID
     */
    private function mask_merchant_id($merchant_id) {
        if (strlen($merchant_id) <= 4) {
            return str_repeat('*', strlen($merchant_id));
        }
        
        return substr($merchant_id, 0, 2) . str_repeat('*', strlen($merchant_id) - 4) . substr($merchant_id, -2);
    }
    
    /**
     * 讓金流公司欄位可排序
     */
    public function make_payment_company_column_sortable($columns) {
        $columns['payment_company'] = 'payment_company';
        return $columns;
    }
    
    /**
     * 處理金流公司欄位排序
     */
    public function handle_payment_company_column_sorting($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $orderby = $query->get('orderby');
        
        if ($orderby === 'payment_company') {
            $query->set('meta_key', '_utopc_payment_account_id');
            $query->set('orderby', 'meta_value');
        }
    }
    
    /**
     * 添加 CSS 樣式
     */
    public function enqueue_styles($hook) {
        // 只在訂單列表頁面載入樣式
        if ($hook !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order') {
            return;
        }
        
        $css = '
        .utopc-payment-company {
            font-size: 12px;
            line-height: 1.4;
        }
        
        .utopc-payment-company .company-name {
            font-weight: bold;
            color: #0073aa;
        }
        
        .utopc-payment-company .merchant-id,
        .utopc-payment-company .account-name {
            color: #666;
        }
        
        .utopc-no-data,
        .utopc-payment-method {
            color: #999;
            font-style: italic;
        }
        
        .utopc-payment-company small {
            display: block;
            margin-top: 2px;
        }
        ';
        
        wp_add_inline_style('woocommerce_admin_styles', $css);
    }
}
