/**
 * Network Site Stats - Admin Scripts
 */
jQuery(document).ready(function($) {
    var refreshButton = $('#nss-refresh-stats');
    var loadingIndicator = $('#nss-loading');
    
    // Refresh tất cả dữ liệu
    refreshButton.on('click', function(e) {
        e.preventDefault();
        
        // Hiển thị loading
        refreshButton.prop('disabled', true);
        loadingIndicator.show();
        
        // Thêm class loading cho tất cả các hàng
        $('.nss-sites-table tbody tr').addClass('nss-row-loading');
        
        // Gửi AJAX request
        $.ajax({
            url: nss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'nss_refresh_stats',
                nonce: nss_ajax.nonce,
                site_id: 0 // 0 = refresh all
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Cập nhật dữ liệu cho từng site
                    $.each(response.data, function(siteId, siteData) {
                        var row = $('.nss-sites-table tbody tr[data-site-id="' + siteId + '"]');
                        if (row.length) {
                            row.find('.nss-post-count').text(siteData.post_count.toLocaleString());
                            row.find('td:nth-child(5)').text(siteData.latest_post);
                            row.find('td:nth-child(6)').text(siteData.db_size);
                        }
                    });
                    
                    // Cập nhật tổng số bài viết
                    var totalPosts = 0;
                    $.each(response.data, function(siteId, siteData) {
                        totalPosts += siteData.post_count;
                    });
                    $('.nss-stats-summary .nss-stat-number').first().next().text(totalPosts.toLocaleString());
                    
                    // Thông báo thành công
                    var notice = $('<div class="notice notice-success is-dismissible"><p>Data refreshed successfully!</p></div>');
                    $('.nss-admin-wrap h1').after(notice);
                    setTimeout(function() {
                        notice.fadeOut('slow', function() { $(this).remove(); });
                    }, 3000);
                } else {
                    // Thông báo lỗi
                    var errorMsg = response.data ? response.data : 'An error occurred';
                    var notice = $('<div class="notice notice-error is-dismissible"><p>Error: ' + errorMsg + '</p></div>');
                    $('.nss-admin-wrap h1').after(notice);
                }
            },
            error: function(xhr, status, error) {
                var notice = $('<div class="notice notice-error is-dismissible"><p>AJAX Error: ' + error + '</p></div>');
                $('.nss-admin-wrap h1').after(notice);
            },
            complete: function() {
                // Ẩn loading
                refreshButton.prop('disabled', false);
                loadingIndicator.hide();
                $('.nss-sites-table tbody tr').removeClass('nss-row-loading');
            }
        });
    });
    
    // Refresh một site cụ thể khi nhấn nút refresh riêng (có thể mở rộng)
    $('.nss-refresh-single').on('click', function(e) {
        e.preventDefault();
        var siteId = $(this).data('site-id');
        var row = $(this).closest('tr');
        
        row.addClass('nss-row-loading');
        
        $.ajax({
            url: nss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'nss_refresh_stats',
                nonce: nss_ajax.nonce,
                site_id: siteId
            },
            success: function(response) {
                if (response.success && response.data) {
                    row.find('.nss-post-count').text(response.data.post_count.toLocaleString());
                    row.find('td:nth-child(5)').text(response.data.latest_post);
                    row.find('td:nth-child(6)').text(response.data.db_size);
                }
            },
            complete: function() {
                row.removeClass('nss-row-loading');
            }
        });
    });
});