/**
 * UTrust Order Payment Change 管理頁面 JavaScript
 */

jQuery(document).ready(function($) {
    
    // 全域變數
    var currentAccountId = null;
    var isEditMode = false;
    
    // 初始化對話框
    $('#utopc-account-dialog').dialog({
        autoOpen: false,
        modal: true,
        width: 600,
        close: function() {
            resetForm();
        }
    });
    
    $('#utopc-logs-dialog').dialog({
        autoOpen: false,
        modal: true,
        width: 800,
        height: 600
    });
    
    $('#utopc-delete-plugin-dialog').dialog({
        autoOpen: false,
        modal: true,
        width: 600,
        resizable: false,
        close: function() {
            // 重置確認狀態
            $('#utopc-confirm-delete-checkbox').prop('checked', false);
            $('#utopc-confirm-delete').prop('disabled', true);
        }
    });
    
    $('#utopc-delete-last-account-dialog').dialog({
        autoOpen: false,
        modal: true,
        width: 600,
        resizable: false,
        close: function() {
            // 重置確認狀態
            $('#utopc-confirm-delete-last-checkbox').prop('checked', false);
            $('#utopc-confirm-delete-last').prop('disabled', true);
        }
    });
    
    $('#utopc-update-old-orders-dialog').dialog({
        autoOpen: false,
        modal: true,
        width: 600,
        resizable: false,
        close: function() {
            // 重置更新狀態
            resetUpdateDialog();
        }
    });
    
    // 新增帳號按鈕
    $('#utopc-add-account').on('click', function() {
        isEditMode = false;
        $('#utopc-account-dialog').dialog('open');
        $('#is_active_row').show();
        $('#monthly_amount_row').hide();
    });
    
    // 編輯帳號按鈕
    $(document).on('click', '.edit-account', function() {
        var accountId = $(this).data('id');
        loadAccountData(accountId);
        isEditMode = true;
        $('#utopc-account-dialog').dialog('open');
        $('#is_active_row').hide();
        $('#monthly_amount_row').show();
    });
    
    // 啟用帳號按鈕
    $(document).on('click', '.activate-account', function() {
        var accountId = $(this).data('id');
        if (confirm(utopc_ajax.strings.confirm_activate)) {
            activateAccount(accountId);
        }
    });
    
    // 刪除帳號按鈕
    $(document).on('click', '.delete-account', function() {
        var accountId = $(this).data('id');
        var isActive = $(this).data('is-active');
        var isLast = $(this).data('is-last');
        
        // 如果是最後一筆帳號，顯示特殊確認對話框
        if (isLast == '1') {
            $('#utopc-delete-last-account-dialog').dialog('open');
            $('#utopc-delete-last-account-dialog').data('account-id', accountId);
        } else if (isActive == '1') {
            // 如果是啟用中的帳號但不是最後一筆，顯示警告
            if (confirm('此帳號目前正在使用中，刪除後將切換到其他帳號。確定要刪除嗎？')) {
                deleteAccount(accountId);
            }
        } else {
            // 一般刪除確認
            if (confirm(utopc_ajax.strings.confirm_delete)) {
                deleteAccount(accountId);
            }
        }
    });
    
    // 重置當月金額按鈕
    $('#utopc-reset-monthly').on('click', function() {
        if (confirm(utopc_ajax.strings.confirm_reset)) {
            resetMonthlyAmounts();
        }
    });
    
    // 計算當月使用量按鈕
    $('#utopc-calculate-monthly').on('click', function() {
        if (confirm('確定要計算當月金流使用量嗎？此操作將更新所有帳號的當月累計金額。')) {
            calculateMonthlyUsage();
        }
    });
    
    // 查看日誌按鈕
    $('#utopc-view-logs').on('click', function() {
        $('#utopc-logs-dialog').dialog('open');
        loadLogs();
    });
    
    // 更新舊訂單按鈕
    $('#utopc-update-old-orders').on('click', function() {
        $('#utopc-update-old-orders-dialog').dialog('open');
    });
    
    // 移除外掛按鈕
    $('#utopc-delete-plugin').on('click', function() {
        $('#utopc-delete-plugin-dialog').dialog('open');
    });
    
    // 確認刪除複選框
    $('#utopc-confirm-delete-checkbox').on('change', function() {
        $('#utopc-confirm-delete').prop('disabled', !$(this).is(':checked'));
    });
    
    // 確認刪除按鈕
    $('#utopc-confirm-delete').on('click', function() {
        if ($('#utopc-confirm-delete-checkbox').is(':checked')) {
            confirmPluginDeletion();
        }
    });
    
    // 確認刪除最後一筆帳號複選框
    $('#utopc-confirm-delete-last-checkbox').on('change', function() {
        $('#utopc-confirm-delete-last').prop('disabled', !$(this).is(':checked'));
    });
    
    // 確認刪除最後一筆帳號按鈕
    $('#utopc-confirm-delete-last').on('click', function() {
        if ($('#utopc-confirm-delete-last-checkbox').is(':checked')) {
            var accountId = $('#utopc-delete-last-account-dialog').data('account-id');
            $('#utopc-delete-last-account-dialog').dialog('close');
            deleteAccount(accountId);
        }
    });
    
    // 清除日誌按鈕
    $(document).on('click', '#utopc-clear-logs', function() {
        if (confirm('確定要清除所有日誌嗎？')) {
            clearLogs();
        }
    });
    
    // 重新整理日誌按鈕
    $(document).on('click', '#utopc-refresh-logs', function() {
        loadLogs();
    });
    
    // 開始更新舊訂單按鈕
    $('#utopc-start-update').on('click', function() {
        startUpdateOldOrders();
    });
    
    // 取消更新按鈕
    $('#utopc-cancel-update').on('click', function() {
        cancelUpdateOldOrders();
    });
    
    // 表單提交
    $('#utopc-account-form').on('submit', function(e) {
        e.preventDefault();
        
        if (isEditMode) {
            updateAccount();
        } else {
            addAccount();
        }
    });
    
    /**
     * 載入帳號資料
     */
    function loadAccountData(accountId) {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_get_account',
                id: accountId,
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    var account = response.data;
                    fillAccountForm(account);
                    currentAccountId = accountId;
                } else {
                    showError('載入帳號資料失敗：' + response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('載入帳號資料時發生錯誤');
            }
        });
    }
    
    /**
     * 填寫帳號表單
     */
    function fillAccountForm(account) {
        $('#account_id').val(account.id);
        $('#account_name').val(account.account_name);
        $('#merchant_id').val(account.merchant_id);
        $('#hash_key').val(account.hash_key);
        $('#hash_iv').val(account.hash_iv);
        $('#amount_limit').val(account.amount_limit);
        $('#monthly_amount').val(account.monthly_amount);
        $('#company_name').val(account.company_name || '');
        $('#tax_id').val(account.tax_id || '');
        $('#address').val(account.address || '');
        $('#phone').val(account.phone || '');
        $('#is_active').prop('checked', account.is_active == 1);
    }
    
    /**
     * 新增帳號
     */
    function addAccount() {
        var formData = $('#utopc-account-form').serialize();
        formData += '&action=utopc_add_account&nonce=' + utopc_ajax.nonce;
        
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    $('#utopc-account-dialog').dialog('close');
                    location.reload();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('新增帳號時發生錯誤');
            }
        });
    }
    
    /**
     * 更新帳號
     */
    function updateAccount() {
        var formData = $('#utopc-account-form').serialize();
        formData += '&action=utopc_update_account&nonce=' + utopc_ajax.nonce;
        
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    $('#utopc-account-dialog').dialog('close');
                    location.reload();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('更新帳號時發生錯誤');
            }
        });
    }
    
    /**
     * 啟用帳號
     */
    function activateAccount(accountId) {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_activate_account',
                id: accountId,
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    location.reload();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('啟用帳號時發生錯誤');
            }
        });
    }
    
    /**
     * 刪除帳號
     */
    function deleteAccount(accountId) {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_delete_account',
                id: accountId,
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    location.reload();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('刪除帳號時發生錯誤');
            }
        });
    }
    
    /**
     * 重置當月金額
     */
    function resetMonthlyAmounts() {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_reset_monthly',
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    location.reload();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('重置當月金額時發生錯誤');
            }
        });
    }
    
    /**
     * 計算當月金流使用量
     */
    function calculateMonthlyUsage() {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_calculate_monthly',
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    var data = response.data;
                    var message = data.message;
                    
                    // 顯示日誌資訊
                    if (data.log_info && data.log_info.length > 0) {
                        message += '\n\n' + data.log_info.join('\n');
                    }
                    
                    // 顯示詳細結果
                    if (data.details && data.details.length > 0) {
                        message += '\n\n詳細結果：\n' + data.details.join('\n');
                    }
                    
                    showSuccess(message);
                    
                    // 重新載入頁面以顯示更新後的資料
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('計算當月使用量時發生錯誤');
            }
        });
    }
    
    /**
     * 載入日誌
     */
    function loadLogs() {
        console.log('loadLogs called'); // 除錯用
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_get_logs',
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                console.log('AJAX success response:', response); // 除錯用
                hideLoading();
                
                if (response.success) {
                    displayLogs(response.data);
                } else {
                    showError('載入日誌失敗：' + response.data);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX error:', xhr, status, error); // 除錯用
                hideLoading();
                showError('載入日誌時發生錯誤：' + error);
            }
        });
    }
    
    /**
     * 顯示日誌
     */
    function displayLogs(data) {
        console.log('displayLogs called with data:', data); // 除錯用
        
        if (data && data.html) {
            // 如果有 HTML 內容，直接使用
            $('.utopc-logs-list').html(data.html);
        } else if (data && data.logs && Array.isArray(data.logs)) {
            // 如果有原始日誌資料，自己生成 HTML
            var logsHtml = '';
            
            if (data.logs.length === 0) {
                logsHtml = '<p>沒有日誌記錄</p>';
            } else {
                logsHtml = '<table class="wp-list-table widefat fixed striped">';
                logsHtml += '<thead><tr><th>時間</th><th>等級</th><th>模組</th><th>訊息</th></tr></thead><tbody>';
                
                data.logs.forEach(function(log) {
                    var levelClass = 'utopc-log-level ' + log.level.toLowerCase();
                    logsHtml += '<tr>';
                    logsHtml += '<td>' + log.timestamp + '</td>';
                    logsHtml += '<td><span class="' + levelClass + '">' + log.level + '</span></td>';
                    logsHtml += '<td>' + (log.module || '-') + '</td>';
                    logsHtml += '<td>' + log.message + '</td>';
                    logsHtml += '</tr>';
                });
                
                logsHtml += '</tbody></table>';
            }
            
            $('.utopc-logs-list').html(logsHtml);
        } else {
            $('.utopc-logs-list').html('<p>沒有日誌記錄</p>');
        }
    }
    
    /**
     * 清除日誌
     */
    function clearLogs() {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_clear_logs',
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess('日誌清除成功');
                    loadLogs();
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('清除日誌時發生錯誤');
            }
        });
    }
    
    /**
     * 重置表單
     */
    function resetForm() {
        $('#utopc-account-form')[0].reset();
        $('#account_id').val('');
        $('#monthly_amount').val('');
        $('#company_name').val('');
        $('#tax_id').val('');
        $('#address').val('');
        $('#phone').val('');
        currentAccountId = null;
        isEditMode = false;
    }
    
    /**
     * 顯示載入指示器
     */
    function showLoading() {
        $('#utopc-loading').show();
    }
    
    /**
     * 隱藏載入指示器
     */
    function hideLoading() {
        $('#utopc-loading').hide();
    }
    
    /**
     * 顯示成功訊息
     */
    function showSuccess(message) {
        // 使用 WordPress 內建的通知系統
        var notice = $('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after(notice);
        
        // 自動隱藏通知
        setTimeout(function() {
            notice.fadeOut();
        }, 3000);
    }
    
    /**
     * 顯示錯誤訊息
     */
    function showError(message) {
        // 使用 WordPress 內建的通知系統
        var notice = $('<div class="notice notice-error is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after(notice);
        
        // 自動隱藏通知
        setTimeout(function() {
            notice.fadeOut();
        }, 5000);
    }
    
    /**
     * 開始更新舊訂單
     */
    function startUpdateOldOrders() {
        var batchSize = $('#utopc-batch-size').val();
        var offset = 0;
        
        // 顯示進度條
        $('.utopc-update-settings').hide();
        $('.utopc-update-progress').show();
        $('.utopc-update-results').hide();
        
        // 更新按鈕狀態
        $('#utopc-start-update').hide();
        $('#utopc-cancel-update').show();
        
        // 開始批量更新
        updateOldOrdersBatch(batchSize, offset);
    }
    
    /**
     * 批量更新舊訂單
     */
    function updateOldOrdersBatch(batchSize, offset) {
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_update_old_orders',
                batch_size: batchSize,
                offset: offset,
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    
                    // 更新進度條
                    updateProgress(data.processed, data.updated);
                    
                    // 如果還有更多訂單需要處理，繼續下一批
                    if (data.has_more) {
                        setTimeout(function() {
                            updateOldOrdersBatch(batchSize, data.next_offset);
                        }, 1000); // 延遲1秒避免伺服器負載過重
                    } else {
                        // 更新完成
                        completeUpdate(data);
                    }
                } else {
                    showError('更新失敗：' + response.data);
                    resetUpdateDialog();
                }
            },
            error: function() {
                showError('更新舊訂單時發生錯誤');
                resetUpdateDialog();
            }
        });
    }
    
    /**
     * 更新進度條
     */
    function updateProgress(processed, updated) {
        var progressText = '已處理 ' + processed + ' 筆訂單，更新了 ' + updated + ' 筆';
        $('.utopc-progress-text').text(progressText);
        
        // 簡單的進度條動畫
        var progressFill = $('.utopc-progress-fill');
        var currentWidth = progressFill.width();
        var newWidth = Math.min(currentWidth + 10, 100);
        progressFill.css('width', newWidth + '%');
    }
    
    /**
     * 完成更新
     */
    function completeUpdate(data) {
        $('.utopc-update-progress').hide();
        $('.utopc-update-results').show();
        
        var resultsHtml = '<p>更新完成！</p>';
        resultsHtml += '<p>總共處理了 ' + data.processed + ' 筆訂單，更新了 ' + data.updated + ' 筆</p>';
        
        $('.utopc-results-content').html(resultsHtml);
        
        // 更新按鈕狀態
        $('#utopc-start-update').text('重新開始').show();
        $('#utopc-cancel-update').hide();
        
        showSuccess('舊訂單更新完成！');
    }
    
    /**
     * 取消更新
     */
    function cancelUpdateOldOrders() {
        // 這裡可以添加取消邏輯，但由於是批量處理，我們只能重置對話框
        resetUpdateDialog();
        showError('更新已取消');
    }
    
    /**
     * 重置更新對話框
     */
    function resetUpdateDialog() {
        $('.utopc-update-settings').show();
        $('.utopc-update-progress').hide();
        $('.utopc-update-results').hide();
        
        $('#utopc-start-update').text('開始更新').show();
        $('#utopc-cancel-update').hide();
        
        // 重置進度條
        $('.utopc-progress-fill').css('width', '0%');
        $('.utopc-progress-text').text('準備中...');
    }
    
    /**
     * 確認外掛刪除
     */
    function confirmPluginDeletion() {
        showLoading();
        
        $.ajax({
            url: utopc_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'utopc_confirm_deletion',
                nonce: utopc_ajax.nonce
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    showSuccess(response.data);
                    $('#utopc-delete-plugin-dialog').dialog('close');
                    
                    // 顯示後續指示
                    setTimeout(function() {
                        var notice = $('<div class="notice notice-info is-dismissible"><p><strong>下一步：</strong>請前往 <a href="' + utopc_ajax.ajax_url.replace('admin-ajax.php', 'plugins.php') + '">外掛管理頁面</a> 刪除此外掛檔案。</p></div>');
                        $('.wrap h1').after(notice);
                    }, 2000);
                } else {
                    showError(response.data);
                }
            },
            error: function() {
                hideLoading();
                showError('確認刪除時發生錯誤');
            }
        });
    }
    
});
