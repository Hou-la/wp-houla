/**
 * WP-Houla Admin JavaScript
 *
 * Handles:
 * - Tab navigation on the settings page
 * - Disconnect AJAX
 * - Batch sync AJAX
 * - Save settings AJAX
 * - Product metabox: sync, unsync, load stats
 * - Post metabox: generate/regenerate shortlink, copy, QR, load click stats
 * - Dashboard widget: overview stats + sparkline
 *
 * @since 1.0.0
 * @package Wp_Houla
 */

(function ($) {
    'use strict';

    // Bail early if our localized data is missing
    if (typeof wphoulaAdmin === 'undefined') {
        return;
    }

    var ajaxUrl = wphoulaAdmin.ajaxUrl;
    var nonce = wphoulaAdmin.nonce;
    var i18n = wphoulaAdmin.i18n;

    // =================================================================
    // Tab navigation
    // =================================================================

    $(document).on('click', '.wphoula-tabs .nav-tab', function (e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        // Update active tab
        $('.wphoula-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Show corresponding content
        $('.wphoula-tab-content').hide();
        $('#tab-' + tab).show();

        // Persist active tab in localStorage
        try { localStorage.setItem('wphoula_active_tab', tab); } catch (ex) {}

        // Load order sync counts when orders tab is activated
        if (tab === 'orders' && !orderSyncLoaded) {
            loadOrderSyncCounts();
        }
    });

    // Restore persisted tab on page load
    $(document).ready(function () {
        try {
            var savedTab = localStorage.getItem('wphoula_active_tab');
            if (savedTab) {
                var $tab = $('.wphoula-tabs .nav-tab[data-tab="' + savedTab + '"]');
                if ($tab.length) {
                    $tab.trigger('click');
                }
            }
        } catch (ex) {}
    });

    // =================================================================
    // Select All / Deselect All categories
    // =================================================================

    $(document).on('change', '#wphoula-select-all-categories', function () {
        var checked = $(this).is(':checked');
        $('.wphoula-sync-category').prop('checked', checked);
    });

    // Keep "Select All" in sync when individual checkboxes change
    $(document).on('change', '.wphoula-sync-category', function () {
        var total = $('.wphoula-sync-category').length;
        var checkedCount = $('.wphoula-sync-category:checked').length;
        $('#wphoula-select-all-categories').prop('checked', checkedCount === total);
    });

    // =================================================================
    // Disconnect
    // =================================================================

    $(document).on('click', '#wphoula-disconnect', function () {
        if (!confirm(i18n.confirm)) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(i18n.disconnecting);

        $.post(ajaxUrl, {
            action: 'wphoula_disconnect',
            nonce: nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data || 'Error');
                $btn.prop('disabled', false).text(i18n.disconnect);
            }
        }).fail(function () {
            alert('Network error');
            $btn.prop('disabled', false).text(i18n.disconnect);
        });
    });

    // =================================================================
    // Workspace switcher
    // =================================================================

    // Map of workspace id -> hasShop (populated by loadWorkspaces)
    var workspaceShopMap = {};

    /**
     * Show or hide shop-related tabs (Sync Produits, Commandes) based on hasShop.
     */
    function updateShopTabs(hasShop) {
        var $syncTab = $('.wphoula-tabs .nav-tab[data-tab="sync"]');
        var $ordersTab = $('.wphoula-tabs .nav-tab[data-tab="orders"]');
        var $syncContent = $('#tab-sync');
        var $ordersContent = $('#tab-orders');

        if (hasShop) {
            $syncTab.show();
            $ordersTab.show();
        } else {
            $syncTab.hide();
            $ordersTab.hide();
            $syncContent.hide();
            $ordersContent.hide();
            // If the active tab was sync/orders, switch back to connection
            if ($syncTab.hasClass('nav-tab-active') || $ordersTab.hasClass('nav-tab-active')) {
                $syncTab.removeClass('nav-tab-active');
                $ordersTab.removeClass('nav-tab-active');
                $('.wphoula-tabs .nav-tab[data-tab="connection"]').addClass('nav-tab-active').trigger('click');
            }
        }
    }

    /**
     * Fetch workspaces from the API and populate the select.
     */
    function loadWorkspaces() {
        var $select = $('#wphoula-workspace-select');
        if (!$select.length) return;

        var $loading = $('#wphoula-workspace-loading');
        var $status = $('#wphoula-switch-status');
        $loading.show();
        $status.hide();
        $select.prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'wphoula_get_workspaces',
            nonce: nonce
        }, function (response) {
            $loading.hide();

            if (!response.success) {
                var errMsg = (response.data && typeof response.data === 'string') ? response.data : 'Erreur inconnue';
                $status.show().html('<span style="color:#d63638;">✗ ' + errMsg + '</span>');
                return;
            }

            if (!response.data || !response.data.workspaces) {
                $status.show().html('<span style="color:#d63638;">✗ Réponse invalide</span>');
                return;
            }

            var workspaces = response.data.workspaces;
            if (workspaces.length <= 1) {
                // Only one workspace — no need for a select
                return;
            }

            // Populate select options and build hasShop map
            $select.empty();
            workspaceShopMap = {};
            for (var k = 0; k < workspaces.length; k++) {
                var ws = workspaces[k];
                workspaceShopMap[ws.id] = !!ws.hasShop;
                var parts = [ws.name];
                if (ws.type === 'personal') parts.push('(Personnel)');
                if (ws.plan !== 'free') parts.push('[' + ws.plan.toUpperCase() + ']');
                if (ws.hasShop) parts.push('\u2014 \uD83D\uDED2 Boutique');

                $select.append(
                    $('<option>')
                        .val(ws.id)
                        .text(parts.join(' '))
                        .prop('selected', ws.isCurrent)
                );
            }
            $select.prop('disabled', false);

            // Dynamically show/hide shop tabs based on the current workspace
            var currentId = $select.val();
            if (currentId && typeof workspaceShopMap[currentId] !== 'undefined') {
                updateShopTabs(workspaceShopMap[currentId]);
            }
        }).fail(function (xhr) {
            $loading.hide();
            $status.show().html('<span style="color:#d63638;">✗ Erreur réseau (HTTP ' + xhr.status + ')</span>');
        });
    }

    // Load workspaces when DOM is ready
    $(document).ready(function () {
        loadWorkspaces();
    });

    // Refresh button
    $(document).on('click', '#wphoula-workspace-refresh', function (e) {
        e.preventDefault();
        loadWorkspaces();
    });

    // Switch workspace on select change
    $(document).on('change', '#wphoula-workspace-select', function () {
        var selectedId = $(this).val();
        var $select = $(this);
        var $status = $('#wphoula-switch-status');
        var $loading = $('#wphoula-workspace-loading');

        $select.prop('disabled', true);
        $loading.show();
        $status.show().html(
            '<span class="spinner is-active" style="float:none; margin:0 4px 0 0;"></span>' +
            (i18n.switchingWorkspace || 'Changement en cours…')
        );

        $.post(ajaxUrl, {
            action: 'wphoula_switch_workspace',
            nonce: nonce,
            workspace_id: selectedId,
            has_shop: workspaceShopMap[selectedId] ? '1' : '0'
        }, function (response) {
            $loading.hide();
            if (response.success) {
                $status.html('<span style="color:#00a32a;">✓ ' + (response.data.message || 'OK') + '</span>');
                setTimeout(function () { location.reload(); }, 1000);
            } else {
                $status.html('<span style="color:#d63638;">✗ ' + (response.data || 'Erreur') + '</span>');
                $select.prop('disabled', false);
            }
        }).fail(function () {
            $loading.hide();
            $status.html('<span style="color:#d63638;">✗ Erreur réseau</span>');
            $select.prop('disabled', false);
        });
    });

    // =================================================================
    // Save settings
    // =================================================================

    $(document).on('click', '#wphoula-save-settings, .wphoula-save-settings-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        var postTypes = [];
        $('.wphoula-post-type:checked').each(function () {
            postTypes.push($(this).val());
        });

        var syncCategories = [];
        $('.wphoula-sync-category:checked').each(function () {
            syncCategories.push($(this).val());
        });

        // Category -> Collection mapping
        var catCollectionMap = {};
        $('.wphoula-collection-select').each(function () {
            var catId = $(this).data('cat-id');
            var collectionId = $(this).val();
            if (catId && collectionId) {
                catCollectionMap[catId] = collectionId;
            }
        });

        var data = {
            action: 'wphoula_save_settings',
            nonce: nonce,
            auto_sync: $('#wphoula-auto-sync').is(':checked') ? 1 : 0,
            sync_on_publish: $('#wphoula-sync-on-publish').is(':checked') ? 1 : 0,
            debug: $('#wphoula-debug').is(':checked') ? 1 : 0,
            api_url: ($('#wphoula-api-url').val() || '').trim(),
            price_adjustment_type: $('#wphoula-price-adj-type').val() || 'none',
            price_adjustment_value: $('#wphoula-price-adj-value').val() || 0,
            sync_tracking: $('#wphoula-sync-tracking').is(':checked') ? 1 : 0,
            'allowed_post_types[]': postTypes,
            'sync_categories[]': syncCategories,
        };

        // Add category_collection_map as nested keys
        for (var catId in catCollectionMap) {
            data['category_collection_map[' + catId + ']'] = catCollectionMap[catId];
        }

        // Add order status concordance map (wc_slug => houla_status)
        $('.wphoula-status-map').each(function () {
            var wcSlug = $(this).data('wc-status');
            var houlaStatus = $(this).val();
            if (wcSlug && houlaStatus) {
                data['order_status_map[' + wcSlug + ']'] = houlaStatus;
            }
        });

        // Add product identifier meta mapping (ean/isbn => meta_key)
        $('.wphoula-id-meta-select').each(function () {
            var idKey = $(this).data('id-key');
            var metaKey = $(this).val();
            data['identifier_meta_map[' + idKey + ']'] = metaKey || '';
        });

        $.post(ajaxUrl, data, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                // Brief visual feedback
                $btn.text('OK!');
                setTimeout(function () {
                    $btn.text($btn.data('original') || (i18n.saveSettings || 'Save Settings'));
                }, 1500);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
        });
    });

    // =================================================================
    // Reset sync data
    // =================================================================

    $(document).on('click', '#wphoula-reset-sync', function () {
        if (!confirm(i18n.confirmResetSync || 'This will clear all sync metadata. You will need to re-sync all products. Continue?')) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text(i18n.loading || 'Loading...');

        $.post(ajaxUrl, {
            action: 'wphoula_reset_sync',
            nonce: nonce
        }, function (resp) {
            if (resp.success) {
                alert(resp.data.message);
                location.reload();
            } else {
                alert(i18n.error || 'Error');
                $btn.prop('disabled', false).text(i18n.resetSync || 'Reset Sync Data');
            }
        }).fail(function () {
            alert(i18n.networkError || 'Network error');
            $btn.prop('disabled', false).text(i18n.resetSync || 'Reset Sync Data');
        });
    });

    // Batch sync (paginated with progress bar)
    // =================================================================

    // -----------------------------------------------------------------
    // Background sync with polling
    // -----------------------------------------------------------------

    var syncPollingTimer = null;

    $(document).on('click', '#wphoula-batch-sync', function () {
        if (shopActive === false) {
            alert(i18n.shopNotActive || 'Your Hou.la shop is not activated. Please connect Stripe on Hou.la first.');
            return;
        }
        var $btn = $(this);
        var $cancelBtn = $('#wphoula-cancel-sync');
        var $spinner = $('#wphoula-sync-status');
        var $progress = $('#wphoula-sync-progress');
        var $fill = $('#wphoula-progress-fill');
        var $text = $('#wphoula-progress-text');

        $btn.prop('disabled', true);
        $spinner.show().text(i18n.syncing || 'Lancement de la synchronisation...');

        // Start background sync
        $.post(ajaxUrl, {
            action: 'wphoula_start_background_sync',
            nonce: nonce
        }, function (resp) {
            if (!resp.success) {
                $btn.prop('disabled', false);
                $spinner.text(i18n.syncError || 'Erreur lors du lancement');
                return;
            }

            var state = resp.data;
            if (state.state === 'done' && state.total === 0) {
                $btn.prop('disabled', false);
                $spinner.text('Aucun produit à synchroniser');
                setTimeout(function () { $spinner.hide(); }, 3000);
                return;
            }

            // Show progress bar + cancel button
            $spinner.hide();
            $progress.show();
            $cancelBtn.show();
            $fill.css('width', '0%').css('background', '').removeClass('wphoula-progress-fill--done');
            $text.html('<strong>Synchronisation en cours...</strong><br>0 / ' + state.total + ' produits traités');

            // Start polling for status
            startSyncPolling();

        }).fail(function () {
            $btn.prop('disabled', false);
            $spinner.text(i18n.syncError || 'Error');
        });
    });

    // Cancel sync
    $(document).on('click', '#wphoula-cancel-sync', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'wphoula_cancel_sync',
            nonce: nonce
        }, function () {
            $btn.prop('disabled', false);
        });
    });

    function startSyncPolling() {
        if (syncPollingTimer) clearInterval(syncPollingTimer);
        syncPollingTimer = setInterval(pollSyncStatus, 2000);
    }

    function stopSyncPolling() {
        if (syncPollingTimer) {
            clearInterval(syncPollingTimer);
            syncPollingTimer = null;
        }
    }

    function pollSyncStatus() {
        $.post(ajaxUrl, {
            action: 'wphoula_get_sync_status',
            nonce: nonce
        }, function (resp) {
            if (!resp.success || !resp.data) return;

            var s = resp.data;
            var $btn = $('#wphoula-batch-sync');
            var $cancelBtn = $('#wphoula-cancel-sync');
            var $progress = $('#wphoula-sync-progress');
            var $fill = $('#wphoula-progress-fill');
            var $text = $('#wphoula-progress-text');

            if (s.state === 'idle') {
                stopSyncPolling();
                return;
            }

            var processed = (s.synced || 0) + (s.errors || 0);
            var total = s.total || 1;
            var pct = Math.min(100, Math.round(processed / total * 100));

            if (s.state === 'running') {
                $progress.show();
                $cancelBtn.show();
                $fill.css('width', pct + '%').css('background', '');
                $text.html(
                    '<strong>Synchronisation en cours... ' + pct + '%</strong><br>' +
                    processed + ' / ' + total + ' produits traités' +
                    (s.errors > 0 ? ' <span style="color:#d63638;">(' + s.errors + ' erreur' + (s.errors > 1 ? 's' : '') + ')</span>' : '') +
                    '<br><small style="color:#888;">Vous pouvez quitter cette page, la synchronisation continue en arrière-plan.</small>'
                );
            } else if (s.state === 'done') {
                stopSyncPolling();
                $fill.css('width', '100%').addClass('wphoula-progress-fill--done').css('background', '');
                var summary = '<strong style="color:#00a32a;">\u2713 Synchronisation terminée</strong><br>' +
                    s.synced + ' produit' + (s.synced > 1 ? 's' : '') + ' synchronisé' + (s.synced > 1 ? 's' : '');
                if (s.errors > 0) {
                    summary += ', <span style="color:#d63638;">' + s.errors + ' erreur' + (s.errors > 1 ? 's' : '') + '</span>';
                }
                $text.html(summary);
                $btn.prop('disabled', false);
                $cancelBtn.hide();

                // Update counters
                $('#wphoula-products-synced-count').text(s.synced);
                $('#wphoula-last-full-sync').text(new Date().toLocaleString());

                // Refresh synced products table
                loadSyncedProducts();
            } else if (s.state === 'error') {
                stopSyncPolling();
                $fill.css('width', '100%').css('background', '#d63638');
                $text.html(
                    '<strong style="color:#d63638;">\u26A0 Erreur de synchronisation</strong><br>' +
                    (s.error_message ? escapeHtml(s.error_message) : 'Une erreur est survenue.') +
                    '<br>' + s.synced + ' produit(s) synchronisé(s) avant l\'erreur.'
                );
                $btn.prop('disabled', false);
                $cancelBtn.hide();
            } else if (s.state === 'cancelled') {
                stopSyncPolling();
                $fill.css('width', pct + '%').css('background', '#dba617');
                $text.html(
                    '<strong style="color:#dba617;">\u26A0 Synchronisation annulée</strong><br>' +
                    s.synced + ' produit(s) synchronisé(s) sur ' + total + '.'
                );
                $btn.prop('disabled', false);
                $cancelBtn.hide();
            }
        });
    }

    // On page load: check if a background sync is already running
    function checkExistingSyncOnLoad() {
        $.post(ajaxUrl, {
            action: 'wphoula_get_sync_status',
            nonce: nonce
        }, function (resp) {
            if (resp.success && resp.data && resp.data.state === 'running') {
                var $btn = $('#wphoula-batch-sync');
                var $progress = $('#wphoula-sync-progress');
                var $cancelBtn = $('#wphoula-cancel-sync');
                $btn.prop('disabled', true);
                $progress.show();
                $cancelBtn.show();
                startSyncPolling();
            }
        });
    }

    // =================================================================
    // Product metabox: sync / unsync / stats
    // =================================================================

    function getMetaboxData() {
        var $box = $('.wphoula-metabox');
        return {
            productId: $box.data('product-id'),
            nonce: $box.data('nonce')
        };
    }

    // Sync now (from not-synced state)
    $(document).on('click', '#wphoula-sync-now, #wphoula-resync', function () {
        var $btn = $(this);
        var $spinner = $('#wphoula-spinner');
        var meta = getMetaboxData();

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(ajaxUrl, {
            action: 'wphoula_sync_product',
            nonce: meta.nonce,
            product_id: meta.productId
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false);
                alert(response.data || i18n.syncError);
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
        });
    });

    // Unsync
    $(document).on('click', '#wphoula-unsync', function () {
        if (!confirm(i18n.confirm)) {
            return;
        }

        var meta = getMetaboxData();
        var $spinner = $('#wphoula-spinner');
        $spinner.addClass('is-active');

        $.post(ajaxUrl, {
            action: 'wphoula_unsync_product',
            nonce: meta.nonce,
            product_id: meta.productId
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                location.reload();
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
        });
    });

    // Load stats on page load (for synced products)
    $(document).ready(function () {
        var $stats = $('#wphoula-stats');
        if ($stats.length === 0) {
            return;
        }

        var meta = getMetaboxData();

        $.post(ajaxUrl, {
            action: 'wphoula_get_stats',
            nonce: meta.nonce,
            product_id: meta.productId
        }, function (response) {
            if (response.success && response.data) {
                var d = response.data;
                $('#wphoula-stat-views').text(d.views || 0);
                $('#wphoula-stat-clicks').text(d.clicks || 0);
                $('#wphoula-stat-sales').text(d.sales || 0);
                $('#wphoula-stat-revenue').text(
                    d.revenue ? d.currency + ' ' + parseFloat(d.revenue).toFixed(2) : '-'
                );
                $stats.show();
            }
        });
    });

    // =================================================================
    // Post metabox: shortlink + QR (all post types)
    // =================================================================

    function getPostMetaboxData() {
        var $box = $('.wphoula-post-metabox');
        return {
            postId: $box.data('post-id'),
            nonce: $box.data('nonce')
        };
    }

    // Copy shortlink to clipboard
    $(document).on('click', '#wphoula-copy-link', function () {
        var $input = $('#wphoula-shortlink-input');
        if ($input.length) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText($input.val());
            } else {
                $input[0].select();
                document.execCommand('copy');
            }
            var $btn = $(this);
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function () {
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 1500);
        }
    });

    // Generate shortlink
    $(document).on('click', '#wphoula-generate-link', function () {
        var $btn = $(this);
        var $spinner = $('#wphoula-post-spinner');
        var meta = getPostMetaboxData();

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(ajaxUrl, {
            action: 'wphoula_generate_shortlink',
            nonce: meta.nonce,
            post_id: meta.postId,
            force: 0
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false);
                alert(response.data || i18n.syncError);
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
        });
    });

    // Regenerate shortlink
    $(document).on('click', '#wphoula-regenerate-link', function () {
        if (!confirm(i18n.confirm)) {
            return;
        }
        var $btn = $(this);
        var $spinner = $('#wphoula-post-spinner');
        var meta = getPostMetaboxData();

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        $.post(ajaxUrl, {
            action: 'wphoula_generate_shortlink',
            nonce: meta.nonce,
            post_id: meta.postId,
            force: 1
        }, function (response) {
            $spinner.removeClass('is-active');
            if (response.success) {
                location.reload();
            } else {
                $btn.prop('disabled', false);
            }
        }).fail(function () {
            $spinner.removeClass('is-active');
            $btn.prop('disabled', false);
        });
    });

    // Load post link stats on page load
    $(document).ready(function () {
        var $postStats = $('#wphoula-post-stats');
        if ($postStats.length === 0) {
            return;
        }

        var meta = getPostMetaboxData();

        $.post(ajaxUrl, {
            action: 'wphoula_get_link_stats',
            nonce: meta.nonce,
            post_id: meta.postId
        }, function (response) {
            if (response.success && response.data) {
                var d = response.data;
                $('#wphoula-post-stat-clicks').text(d.totalClicks || d.clicks || 0);
                $('#wphoula-post-stat-today').text(d.clicksToday || 0);
                $('#wphoula-post-stat-qr').text(d.qrScans || 0);
                $postStats.show();

                // Draw 7-day sparkline if data available
                if (d.byDay && d.byDay.length > 0) {
                    drawSparkline(d.byDay);
                }
            }
        });
    });

    // =================================================================
    // Sparkline chart (pure canvas, no dependencies)
    // =================================================================

    function drawSparkline(byDay) {
        var canvas = document.getElementById('wphoula-sparkline');
        if (!canvas || !canvas.getContext) return;

        var $wrapper = $('#wphoula-chart-wrapper');
        $wrapper.show();

        var ctx = canvas.getContext('2d');
        var w = canvas.width;
        var h = canvas.height;
        var padding = 4;

        // Extract click values, sorted by date
        byDay.sort(function (a, b) {
            return a.date < b.date ? -1 : a.date > b.date ? 1 : 0;
        });

        var values = byDay.map(function (d) { return d.clicks || 0; });
        var labels = byDay.map(function (d) {
            var parts = d.date.split('-');
            return parts[2] + '/' + parts[1];
        });
        var maxVal = Math.max.apply(null, values) || 1;
        var n = values.length;

        if (n < 2) return;

        var stepX = (w - padding * 2) / (n - 1);
        var scaleY = (h - padding * 2) / maxVal;

        // Background
        ctx.clearRect(0, 0, w, h);

        // Gradient fill under the line
        var gradient = ctx.createLinearGradient(0, 0, 0, h);
        gradient.addColorStop(0, 'rgba(102, 126, 234, 0.3)');
        gradient.addColorStop(1, 'rgba(102, 126, 234, 0.02)');

        ctx.beginPath();
        ctx.moveTo(padding, h - padding - values[0] * scaleY);
        for (var i = 1; i < n; i++) {
            ctx.lineTo(padding + i * stepX, h - padding - values[i] * scaleY);
        }
        ctx.lineTo(padding + (n - 1) * stepX, h - padding);
        ctx.lineTo(padding, h - padding);
        ctx.closePath();
        ctx.fillStyle = gradient;
        ctx.fill();

        // Line
        ctx.beginPath();
        ctx.moveTo(padding, h - padding - values[0] * scaleY);
        for (var j = 1; j < n; j++) {
            ctx.lineTo(padding + j * stepX, h - padding - values[j] * scaleY);
        }
        ctx.strokeStyle = '#667eea';
        ctx.lineWidth = 2;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // Dots at each data point
        for (var k = 0; k < n; k++) {
            ctx.beginPath();
            ctx.arc(padding + k * stepX, h - padding - values[k] * scaleY, 2.5, 0, Math.PI * 2);
            ctx.fillStyle = '#667eea';
            ctx.fill();
        }

        // Day labels at bottom
        ctx.fillStyle = '#999';
        ctx.font = '9px -apple-system, BlinkMacSystemFont, sans-serif';
        ctx.textAlign = 'center';
        for (var m = 0; m < n; m++) {
            // Only show first, middle, and last label to avoid overlap
            if (m === 0 || m === n - 1 || m === Math.floor(n / 2)) {
                ctx.fillText(labels[m], padding + m * stepX, h - 1);
            }
        }
    }

    // =================================================================
    // Dashboard widget
    // =================================================================

    $(document).ready(function () {
        var $widget = $('.wphoula-dashboard-widget');
        if ($widget.length === 0) return;

        var widgetNonce = $widget.data('nonce');

        $.post(ajaxUrl, {
            action: 'wphoula_dashboard_stats',
            nonce: widgetNonce
        }, function (response) {
            $('#wphoula-dw-loading').hide();

            if (response.success && response.data) {
                var d = response.data;
                $('#wphoula-dw-total-links').text(d.totalLinks || 0);
                $('#wphoula-dw-total-clicks').text(d.totalClicks || 0);
                $('#wphoula-dw-clicks-today').text(d.clicksToday || 0);
                $('#wphoula-dw-content').show();

                // Render top links table
                if (d.topLinks && d.topLinks.length > 0) {
                    var $tbody = $('#wphoula-dw-table tbody');
                    $tbody.empty();
                    for (var i = 0; i < d.topLinks.length; i++) {
                        var link = d.topLinks[i];
                        var title = link.title || link.shortUrl;
                        if (title.length > 35) title = title.substring(0, 35) + '...';
                        $tbody.append(
                            '<tr>' +
                            '<td><a href="' + link.shortUrl + '" target="_blank" title="' + link.shortUrl + '">' + title + '</a></td>' +
                            '<td class="wphoula-stat-value">' + (link.clicks || 0) + '</td>' +
                            '</tr>'
                        );
                    }
                    $('#wphoula-dw-top').show();
                }
            } else {
                $('#wphoula-dw-loading').text(response.data || 'Error loading stats');
                $('#wphoula-dw-loading').show();
            }
        }).fail(function () {
            $('#wphoula-dw-loading').text('Network error');
        });
    });

    // =================================================================
    // Price adjustment UI
    // =================================================================

    $(document).on('change', '#wphoula-price-adj-type', function () {
        var type = $(this).val();
        var $row = $('#wphoula-price-adj-row');
        var $unit = $('#wphoula-price-adj-unit');

        if (type === 'none') {
            $row.hide();
        } else {
            $row.show();
            if (type === 'percent_up' || type === 'percent_down') {
                $unit.text('%');
            } else {
                // Currency symbol from WC (fallback EUR)
                $unit.text(wphoulaAdmin.currencySymbol || 'EUR');
            }
            updatePriceExample();
        }
    });

    $(document).on('input', '#wphoula-price-adj-value', function () {
        updatePriceExample();
    });

    function updatePriceExample() {
        var type = $('#wphoula-price-adj-type').val();
        var value = parseFloat($('#wphoula-price-adj-value').val()) || 0;
        var $example = $('#wphoula-price-example');
        var currency = wphoulaAdmin.currencySymbol || 'EUR';

        if (type === 'none' || value <= 0) {
            $example.text('');
            return;
        }

        var samplePrice = 100;
        var result;

        switch (type) {
            case 'percent_up':
                result = samplePrice * (1 + value / 100);
                $example.text('Example: ' + samplePrice + ' ' + currency + ' -> ' + result.toFixed(2) + ' ' + currency + ' (+' + value + '%)');
                break;
            case 'percent_down':
                result = samplePrice * (1 - value / 100);
                $example.text('Example: ' + samplePrice + ' ' + currency + ' -> ' + result.toFixed(2) + ' ' + currency + ' (-' + value + '%)');
                break;
            case 'fixed_up':
                result = samplePrice + value;
                $example.text('Example: ' + samplePrice + ' ' + currency + ' -> ' + result.toFixed(2) + ' ' + currency + ' (+' + value + ' ' + currency + ')');
                break;
            case 'fixed_down':
                result = Math.max(0, samplePrice - value);
                $example.text('Example: ' + samplePrice + ' ' + currency + ' -> ' + result.toFixed(2) + ' ' + currency + ' (-' + value + ' ' + currency + ')');
                break;
        }
    }

    // =================================================================
    // Sync tab initialisation: load collections + synced products
    // =================================================================

    var collectionsLoaded = false;
    var shopActive = null; // null = unknown, true = active, false = not active

    $(document).on('click', '.wphoula-tabs .nav-tab[data-tab="sync"]', function () {
        if (!collectionsLoaded) {
            collectionsLoaded = true;
            checkShopStatus();
            loadCollections();
            loadSyncedProducts();
            loadIdentifierMetaKeys();
            checkExistingSyncOnLoad();
        }
    });

    // If sync tab is already active on page load
    $(document).ready(function () {
        if ($('.wphoula-tabs .nav-tab[data-tab="sync"]').hasClass('nav-tab-active')) {
            collectionsLoaded = true;
            checkShopStatus();
            loadCollections();
            loadSyncedProducts();
            loadIdentifierMetaKeys();
            checkExistingSyncOnLoad();
        }

        // Trigger price example on load if value is set
        if ($('#wphoula-price-adj-type').val() !== 'none') {
            updatePriceExample();
        }
    });

    // -----------------------------------------------------------------
    // Check shop activation status
    // -----------------------------------------------------------------

    function checkShopStatus() {
        $.post(ajaxUrl, {
            action: 'wphoula_get_shop_status',
            nonce: nonce
        }, function (response) {
            if (response.success && response.data) {
                shopActive = !!response.data.shopActive;
                if (!shopActive) {
                    $('#wphoula-shop-status-banner').show();
                    // Disable sync buttons
                    $('#wphoula-batch-sync').prop('disabled', true).attr('title', i18n.shopNotActive || 'Shop not activated on Hou.la');
                    $('#wphoula-auto-map').prop('disabled', true).attr('title', i18n.shopNotActive || 'Shop not activated on Hou.la');
                } else {
                    $('#wphoula-shop-status-banner').hide();
                }
            }
        });
    }

    // -----------------------------------------------------------------
    // Load product identifier meta keys (EAN / ISBN)
    // -----------------------------------------------------------------

    function loadIdentifierMetaKeys() {
        $('.wphoula-id-meta-loading').show();

        $.post(ajaxUrl, {
            action: 'wphoula_get_product_meta_keys',
            nonce: nonce
        }, function (response) {
            $('.wphoula-id-meta-loading').hide();

            if (!response.success || !response.data) return;

            var data = response.data;

            $('.wphoula-id-meta-select').each(function () {
                var $select = $(this);
                var idKey = $select.data('id-key'); // 'ean' or 'isbn'
                var savedValue = $('#wphoula-id-meta-' + idKey + '-saved').val();

                // Keep the first default option
                $select.find('option:gt(0)').remove();

                // Add detected keys first (from installed plugins)
                if (data.detected && data.detected.length > 0) {
                    var $group = $('<optgroup></optgroup>').attr('label', i18n.detectedPlugins || 'Detected plugins');
                    for (var i = 0; i < data.detected.length; i++) {
                        var d = data.detected[i];
                        var $opt = $('<option></option>').val(d.key).text(d.label);
                        if (d.key === savedValue) $opt.prop('selected', true);
                        $group.append($opt);
                    }
                    $select.append($group);
                }

                // Add custom (non-underscore) meta keys
                if (data.custom && data.custom.length > 0) {
                    var $group2 = $('<optgroup></optgroup>').attr('label', i18n.customMetaKeys || 'Custom meta fields');
                    for (var j = 0; j < data.custom.length; j++) {
                        var c = data.custom[j];
                        var $opt2 = $('<option></option>').val(c.key).text(c.label);
                        if (c.key === savedValue) $opt2.prop('selected', true);
                        $group2.append($opt2);
                    }
                    $select.append($group2);
                }

                // Add all known plugin keys (even if not installed)
                if (data.known && data.known.length > 0) {
                    var $group3 = $('<optgroup></optgroup>').attr('label', i18n.knownPlugins || 'Known plugins (not detected)');
                    for (var k = 0; k < data.known.length; k++) {
                        var kn = data.known[k];
                        var $opt3 = $('<option></option>').val(kn.key).text(kn.label);
                        if (kn.key === savedValue) $opt3.prop('selected', true);
                        $group3.append($opt3);
                    }
                    $select.append($group3);
                }

                // If saved value not in any list, add it manually
                if (savedValue && $select.val() !== savedValue) {
                    var $manual = $('<option></option>').val(savedValue).text(savedValue).prop('selected', true);
                    $select.prepend($manual);
                }
            });
        }).fail(function () {
            $('.wphoula-id-meta-loading').hide();
        });
    }

    // -----------------------------------------------------------------
    // Load collections and populate dropdowns
    // -----------------------------------------------------------------

    function loadCollections() {
        $.post(ajaxUrl, {
            action: 'wphoula_get_collections',
            nonce: nonce
        }, function (response) {
            if (!response.success || !response.data) return;

            var collections = response.data;

            $('.wphoula-collection-select').each(function () {
                var $select = $(this);
                var $hidden = $select.closest('td').find('.wphoula-collection-value');
                var savedValue = $hidden.val();

                // Keep the first "- Not mapped -" option
                $select.find('option:gt(0)').remove();

                for (var i = 0; i < collections.length; i++) {
                    var c = collections[i];
                    var $opt = $('<option></option>')
                        .val(c.id)
                        .text(c.title);
                    if (c.id === savedValue) {
                        $opt.prop('selected', true);
                    }
                    $select.append($opt);
                }
            });
        });
    }

    // -----------------------------------------------------------------
    // Load synced products (paginated)
    // -----------------------------------------------------------------

    var syncedProductsPage = 1;
    var syncedProductsPerPage = 20;
    var syncedProductsSearch = '';
    var syncedProductsSearchTimer = null;

    function loadSyncedProducts() {
        var $loading = $('#wphoula-synced-products-loading');
        var $table = $('#wphoula-synced-products');
        var $empty = $('#wphoula-synced-products-empty');
        var $pagination = $('#wphoula-products-pagination');

        $loading.show();
        $table.hide();
        $empty.hide();
        $pagination.hide();

        $.post(ajaxUrl, {
            action: 'wphoula_get_synced_products',
            nonce: nonce,
            page: syncedProductsPage,
            per_page: syncedProductsPerPage,
            search: syncedProductsSearch
        }, function (response) {
            $loading.hide();

            if (!response.success) {
                var errMsg = (response.data && typeof response.data === 'string') ? response.data : 'Failed to load synced products';
                $empty.html('<p style="color:#d63638;"><span class="dashicons dashicons-warning" style="margin-right:4px;"></span>' + escapeHtml(errMsg) + '</p>').show();
                $('#wphoula-products-total').text('');
                return;
            }

            if (!response.data || !response.data.products || response.data.products.length === 0) {
                $empty.show();
                $('#wphoula-products-total').text('');
                return;
            }

            var data = response.data;
            var products = data.products;
            var total = data.total;
            var page = data.page;
            var pages = data.pages;
            var offset = (page - 1) * data.per_page;

            var $tbody = $table.find('tbody');
            $tbody.empty();

            for (var i = 0; i < products.length; i++) {
                var p = products[i];
                var statusClass = 'wphoula-status-draft';
                var statusLabel = p.status || 'draft';
                if (p.status === 'active') {
                    statusClass = 'wphoula-status-active';
                    statusLabel = 'Active';
                } else if (p.status === 'draft') {
                    statusLabel = 'Draft';
                } else if (p.status === 'out_of_stock') {
                    statusLabel = 'Rupture';
                    statusClass = 'wphoula-status-oos';
                }
                var stockLabel = (p.stock !== undefined && p.stock !== null) ? p.stock : '-';
                var wcPrice = p.wc_price ? p.wc_price : '-';
                var houlaPrice = p.price ? p.price : '-';
                var synced = p.last_synced ? p.last_synced : '-';
                var name = p.name || p.title || 'Unknown';

                $tbody.append(
                    '<tr>' +
                    '<td>' + (offset + i + 1) + '</td>' +
                    '<td>' + escapeHtml(name) + '</td>' +
                    '<td>' + escapeHtml(String(wcPrice)) + '</td>' +
                    '<td>' + escapeHtml(String(houlaPrice)) + '</td>' +
                    '<td><span class="' + statusClass + '">' + escapeHtml(statusLabel) + '</span></td>' +
                    '<td>' + escapeHtml(String(stockLabel)) + '</td>' +
                    '<td>' + escapeHtml(synced) + '</td>' +
                    '</tr>'
                );
            }

            $table.show();

            // Update total
            $('#wphoula-products-total').text(total + ' produit' + (total > 1 ? 's' : ''));

            // Update pagination
            if (pages > 1) {
                $pagination.show();
                $('#wphoula-products-prev').prop('disabled', page <= 1);
                $('#wphoula-products-next').prop('disabled', page >= pages);
                $('#wphoula-products-page-info').text('Page ' + page + ' / ' + pages);
            } else {
                $pagination.hide();
            }
        }).fail(function (jqXHR) {
            $loading.hide();
            var errMsg = 'Network error — unable to load synced products';
            if (jqXHR.status) {
                errMsg += ' (HTTP ' + jqXHR.status + ')';
            }
            $empty.html('<p style="color:#d63638;"><span class="dashicons dashicons-warning" style="margin-right:4px;"></span>' + escapeHtml(errMsg) + '</p>').show();
        });
    }

    // Pagination controls
    $(document).on('click', '#wphoula-products-prev', function () {
        if (syncedProductsPage > 1) {
            syncedProductsPage--;
            loadSyncedProducts();
        }
    });

    $(document).on('click', '#wphoula-products-next', function () {
        syncedProductsPage++;
        loadSyncedProducts();
    });

    // Per-page selector
    $(document).on('change', '#wphoula-products-per-page', function () {
        syncedProductsPerPage = parseInt($(this).val(), 10) || 20;
        syncedProductsPage = 1;
        loadSyncedProducts();
    });

    // Search with debounce
    $(document).on('input', '#wphoula-products-search', function () {
        var val = $(this).val();
        clearTimeout(syncedProductsSearchTimer);
        syncedProductsSearchTimer = setTimeout(function () {
            syncedProductsSearch = val;
            syncedProductsPage = 1;
            loadSyncedProducts();
        }, 400);
    });

    // Utility: escape HTML
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // -----------------------------------------------------------------
    // Auto-map collections button
    // -----------------------------------------------------------------

    $(document).on('click', '#wphoula-auto-map', function () {
        var $btn = $(this);
        var $status = $('#wphoula-automap-status');

        $btn.prop('disabled', true);
        $status.show().text(i18n.creatingCollections || 'Creating collections...').css('color', '#666');

        $.post(ajaxUrl, {
            action: 'wphoula_auto_map_collections',
            nonce: nonce
        }, function (response) {
            $btn.prop('disabled', false);

            if (response.success && response.data) {
                var d = response.data;
                $status.text((d.created || 0) + (i18n.collectionsCreated || ' collections created, ') + (d.total || 0) + (i18n.collectionsMapped || ' mapped.')).css('color', '#46b450');

                // Update all dropdowns with the new mapping
                if (d.mapping) {
                    // Reload collections to show newly created ones
                    loadCollections();

                    // After slight delay (to let loadCollections finish), set dropdown values
                    setTimeout(function () {
                        for (var catId in d.mapping) {
                            var collectionId = d.mapping[catId];
                            var $select = $('.wphoula-collection-select[data-cat-id="' + catId + '"]');
                            if ($select.length) {
                                $select.val(collectionId);
                                $select.closest('td').find('.wphoula-collection-value').val(collectionId);
                            }
                        }
                    }, 1500);
                }

                setTimeout(function () { $status.fadeOut(); }, 5000);
            } else {
                $status.text(response.data || 'Error').css('color', '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('Network error').css('color', '#dc3232');
        });
    });

    // =================================================================
    // Order resync
    // =================================================================

    var orderSyncLoaded = false;

    // Load order sync counts when on Orders tab
    function loadOrderSyncCounts() {
        orderSyncLoaded = true;
        var $container = $('#wphoula-order-sync-counts');
        if (!$container.length) return;

        $.post(ajaxUrl, {
            action: 'wphoula_order_sync_counts',
            nonce: nonce
        }, function (resp) {
            if (!resp.success) {
                $container.html('<span style="color:#dc3232;">' + (resp.data || 'Error loading counts') + '</span>');
                return;
            }
            var d = resp.data;
            var html = '<table class="widefat" style="max-width:400px;">';
            html += '<tr><td>' + (i18n.totalOrders || 'Total Hou.la orders') + '</td><td><strong>' + d.total + '</strong></td></tr>';
            html += '<tr><td>' + (i18n.syncedOrders || 'Synced') + '</td><td><span style="color:#46b450;">&#10003; ' + d.synced + '</span></td></tr>';
            html += '<tr><td>' + (i18n.failedOrders || 'Failed') + '</td><td><span style="color:' + (d.failed > 0 ? '#dc3232' : '#999') + ';">' + (d.failed > 0 ? '&#10007; ' : '') + d.failed + '</span></td></tr>';
            html += '<tr><td>' + (i18n.pendingOrders || 'Pending') + '</td><td><span style="color:#f0b849;">' + d.pending + '</span></td></tr>';
            html += '</table>';
            $container.html(html);

            // Enable buttons
            $('#wphoula-resync-all-orders').prop('disabled', d.total === 0);
            $('#wphoula-resync-failed-orders').prop('disabled', d.failed === 0);
        }).fail(function (xhr) {
            console.error('wp-houla: order sync counts failed', xhr.status, xhr.responseText);
            $container.html('<span style="color:#dc3232;">' + (i18n.networkError || 'Network error') + ' (HTTP ' + xhr.status + ')</span>');
        });
    }

    // Load when orders tab is activated (integrated into main tab handler)
    $(document).ready(function () {
        if ($('.wphoula-tabs .nav-tab[data-tab="orders"]').hasClass('nav-tab-active')) {
            loadOrderSyncCounts();
        }
    });

    // Resync all orders
    $(document).on('click', '#wphoula-resync-all-orders', function () {
        resyncOrders($(this), 'all');
    });

    // Resync failed orders
    $(document).on('click', '#wphoula-resync-failed-orders', function () {
        resyncOrders($(this), 'failed');
    });

    // Pull orders from Hou.la → WooCommerce
    $(document).on('click', '#wphoula-pull-orders-from-houla', function () {
        console.log('[wp-houla] Pull orders button clicked');
        var $btn = $(this);
        var $status = $('#wphoula-order-resync-status');
        var originalText = $btn.text();
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>' + (i18n.pulling || 'Pulling orders from Hou.la...'));
        $status.show().text(i18n.pulling || 'Pulling orders from Hou.la...').css('color', '#666');

        $.post(ajaxUrl, {
            action: 'wphoula_pull_orders_from_houla',
            nonce: nonce
        }, function (resp) {
            console.log('[wp-houla] Pull orders response:', resp);
            $btn.prop('disabled', false).text(originalText);
            if (resp.success) {
                var d = resp.data;
                var msg = (i18n.xSynced || '%d synced').replace('%d', d.succeeded);
                if (d.failed > 0) msg += ', ' + (i18n.xFailed || '%d failed').replace('%d', d.failed);
                msg += ' ' + (i18n.xTotal || '(%d total)').replace('%d', d.total);
                $status.html('<span style="color:' + (d.failed > 0 ? '#f0b849' : '#46b450') + ';">&#10003; ' + msg + '</span>');
                loadOrderSyncCounts(); // Refresh counts
            } else {
                $status.html('<span style="color:#dc3232;">&#10007; ' + (resp.data || (i18n.error || 'Error')) + '</span>');
            }
        }).fail(function (xhr) {
            console.error('[wp-houla] Pull orders AJAX failed:', xhr.status, xhr.responseText);
            $btn.prop('disabled', false).text(originalText);
            $status.html('<span style="color:#dc3232;">&#10007; ' + (i18n.networkError || 'Network error') + ' (HTTP ' + xhr.status + ')</span>');
        });
    });

    function resyncOrders($btn, filter) {
        var $status = $('#wphoula-order-resync-status');
        var originalText = $btn.text();
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;vertical-align:middle;"></span>' + (i18n.syncing || 'Syncing...'));
        $status.show().text(i18n.syncing || 'Syncing...').css('color', '#666');

        $.post(ajaxUrl, {
            action: 'wphoula_batch_resync_orders',
            nonce: nonce,
            filter: filter
        }, function (resp) {
            $btn.prop('disabled', false).text(originalText);
            if (resp.success) {
                var d = resp.data;
                var msg = (i18n.xSynced || '%d synced').replace('%d', d.synced);
                if (d.failed > 0) msg += ', ' + (i18n.xFailed || '%d failed').replace('%d', d.failed);
                if (d.skipped > 0) msg += ', ' + (i18n.xSkipped || '%d skipped').replace('%d', d.skipped);
                $status.html('<span style="color:' + (d.failed > 0 ? '#f0b849' : '#46b450') + ';">&#10003; ' + msg + '</span>');
                loadOrderSyncCounts(); // Refresh counts
            } else {
                $status.html('<span style="color:#dc3232;">&#10007; ' + (resp.data || (i18n.error || 'Error')) + '</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text(originalText);
            $status.html('<span style="color:#dc3232;">&#10007; ' + (i18n.networkError || 'Network error') + '</span>');
        });
    }

    // Individual order resync (in WC orders list)
    $(document).on('click', '.wphoula-resync-order', function (e) {
        e.preventDefault();
        var $link = $(this);
        var orderId = $link.data('order-id');
        $link.text('...').css('pointer-events', 'none');

        $.post(ajaxUrl, {
            action: 'wphoula_resync_order',
            nonce: nonce,
            order_id: orderId
        }, function (resp) {
            if (resp.success) {
                $link.closest('td').find('span').first().replaceWith('<span style="color:#46b450;" title="Synced">&#10003;</span>');
                $link.remove();
            } else {
                $link.text('\u21BB').css({ 'pointer-events': 'auto', 'color': '#dc3232' });
            }
        }).fail(function () {
            $link.text('\u21BB').css({ 'pointer-events': 'auto', 'color': '#dc3232' });
        });
    });

    // =================================================================
    // Multi-workspace mapping
    // =================================================================

    // Cache of loaded workspaces for the mapping dropdowns
    var wsMapWorkspaces = null;

    /**
     * Load workspaces into all workspace mapping dropdowns.
     */
    function loadWorkspacesForMapping() {
        if (wsMapWorkspaces !== null) {
            populateWsMapSelects(wsMapWorkspaces);
            return;
        }

        var $table = $('#wphoula-workspace-map-table');
        $table.css('opacity', '0.5');

        $.post(ajaxUrl, {
            action: 'wphoula_get_workspaces',
            nonce: nonce
        }, function (response) {
            $table.css('opacity', '1');

            if (!response.success) {
                var errMsg = (response.data && typeof response.data === 'string') ? response.data : (i18n.errorUnknown || 'Unknown error');
                $table.before('<div class="notice notice-error inline"><p><strong>Workspace loading failed:</strong> ' + errMsg + '</p></div>');
                return;
            }

            if (!response.data || !response.data.workspaces) {
                $table.before('<div class="notice notice-error inline"><p><strong>Workspace loading failed:</strong> Invalid response</p></div>');
                return;
            }

            wsMapWorkspaces = response.data.workspaces;
            populateWsMapSelects(wsMapWorkspaces);
        }).fail(function (xhr) {
            $table.css('opacity', '1');
            $table.before('<div class="notice notice-error inline"><p><strong>Workspace loading failed:</strong> Network error (HTTP ' + xhr.status + ')</p></div>');
        });
    }

    function populateWsMapSelects(workspaces) {
        $('.wphoula-ws-map-select').each(function () {
            var $select = $(this);
            var currentVal = $select.val();

            // Remove all options except the first (default)
            $select.find('option:not(:first)').remove();

            // Add workspace options
            for (var i = 0; i < workspaces.length; i++) {
                var ws = workspaces[i];
                var label = ws.name || ws.id;
                if (ws.type) label += ' (' + ws.type + ')';
                $select.append(
                    $('<option></option>').val(ws.id).text(label)
                );
            }

            // Restore selection
            if (currentVal) {
                $select.val(currentVal);
            }
        });
    }

    // Load workspaces when sync tab is shown (or on page load if already on sync tab)
    $(document).ready(function () {
        if ($('#wphoula-workspace-map-table').length) {
            loadWorkspacesForMapping();
        }
    });

    // When a workspace is selected in the mapping dropdown
    $(document).on('change', '.wphoula-ws-map-select', function () {
        var $select = $(this);
        var catId = $select.data('cat-id');
        var wsId = $select.val();
        var $row = $select.closest('tr');

        if (wsId) {
            $row.addClass('wphoula-ws-mapped');

            // Show price adjustment controls
            var $adjTd = $row.find('td').eq(4);
            if (!$adjTd.find('.wphoula-ws-adj-type').length) {
                $adjTd.html(
                    '<select class="wphoula-ws-adj-type" data-cat-id="' + catId + '" style="width:100%;">' +
                    '<option value="none">' + (i18n.noAdjustment || 'None') + '</option>' +
                    '<option value="percent_up">+%</option>' +
                    '<option value="percent_down">-%</option>' +
                    '<option value="fixed_up">+€</option>' +
                    '<option value="fixed_down">-€</option>' +
                    '</select>' +
                    '<input type="number" class="wphoula-ws-adj-value small-text" data-cat-id="' + catId + '" value="0" min="0" step="0.01" style="width:60px; margin-top:4px; display:none;">'
                );
            }

            // Show remove button
            var $actionTd = $row.find('td').eq(5);
            if (!$actionTd.find('.wphoula-ws-map-remove').length) {
                $actionTd.html(
                    '<button type="button" class="button button-small button-link-delete wphoula-ws-map-remove" data-cat-id="' + catId + '">' +
                    (i18n.removeMapping || 'Remove') +
                    '</button>'
                );
            }

            // Show key status (will be set after save)
            var $keyTd = $row.find('td').eq(3);
            $keyTd.html('<span class="description">—</span>');
        } else {
            // Reset row when "Default workspace" is selected
            $row.removeClass('wphoula-ws-mapped');
            $row.find('td').eq(3).html('<span class="description">—</span>');
            $row.find('td').eq(4).html('<span class="description">—</span>');
            $row.find('td').eq(5).html('');
        }
    });

    // Toggle price adjustment value input
    $(document).on('change', '.wphoula-ws-adj-type', function () {
        var $input = $(this).siblings('.wphoula-ws-adj-value');
        if ($(this).val() === 'none') {
            $input.hide();
        } else {
            $input.show();
        }
    });

    // Remove workspace mapping for a category
    $(document).on('click', '.wphoula-ws-map-remove', function () {
        var catId = $(this).data('cat-id');
        var $row = $(this).closest('tr');

        $row.removeClass('wphoula-ws-mapped');
        $row.find('.wphoula-ws-map-select').val('');
        $row.find('td').eq(3).html('<span class="description">—</span>');
        $row.find('td').eq(4).html('<span class="description">—</span>');
        $row.find('td').eq(5).html('');
    });

    // Save workspace mapping
    $(document).on('click', '#wphoula-save-workspace-map', function () {
        var $btn = $(this);
        var $status = $('#wphoula-ws-map-status');
        $btn.prop('disabled', true);
        $status.show().text(i18n.savingWorkspaceMap || 'Saving...').css('color', '#666');

        var data = {
            action: 'wphoula_save_workspace_map',
            nonce: nonce
        };

        // Collect all mapped categories
        var mappedCount = 0;
        var provisionQueue = [];

        $('.wphoula-ws-map-select').each(function () {
            var catId = $(this).data('cat-id');
            var wsId = $(this).val();
            if (!wsId) return;

            var wsName = $(this).find('option:selected').text();
            var $row = $(this).closest('tr');
            var adjType = $row.find('.wphoula-ws-adj-type').val() || 'none';
            var adjValue = $row.find('.wphoula-ws-adj-value').val() || 0;

            // Check if this workspace needs a key provisioned
            var $keyStatus = $row.find('.wphoula-key-status--ok');
            var needsKey = $keyStatus.length === 0;

            data['category_workspace_map[' + catId + '][workspace_id]'] = wsId;
            data['category_workspace_map[' + catId + '][workspace_name]'] = wsName;
            data['category_workspace_map[' + catId + '][price_adjustment_type]'] = adjType;
            data['category_workspace_map[' + catId + '][price_adjustment_value]'] = adjValue;
            // api_key will be provisioned separately

            if (needsKey) {
                provisionQueue.push({ catId: catId, wsId: wsId, wsName: wsName });
            }

            mappedCount++;
        });

        // First, provision API keys for workspaces that need them
        if (provisionQueue.length > 0) {
            $status.text(i18n.provisioningKey || 'Provisioning API keys...');
            provisionKeysSequentially(provisionQueue, 0, data, function (updatedData) {
                // Now save the complete mapping
                saveWorkspaceMap(updatedData, $btn, $status, mappedCount);
            });
        } else {
            saveWorkspaceMap(data, $btn, $status, mappedCount);
        }
    });

    /**
     * Provision API keys one by one for workspaces that need them.
     */
    function provisionKeysSequentially(queue, index, data, callback) {
        if (index >= queue.length) {
            callback(data);
            return;
        }

        var item = queue[index];
        $.post(ajaxUrl, {
            action: 'wphoula_provision_workspace_key',
            nonce: nonce,
            workspace_id: item.wsId,
            workspace_name: item.wsName
        }, function (response) {
            if (response.success && response.data && response.data.api_key) {
                data['category_workspace_map[' + item.catId + '][api_key]'] = response.data.api_key;

                // Update UI to show key is configured
                var $row = $('tr[data-cat-id="' + item.catId + '"]');
                $row.find('td').eq(3).html(
                    '<span class="wphoula-key-status wphoula-key-status--ok">' +
                    '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> ' +
                    (i18n.keyProvisioned || 'Configured') +
                    '</span>'
                );
            } else {
                // Mark as failed but continue
                var $row = $('tr[data-cat-id="' + item.catId + '"]');
                $row.find('td').eq(3).html(
                    '<span class="wphoula-key-status wphoula-key-status--missing">' +
                    '<span class="dashicons dashicons-warning" style="color:#d63638;"></span> ' +
                    (response.data || 'Error') +
                    '</span>'
                );
            }
            provisionKeysSequentially(queue, index + 1, data, callback);
        }).fail(function () {
            provisionKeysSequentially(queue, index + 1, data, callback);
        });
    }

    /**
     * Save the workspace mapping via AJAX.
     */
    function saveWorkspaceMap(data, $btn, $status, mappedCount) {
        $.post(ajaxUrl, data, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $status.text((i18n.workspaceMapSaved || 'Mapping saved.') + ' (' + mappedCount + ')').css('color', '#46b450');
                setTimeout(function () { $status.fadeOut(); }, 4000);
            } else {
                $status.text(response.data || (i18n.error || 'Error')).css('color', '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text(i18n.networkError || 'Network error').css('color', '#dc3232');
        });
    }

    // Provision a single workspace key (from the "Generate" button)
    $(document).on('click', '.wphoula-provision-ws-key', function () {
        var $btn = $(this);
        var wsId = $btn.data('ws-id');
        var wsName = $btn.data('ws-name');
        var catId = $btn.data('cat-id');

        $btn.prop('disabled', true).text('...');

        $.post(ajaxUrl, {
            action: 'wphoula_provision_workspace_key',
            nonce: nonce,
            workspace_id: wsId,
            workspace_name: wsName
        }, function (response) {
            if (response.success && response.data && response.data.api_key) {
                // Save the key to the mapping immediately
                var saveData = {
                    action: 'wphoula_save_workspace_map',
                    nonce: nonce
                };

                // Reload current mapping and add the key
                $('.wphoula-ws-map-select').each(function () {
                    var cid = $(this).data('cat-id');
                    var wid = $(this).val();
                    if (!wid) return;

                    var wName = $(this).find('option:selected').text();
                    var $r = $(this).closest('tr');
                    saveData['category_workspace_map[' + cid + '][workspace_id]'] = wid;
                    saveData['category_workspace_map[' + cid + '][workspace_name]'] = wName;
                    saveData['category_workspace_map[' + cid + '][price_adjustment_type]'] = $r.find('.wphoula-ws-adj-type').val() || 'none';
                    saveData['category_workspace_map[' + cid + '][price_adjustment_value]'] = $r.find('.wphoula-ws-adj-value').val() || 0;
                    if (parseInt(cid) === parseInt(catId)) {
                        saveData['category_workspace_map[' + cid + '][api_key]'] = response.data.api_key;
                    }
                });

                $.post(ajaxUrl, saveData, function () {
                    var $row = $('tr[data-cat-id="' + catId + '"]');
                    $row.find('td').eq(3).html(
                        '<span class="wphoula-key-status wphoula-key-status--ok">' +
                        '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> Configured' +
                        '</span>'
                    );
                });
            } else {
                $btn.prop('disabled', false).text('Generate');
                alert(response.data || 'Error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('Generate');
        });
    });

})(jQuery);
