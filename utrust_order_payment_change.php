<?php
/**
 * Plugin Name: UTrust Order Payment Change
 * Plugin URI: https://utrust.com
 * Description: 自動管理多個金流帳號，根據金額上限自動切換金流服務商
 * Version: 1.2.0
 * Author: UTrust
 * Author URI: https://utrust.com
 * Text Domain: utrust-order-payment-change
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * 
 * 相容性說明：
 * - 支援 WooCommerce 高效能訂單儲存 (HPOS)
 * - 支援 WooCommerce 電子郵件強化項目
 * - 完全相容最新的 WooCommerce 功能
 */

// 防止直接存取
if (!defined('ABSPATH')) {
    exit;
}

// 定義外掛常數
define('UTOPC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UTOPC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('UTOPC_PLUGIN_VERSION', '1.2.0');
define('UTOPC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// 檢查 WooCommerce 是否啟用
function utopc_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>' . 
                 __('UTrust Order Payment Change 外掛需要 WooCommerce 才能運作。', 'utrust-order-payment-change') . 
                 '</p></div>';
        });
        return false;
    }
    return true;
}

// 檢查並宣告 HPOS 支援
function utopc_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

// 在 WooCommerce 初始化前宣告 HPOS 相容性
add_action('before_woocommerce_init', function() {
    utopc_declare_hpos_compatibility();
});

// 主要外掛類別
class UTrust_Order_Payment_Change {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        if (!utopc_check_woocommerce()) {
            return;
        }
        
        // 載入語言檔案
        load_plugin_textdomain('utrust-order-payment-change', false, dirname(UTOPC_PLUGIN_BASENAME) . '/languages');
        
        // 初始化各模組
        $this->init_modules();
        
        // 註冊啟用/停用 hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    private function init_modules() {
        // 載入各模組
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-database.php';
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-admin.php';
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-order-monitor.php';
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-payment-switcher.php';
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-monthly-reset.php';
        require_once UTOPC_PLUGIN_PATH . 'includes/class-utopc-hpos-helper.php';
        
        // 確保資料表存在
        UTOPC_Database::ensure_tables_exist();
        
        // 檢查並創建預設金流帳號
        UTOPC_Database::check_and_create_default_account();
        
        // 初始化 HPOS 輔助工具
        UTOPC_HPOS_Helper::get_instance();
        
        // 初始化資料庫模組
        UTOPC_Database::get_instance();
        
        // 初始化管理頁面模組
        UTOPC_Admin::get_instance();
        
        // 初始化訂單監控模組
        UTOPC_Order_Monitor::get_instance();
        
        // 初始化金流切換模組
        UTOPC_Payment_Switcher::get_instance();
        
        // 初始化月度重置模組
        UTOPC_Monthly_Reset::get_instance();
    }
    
    public function activate() {
        // 建立資料表
        UTOPC_Database::create_tables();
        
        // 檢查並創建預設金流帳號
        UTOPC_Database::check_and_create_default_account();
        
        // 設定預設選項
        $this->set_default_options();
        
        // 清除重寫規則快取
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // 清除重寫規則快取
        flush_rewrite_rules();
        
        // 清除 WP-Cron 事件
        wp_clear_scheduled_hook('utopc_monthly_reset');
    }
    
    private function set_default_options() {
        // 設定預設選項
        if (!get_option('utopc_keep_data_on_deactivate')) {
            update_option('utopc_keep_data_on_deactivate', 'yes');
        }
        
        if (!get_option('utopc_auto_switch_enabled')) {
            update_option('utopc_auto_switch_enabled', 'yes');
        }
    }
}

// 初始化外掛
function utopc_init() {
    return UTrust_Order_Payment_Change::get_instance();
}

// 啟動外掛
add_action('plugins_loaded', 'utopc_init');
