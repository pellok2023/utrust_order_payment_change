<?php
/**
 * HPOS 輔助工具類別
 * 負責處理高效能訂單儲存相關功能
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTOPC_HPOS_Helper {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 建構函數
    }
    
    /**
     * 檢查是否使用 HPOS
     */
    public function is_hpos_enabled() {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        return false;
    }
    
    /**
     * 取得訂單物件 - 支援 HPOS
     */
    public function get_order($order_id) {
        if ($this->is_hpos_enabled()) {
            // 使用 HPOS 相容的方法
            return wc_get_order($order_id);
        } else {
            // 使用傳統方法
            return wc_get_order($order_id);
        }
    }
    
    /**
     * 取得訂單 ID - 支援 HPOS
     */
    public function get_order_id($order) {
        if ($this->is_hpos_enabled()) {
            return $order->get_id();
        } else {
            return $order->get_id();
        }
    }
    
    /**
     * 取得訂單總金額 - 支援 HPOS
     */
    public function get_order_total($order) {
        if ($this->is_hpos_enabled()) {
            return $order->get_total();
        } else {
            return $order->get_total();
        }
    }
    
    /**
     * 取得訂單狀態 - 支援 HPOS
     */
    public function get_order_status($order) {
        if ($this->is_hpos_enabled()) {
            return $order->get_status();
        } else {
            return $order->get_status();
        }
    }
    
    /**
     * 檢查訂單是否存在 - 支援 HPOS
     */
    public function order_exists($order_id) {
        if ($this->is_hpos_enabled()) {
            $order = wc_get_order($order_id);
            return $order && $order->get_id() > 0;
        } else {
            $order = wc_get_order($order_id);
            return $order && $order->get_id() > 0;
        }
    }
    
    /**
     * 取得訂單元資料 - 支援 HPOS
     */
    public function get_order_meta($order, $key, $single = true) {
        if ($this->is_hpos_enabled()) {
            return $order->get_meta($key, $single);
        } else {
            return $order->get_meta($key, $single);
        }
    }
    
    /**
     * 設定訂單元資料 - 支援 HPOS
     */
    public function set_order_meta($order, $key, $value) {
        if ($this->is_hpos_enabled()) {
            $order->update_meta_data($key, $value);
            $order->save();
        } else {
            $order->update_meta_data($key, $value);
            $order->save();
        }
    }
    
    /**
     * 宣告 HPOS 相容性
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', UTOPC_PLUGIN_BASENAME, true);
        }
    }
    
    /**
     * 取得 HPOS 狀態資訊
     */
    public function get_hpos_status() {
        $status = array(
            'hpos_enabled' => $this->is_hpos_enabled(),
            'hpos_available' => class_exists('\Automattic\WooCommerce\Utilities\OrderUtil'),
            'features_util_available' => class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')
        );
        
        return $status;
    }
}
