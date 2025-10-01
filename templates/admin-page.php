<?php
/**
 * 管理頁面模板
 */

if (!defined('ABSPATH')) {
    exit;
}

// 取得統計資訊
$order_monitor = UTOPC_Order_Monitor::get_instance();
$payment_switcher = UTOPC_Payment_Switcher::get_instance();
$monthly_reset = UTOPC_Monthly_Reset::get_instance();

$order_stats = $order_monitor->get_order_stats();
$switch_history = $payment_switcher->get_switch_history(5);
$reset_stats = $monthly_reset->get_reset_stats();
?>

<div class="wrap utopc-admin">
    <h1><?php _e('金流管理', 'utrust-order-payment-change'); ?></h1>
    
    <!-- 統計資訊 -->
    <div class="utopc-stats-grid">
        <div class="utopc-stat-card">
            <h3><?php _e('目前使用中', 'utrust-order-payment-change'); ?></h3>
            <div class="stat-value"><?php echo esc_html($order_stats['account_name'] ?: __('無', 'utrust-order-payment-change')); ?></div>
        </div>
        
        <div class="utopc-stat-card">
            <h3><?php _e('當月累計金額', 'utrust-order-payment-change'); ?></h3>
            <div class="stat-value">NT$ <?php echo number_format($order_stats['monthly_amount'], 2); ?></div>
        </div>
        
        <div class="utopc-stat-card">
            <h3><?php _e('剩餘額度', 'utrust-order-payment-change'); ?></h3>
            <div class="stat-value">NT$ <?php echo number_format($order_stats['remaining_limit'], 2); ?></div>
        </div>
        
        <div class="utopc-stat-card">
            <h3><?php _e('下次重置', 'utrust-order-payment-change'); ?></h3>
            <div class="stat-value"><?php echo esc_html($reset_stats['next_reset']); ?></div>
        </div>
    </div>
    
    <!-- 當前 NewebPay 設定 -->
    <div class="utopc-newebpay-settings">
        <h2><?php _e('當前 NewebPay 設定', 'utrust-order-payment-change'); ?></h2>
        <div class="utopc-settings-grid">
            <div class="utopc-setting-item">
                <label><?php _e('MerchantID', 'utrust-order-payment-change'); ?>:</label>
                <span class="setting-value"><?php echo esc_html($current_newebpay_settings['merchant_id'] ?: __('未設定', 'utrust-order-payment-change')); ?></span>
            </div>
            <div class="utopc-setting-item">
                <label><?php _e('HashKey', 'utrust-order-payment-change'); ?>:</label>
                <span class="setting-value"><?php echo esc_html($current_newebpay_settings['hash_key'] ?: __('未設定', 'utrust-order-payment-change')); ?></span>
            </div>
            <div class="utopc-setting-item">
                <label><?php _e('HashIV', 'utrust-order-payment-change'); ?>:</label>
                <span class="setting-value"><?php echo esc_html($current_newebpay_settings['hash_iv'] ?: __('未設定', 'utrust-order-payment-change')); ?></span>
            </div>
        </div>
    </div>
    
    <!-- 付款驗證設定 -->
    <div class="utopc-payment-settings">
        <h2><?php _e('付款驗證設定', 'utrust-order-payment-change'); ?></h2>
        <div class="utopc-settings-grid">
            <div class="utopc-setting-item">
                <label>
                    <input type="checkbox" id="utopc-auto-switch-enabled" <?php checked(get_option('utopc_auto_switch_enabled', 'yes'), 'yes'); ?>>
                    <?php _e('啟用自動切換金流', 'utrust-order-payment-change'); ?>
                </label>
                <p class="description"><?php _e('當付款金額會超過當前金流上限時，自動切換到可用的金流帳號', 'utrust-order-payment-change'); ?></p>
            </div>
            
            <div class="utopc-setting-item">
                <label>
                    <input type="checkbox" id="utopc-enable-notifications" <?php checked(get_option('utopc_enable_notifications', 'yes'), 'yes'); ?>>
                    <?php _e('啟用通知功能', 'utrust-order-payment-change'); ?>
                </label>
                <p class="description"><?php _e('當金流帳號自動切換時，發送郵件通知管理員', 'utrust-order-payment-change'); ?></p>
            </div>
            
            <div class="utopc-setting-item">
                <label>
                    <input type="checkbox" id="utopc-enable-logging" <?php checked(get_option('utopc_enable_logging', 'yes'), 'yes'); ?>>
                    <?php _e('啟用日誌記錄', 'utrust-order-payment-change'); ?>
                </label>
                <p class="description"><?php _e('記錄系統操作和錯誤資訊，便於問題排查', 'utrust-order-payment-change'); ?></p>
            </div>
        </div>
        
        <div class="utopc-settings-actions">
            <button type="button" class="button button-primary" id="utopc-save-settings">
                <?php _e('儲存設定', 'utrust-order-payment-change'); ?>
            </button>
        </div>
    </div>
    
    <!-- 操作按鈕 -->
    <div class="utopc-actions">
        <button type="button" class="button button-primary" id="utopc-add-account">
            <?php _e('新增金流帳號', 'utrust-order-payment-change'); ?>
        </button>
        
        <button type="button" class="button button-secondary" id="utopc-reset-monthly">
            <?php _e('重置當月金額', 'utrust-order-payment-change'); ?>
        </button>
        
        <button type="button" class="button button-primary" id="utopc-calculate-monthly">
            <?php _e('計算當月使用量', 'utrust-order-payment-change'); ?>
        </button>
        
        <button type="button" class="button button-secondary" id="utopc-view-logs">
            <?php _e('查看日誌', 'utrust-order-payment-change'); ?>
        </button>
        
        <button type="button" class="button button-primary" id="utopc-update-old-orders">
            <?php _e('更新舊訂單', 'utrust-order-payment-change'); ?>
        </button>
        
        <button type="button" class="button button-secondary" id="utopc-delete-plugin" style="color: #d63638; border-color: #d63638;">
            <?php _e('移除外掛', 'utrust-order-payment-change'); ?>
        </button>
    </div>
    
    <!-- 金流帳號清單 -->
    <div class="utopc-accounts-section">
        <h2><?php _e('金流帳號清單', 'utrust-order-payment-change'); ?></h2>
        
        <?php if (empty($accounts)): ?>
            <div class="utopc-no-accounts">
                <p><?php _e('目前沒有金流帳號，請新增第一個帳號。', 'utrust-order-payment-change'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('帳號名稱', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('公司名稱', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('MerchantID', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('金額上限', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('當月累計', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('狀態', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('預設金流', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('建立時間', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('操作', 'utrust-order-payment-change'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?php echo esc_html($account->account_name); ?></td>
                            <td><?php echo esc_html($account->company_name ?: '-'); ?></td>
                            <td><?php echo esc_html($account->merchant_id); ?></td>
                            <td>NT$ <?php echo number_format($account->amount_limit, 2); ?></td>
                            <td>NT$ <?php echo number_format($account->monthly_amount, 2); ?></td>
                            <td><?php echo $this->get_status_label($account->is_active); ?></td>
                            <td><?php echo $this->get_default_status_label($account->is_default); ?></td>
                            <td><?php echo esc_html($account->created_at); ?></td>
                            <td>
                                <button type="button" class="button button-small edit-account" data-id="<?php echo $account->id; ?>">
                                    <?php _e('編輯', 'utrust-order-payment-change'); ?>
                                </button>
                                
                                <?php if (!$account->is_active): ?>
                                    <button type="button" class="button button-small activate-account" data-id="<?php echo $account->id; ?>">
                                        <?php _e('啟用', 'utrust-order-payment-change'); ?>
                                    </button>
                                <?php endif; ?>
                                
                                
                                <button type="button" class="button button-small delete-account" data-id="<?php echo $account->id; ?>" data-is-active="<?php echo $account->is_active; ?>" data-is-last="<?php echo count($accounts) === 1 ? '1' : '0'; ?>">
                                    <?php _e('刪除', 'utrust-order-payment-change'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- 最近切換記錄 -->
    <?php if (!empty($switch_history)): ?>
        <div class="utopc-history-section">
            <h2><?php _e('最近切換記錄', 'utrust-order-payment-change'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('切換時間', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('帳號名稱', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('切換原因', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('預設金流', 'utrust-order-payment-change'); ?></th>
                        <th><?php _e('當時金額', 'utrust-order-payment-change'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($switch_history as $record): ?>
                        <tr>
                            <td><?php echo esc_html($record['timestamp']); ?></td>
                            <td><?php echo esc_html($record['account_name']); ?></td>
                            <td><?php echo esc_html($record['reason']); ?></td>
                            <td><?php echo isset($record['is_default']) && $record['is_default'] ? __('是', 'utrust-order-payment-change') : __('否', 'utrust-order-payment-change'); ?></td>
                            <td>NT$ <?php echo number_format($record['monthly_amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- 新增/編輯帳號對話框 -->
<div id="utopc-account-dialog" title="<?php _e('金流帳號', 'utrust-order-payment-change'); ?>" style="display: none;">
    <form id="utopc-account-form">
        <input type="hidden" id="account_id" name="id" value="">
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="account_name"><?php _e('帳號名稱', 'utrust-order-payment-change'); ?> *</label>
                </th>
                <td>
                    <input type="text" id="account_name" name="account_name" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="merchant_id"><?php _e('MerchantID', 'utrust-order-payment-change'); ?> *</label>
                </th>
                <td>
                    <input type="text" id="merchant_id" name="merchant_id" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="hash_key"><?php _e('HashKey', 'utrust-order-payment-change'); ?> *</label>
                </th>
                <td>
                    <input type="text" id="hash_key" name="hash_key" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="hash_iv"><?php _e('HashIV', 'utrust-order-payment-change'); ?> *</label>
                </th>
                <td>
                    <input type="text" id="hash_iv" name="hash_iv" class="regular-text" required>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="amount_limit"><?php _e('金額上限', 'utrust-order-payment-change'); ?> *</label>
                </th>
                <td>
                    <input type="number" id="amount_limit" name="amount_limit" class="regular-text" step="0.01" min="0" required>
                    <p class="description"><?php _e('設定此帳號的月度金額上限', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr id="monthly_amount_row" style="display: none;">
                <th scope="row">
                    <label for="monthly_amount"><?php _e('當月累計', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="number" id="monthly_amount" name="monthly_amount" class="regular-text" step="0.01" min="0">
                    <p class="description"><?php _e('此帳號的當月累計金額（可手動調整）', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="company_name"><?php _e('公司名稱', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="text" id="company_name" name="company_name" class="regular-text">
                    <p class="description"><?php _e('金流帳號所屬公司名稱', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tax_id"><?php _e('統一編號', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="text" id="tax_id" name="tax_id" class="regular-text" maxlength="8">
                    <p class="description"><?php _e('公司統一編號（8位數字）', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="address"><?php _e('地址', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <textarea id="address" name="address" class="large-text" rows="3"></textarea>
                    <p class="description"><?php _e('公司地址', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="phone"><?php _e('電話', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="text" id="phone" name="phone" class="regular-text">
                    <p class="description"><?php _e('公司聯絡電話', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr id="is_active_row" style="display: none;">
                <th scope="row">
                    <label for="is_active"><?php _e('設為啟用', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="is_active" name="is_active" value="1">
                    <p class="description"><?php _e('勾選後將啟用此帳號並停用其他帳號', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="is_default"><?php _e('設為預設金流', 'utrust-order-payment-change'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="is_default" name="is_default" value="1">
                    <p class="description"><?php _e('當所有金流都達到上限時，系統將使用此預設金流', 'utrust-order-payment-change'); ?></p>
                </td>
            </tr>
        </table>
        
        <div class="utopc-dialog-buttons">
            <button type="submit" class="button button-primary"><?php _e('儲存', 'utrust-order-payment-change'); ?></button>
            <button type="button" class="button" onclick="jQuery('#utopc-account-dialog').dialog('close');"><?php _e('取消', 'utrust-order-payment-change'); ?></button>
        </div>
    </form>
</div>

<!-- 日誌對話框 -->
<div id="utopc-logs-dialog" title="<?php _e('系統日誌', 'utrust-order-payment-change'); ?>" style="display: none;">
    <div class="utopc-logs-content">
        <div class="utopc-logs-controls">
            <button type="button" class="button" id="utopc-clear-logs"><?php _e('清除日誌', 'utrust-order-payment-change'); ?></button>
            <button type="button" class="button" id="utopc-refresh-logs"><?php _e('重新整理', 'utrust-order-payment-change'); ?></button>
        </div>
        
        <p class="description"><?php _e('記錄系統操作和錯誤資訊，便於問題排查', 'utrust-order-payment-change'); ?></p>
        
        <div class="utopc-logs-list">
            <!-- 日誌內容將透過 AJAX 載入 -->
        </div>
    </div>
</div>

<!-- 刪除最後一筆帳號確認對話框 -->
<div id="utopc-delete-last-account-dialog" title="<?php _e('確認刪除最後一筆帳號', 'utrust-order-payment-change'); ?>" style="display: none;">
    <div class="utopc-delete-warning">
        <p><strong><?php _e('⚠️ 警告：您即將刪除最後一筆金流帳號！', 'utrust-order-payment-change'); ?></strong></p>
        
        <p><?php _e('刪除此帳號後：', 'utrust-order-payment-change'); ?></p>
        <ul>
            <li><?php _e('將沒有任何可用的金流帳號', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('系統將無法處理新的訂單付款', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('需要重新新增金流帳號才能恢復功能', 'utrust-order-payment-change'); ?></li>
        </ul>
        
        <p><strong><?php _e('確定要繼續刪除此帳號嗎？', 'utrust-order-payment-change'); ?></strong></p>
        
        <div class="utopc-delete-confirmation">
            <label>
                <input type="checkbox" id="utopc-confirm-delete-last-checkbox">
                <?php _e('我了解刪除最後一筆帳號的後果，並確認要刪除', 'utrust-order-payment-change'); ?>
            </label>
        </div>
    </div>
    
    <div class="utopc-dialog-buttons">
        <button type="button" class="button button-primary" id="utopc-confirm-delete-last" disabled>
            <?php _e('確認刪除', 'utrust-order-payment-change'); ?>
        </button>
        <button type="button" class="button" onclick="jQuery('#utopc-delete-last-account-dialog').dialog('close');">
            <?php _e('取消', 'utrust-order-payment-change'); ?>
        </button>
    </div>
</div>

<!-- 刪除外掛確認對話框 -->
<div id="utopc-delete-plugin-dialog" title="<?php _e('確認移除外掛', 'utrust-order-payment-change'); ?>" style="display: none;">
    <div class="utopc-delete-warning">
        <p><strong><?php _e('⚠️ 警告：此操作將完全移除外掛和所有相關資料！', 'utrust-order-payment-change'); ?></strong></p>
        
        <p><?php _e('移除外掛將會刪除以下資料：', 'utrust-order-payment-change'); ?></p>
        <ul>
            <li><?php _e('所有金流帳號資料', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('訂單處理記錄', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('金流切換歷史', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('系統日誌', 'utrust-order-payment-change'); ?></li>
            <li><?php _e('所有外掛設定', 'utrust-order-payment-change'); ?></li>
        </ul>
        
        <p><strong><?php _e('此操作無法復原！', 'utrust-order-payment-change'); ?></strong></p>
        
        <div class="utopc-delete-confirmation">
            <label>
                <input type="checkbox" id="utopc-confirm-delete-checkbox">
                <?php _e('我了解此操作將永久刪除所有資料，並確認要移除外掛', 'utrust-order-payment-change'); ?>
            </label>
        </div>
    </div>
    
    <div class="utopc-dialog-buttons">
        <button type="button" class="button button-primary" id="utopc-confirm-delete" disabled>
            <?php _e('確認移除', 'utrust-order-payment-change'); ?>
        </button>
        <button type="button" class="button" onclick="jQuery('#utopc-delete-plugin-dialog').dialog('close');">
            <?php _e('取消', 'utrust-order-payment-change'); ?>
        </button>
    </div>
</div>

<!-- 更新舊訂單對話框 -->
<div id="utopc-update-old-orders-dialog" title="<?php _e('更新舊訂單金流資訊', 'utrust-order-payment-change'); ?>" style="display: none;">
    <div class="utopc-update-info">
        <p><?php _e('此功能將為舊的 NewebPay 訂單添加金流公司資訊，讓訂單列表能正確顯示金流公司欄位。', 'utrust-order-payment-change'); ?></p>
        
        <div class="utopc-update-settings">
            <label for="utopc-batch-size">
                <?php _e('每批處理數量：', 'utrust-order-payment-change'); ?>
                <select id="utopc-batch-size">
                    <option value="25">25</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                </select>
            </label>
        </div>
        
        <div class="utopc-update-progress" style="display: none;">
            <div class="utopc-progress-bar">
                <div class="utopc-progress-fill"></div>
            </div>
            <p class="utopc-progress-text"><?php _e('準備中...', 'utrust-order-payment-change'); ?></p>
        </div>
        
        <div class="utopc-update-results" style="display: none;">
            <h4><?php _e('更新結果：', 'utrust-order-payment-change'); ?></h4>
            <div class="utopc-results-content"></div>
        </div>
    </div>
    
    <div class="utopc-dialog-buttons">
        <button type="button" class="button button-primary" id="utopc-start-update">
            <?php _e('開始更新', 'utrust-order-payment-change'); ?>
        </button>
        <button type="button" class="button" id="utopc-cancel-update" style="display: none;">
            <?php _e('取消', 'utrust-order-payment-change'); ?>
        </button>
        <button type="button" class="button" onclick="jQuery('#utopc-update-old-orders-dialog').dialog('close');">
            <?php _e('關閉', 'utrust-order-payment-change'); ?>
        </button>
    </div>
</div>

<!-- 載入指示器 -->
<div id="utopc-loading" style="display: none;">
    <div class="utopc-loading-spinner"></div>
    <p><?php _e('處理中...', 'utrust-order-payment-change'); ?></p>
</div>
