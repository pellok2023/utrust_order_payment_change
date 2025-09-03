<?php
/**
 * 外掛解除安裝檔案
 * 當外掛被刪除時執行清理工作
 */

// 防止直接存取
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 檢查是否要保留資料
$keep_data = get_option('utopc_keep_data_on_deactivate', 'yes');

// 檢查是否有確認刪除的標記
$confirmed_deletion = get_option('utopc_confirmed_deletion', false);

if ($keep_data === 'yes' && !$confirmed_deletion) {
    // 保留資料，只清理外掛相關的選項
    delete_option('utopc_keep_data_on_deactivate');
    delete_option('utopc_auto_switch_enabled');
    delete_option('utopc_enable_logging');
    delete_option('utopc_enable_notifications');
    delete_option('utopc_allow_manual_reset');
    delete_option('utopc_confirmed_deletion');
    
    // 清理 WP-Cron 事件
    wp_clear_scheduled_hook('utopc_monthly_reset');
    
    return;
}

// 完全清理所有資料
global $wpdb;

// 記錄開始清理
if (function_exists('error_log')) {
    error_log('UTOPC: 開始完全移除外掛和所有資料...');
}

// 刪除資料表
$table_name = $wpdb->prefix . 'utopc_payment_accounts';
$result = $wpdb->query("DROP TABLE IF EXISTS $table_name");

if ($result !== false) {
    if (function_exists('error_log')) {
        error_log('UTOPC: 資料表 ' . $table_name . ' 已成功刪除');
    }
} else {
    if (function_exists('error_log')) {
        error_log('UTOPC: 刪除資料表 ' . $table_name . ' 時發生錯誤: ' . $wpdb->last_error);
    }
}

// 刪除所有外掛相關的選項
$options_to_delete = array(
    'utopc_keep_data_on_deactivate',
    'utopc_auto_switch_enabled',
    'utopc_enable_logging',
    'utopc_enable_notifications',
    'utopc_allow_manual_reset',
    'utopc_confirmed_deletion',
    'utopc_default_account_created',
    'utopc_default_account_created_time'
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// 刪除日誌和歷史記錄
$data_options_to_delete = array(
    'utopc_logs',
    'utopc_processed_orders',
    'utopc_switch_history',
    'utopc_monthly_backups',
    'utopc_reset_history'
);

foreach ($data_options_to_delete as $option) {
    delete_option($option);
}

// 清理 WP-Cron 事件
wp_clear_scheduled_hook('utopc_monthly_reset');

// 清理快取（如果有的話）
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}

// 記錄完成清理
if (function_exists('error_log')) {
    error_log('UTOPC: UTrust Order Payment Change 外掛已完全移除，所有資料已清理完成');
}
