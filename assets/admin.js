(function($) {
    'use strict';

    // =====================
    // Character Counter
    // =====================
    $('#post_text').on('input', function() {
        var len = $(this).val().length;
        var $counter = $('#char-count');
        $counter.text(len);

        $counter.removeClass('warning danger');
        if (len > 280) {
            $counter.addClass('danger');
        } else if (len > 250) {
            $counter.addClass('warning');
        }
    });

    // =====================
    // Populate from Blog Post
    // =====================
    $('#wpbq-populate-from-post').on('click', function() {
        var $selected = $('#blog_post_select option:selected');
        var title = $selected.data('title');
        var url = $selected.data('url');
        var image = $selected.data('image');

        if (!title) {
            alert('Please select a blog post first.');
            return;
        }

        var text = '📝 ' + title + '\n\n🔗 ' + url;

        if (text.length > 300) {
            text = text.substring(0, 297) + '...';
        }

        $('#post_text').val(text).trigger('input');
        $('#link_url').val(url);
        $('#image_url').val(image || '');
    });

    // =====================
    // Add to Queue (AJAX)
    // =====================
    $('#wpbq-add-form').on('submit', function(e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Adding...');

        var data = {
            action: 'wpbq_add_queue_item',
            nonce: wpbq.nonce,
            post_text: $('#post_text').val(),
            link_url: $('#link_url').val(),
            image_url: $('#image_url').val(),
            blog_post_id: $('#blog_post_select').val() || 0,
            scheduled_at: $('#scheduled_at').val() || ''
        };

        $.post(wpbq.ajax_url, data, function(response) {
            if (response.success) {
                alert('✅ ' + response.data.message);
                location.reload();
            } else {
                alert('❌ Error: ' + response.data);
            }
        }).fail(function() {
            alert('❌ Network error. Please try again.');
        }).always(function() {
            $btn.prop('disabled', false).text(originalText);
        });
    });

    // =====================
    // Post Now (AJAX)
    // =====================
    $(document).on('click', '.wpbq-post-now', function() {
        var id = $(this).data('id');
        var $btn = $(this);

        if (!confirm('Post this item to Bluesky right now?')) return;

        $btn.prop('disabled', true).text('Posting...');

        $.post(wpbq.ajax_url, {
            action: 'wpbq_post_now',
            nonce: wpbq.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                alert('✅ ' + response.data);
                location.reload();
            } else {
                alert('❌ ' + response.data);
                $btn.prop('disabled', false).text('🚀 Post Now');
            }
        }).fail(function() {
            alert('❌ Network error.');
            $btn.prop('disabled', false).text('🚀 Post Now');
        });
    });

    // =====================
    // Delete Item (AJAX)
    // =====================
    $(document).on('click', '.wpbq-delete', function() {
        var id = $(this).data('id');

        if (!confirm('Are you sure you want to delete this queue item?')) return;

        $.post(wpbq.ajax_url, {
            action: 'wpbq_delete_queue_item',
            nonce: wpbq.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                $('tr[data-id="' + id + '"]').fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert('❌ Failed to delete.');
            }
        });
    });

    // =====================
    // Re-queue Item (AJAX)
    // =====================
    $(document).on('click', '.wpbq-requeue', function() {
        var id = $(this).data('id');
        var $btn = $(this);

        $btn.prop('disabled', true).text('Re-queuing...');

        $.post(wpbq.ajax_url, {
            action: 'wpbq_requeue_item',
            nonce: wpbq.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                alert('✅ ' + response.data);
                location.reload();
            } else {
                alert('❌ Failed to re-queue.');
                $btn.prop('disabled', false).text('🔄 Re-queue');
            }
        });
    });

    // =====================
    // Edit Item Text (AJAX)
    // =====================
    $(document).on('click', '.wpbq-edit', function() {
        var $cell = $(this).closest('tr').find('.wpbq-item-text-cell');
        if ($cell.find('.wpbq-edit-form').length) return;

        var $form = $(
            '<div class="wpbq-edit-form">' +
                '<textarea rows="4" class="large-text" maxlength="300"></textarea>' +
                '<p class="description">Characters: <span class="wpbq-edit-count">0</span>/300</p>' +
                '<button type="button" class="button button-primary button-small wpbq-edit-save">Save</button> ' +
                '<button type="button" class="button button-small wpbq-edit-cancel">Cancel</button>' +
            '</div>'
        );
        $form.find('textarea').val($cell.data('text'));
        $cell.find('.wpbq-item-preview').hide();
        $cell.prepend($form);
        $form.find('textarea').trigger('input').focus();
    });

    $(document).on('input', '.wpbq-edit-form textarea', function() {
        var len = $(this).val().length;
        var $counter = $(this).siblings('.description').find('.wpbq-edit-count');
        $counter.text(len).removeClass('warning danger');
        if (len > 280) {
            $counter.addClass('danger');
        } else if (len > 250) {
            $counter.addClass('warning');
        }
    });

    $(document).on('click', '.wpbq-edit-cancel', function() {
        var $cell = $(this).closest('.wpbq-item-text-cell');
        $cell.find('.wpbq-edit-form').remove();
        $cell.find('.wpbq-item-preview').show();
    });

    $(document).on('click', '.wpbq-edit-save', function() {
        var $btn = $(this);
        var $cell = $btn.closest('.wpbq-item-text-cell');
        var id = $btn.closest('tr').data('id');
        var text = $cell.find('.wpbq-edit-form textarea').val();

        $btn.prop('disabled', true).text('Saving...');

        $.post(wpbq.ajax_url, {
            action: 'wpbq_edit_queue_item',
            nonce: wpbq.nonce,
            id: id,
            post_text: text
        }, function(response) {
            if (response.success) {
                var saved = response.data.post_text;
                var preview = saved.length > 100 ? saved.substr(0, 100) + '...' : saved;
                $cell.data('text', saved);
                $cell.find('.wpbq-item-preview').text(preview).show();
                $cell.find('.wpbq-edit-form').remove();
            } else {
                alert('❌ ' + response.data);
                $btn.prop('disabled', false).text('Save');
            }
        }).fail(function() {
            alert('❌ Network error.');
            $btn.prop('disabled', false).text('Save');
        });
    });




        $('#wpbq-debug-image-btn').on('click', function() {
            var url = $('#wpbq-debug-image-url').val();
            if (!url) { alert('Enter an image URL first'); return; }

            var $result = $('#wpbq-debug-image-result');
            $result.show().text('Fetching...');

            $.post(wpbq.ajax_url, {
                action: 'wpbq_debug_image',
                nonce: wpbq.nonce,
                url: url
            }, function(response) {
                $result.text(JSON.stringify(response.data, null, 2));
            }).fail(function() {
                $result.text('Network error');
            });
        });




    // =====================
    // Import Archives (AJAX)
    // =====================
    $('#wpbq-import-form').on('submit', function(e) {
        e.preventDefault();

        var $btn = $('#wpbq-import-btn');
        var $status = $('#wpbq-import-status');

        $btn.prop('disabled', true).text('Importing...');
        $status.removeClass('success error').text('');

        var data = {
            action: 'wpbq_import_archives',
            nonce: wpbq.nonce,
            post_type: $(this).find('[name="post_type"]').val(),
            category: $(this).find('[name="category"]').val(),
            date_from: $(this).find('[name="date_from"]').val(),
            date_to: $(this).find('[name="date_to"]').val(),
            max_posts: $(this).find('[name="max_posts"]').val()
        };

        $.post(wpbq.ajax_url, data, function(response) {
            if (response.success) {
                $status.addClass('success').text('✅ ' + response.data.message);
            } else {
                $status.addClass('error').text('❌ ' + response.data);
            }
        }).fail(function() {
            $status.addClass('error').text('❌ Network error.');
        }).always(function() {
            $btn.prop('disabled', false).text('📥 Import to Queue');
        });
    });

    // =====================
    // Test Connection (AJAX)
    // =====================
    $('#wpbq-test-connection').on('click', function() {
            var $btn = $(this);
            var $result = $('#wpbq-test-result');

            $btn.prop('disabled', true);
            $result.html('<span class="wpbq-loading"></span> Testing...');

            $.post(wpbq.ajax_url, {
                action: 'wpbq_test_connection',
                nonce: wpbq.nonce
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:#1e8e3e;">' + response.data + '</span>');
                } else {
                    $result.html('<span style="color:#d93025;">❌ ' + response.data + '</span>');
                }
            }).fail(function() {
                $result.html('<span style="color:#d93025;">❌ Network error</span>');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        $('#wpbq-test-mastodon').on('click', function() {
            var $btn = $(this);
            var $result = $('#wpbq-test-mastodon-result');

            $btn.prop('disabled', true);
            $result.html('<span class="wpbq-loading"></span> Testing...');

            $.post(wpbq.ajax_url, {
                action: 'wpbq_test_mastodon',
                nonce: wpbq.nonce
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:#1e8e3e;">' + response.data + '</span>');
                } else {
                    $result.html('<span style="color:#d93025;">❌ ' + response.data + '</span>');
                }
            }).fail(function() {
                $result.html('<span style="color:#d93025;">❌ Network error</span>');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        $('#wpbq-test-buffer').on('click', function() {
            var $btn = $(this);
            var $result = $('#wpbq-test-buffer-result');

            $btn.prop('disabled', true);
            $result.html('<span class="wpbq-loading"></span> Testing...');

            $.post(wpbq.ajax_url, {
                action: 'wpbq_test_buffer',
                nonce: wpbq.nonce
            }, function(response) {
                if (response.success) {
                    $result.html('<span style="color:#1e8e3e;">' + response.data + '</span>');
                } else {
                    $result.html('<span style="color:#d93025;">❌ ' + response.data + '</span>');
                }
            }).fail(function() {
                $result.html('<span style="color:#d93025;">❌ Network error</span>');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

    // =====================
    // Post Templates: Add/Remove Rows
    // =====================
    function wpbqUpdateTemplateRemoveButtons() {
        var $rows = $('#wpbq-template-list .wpbq-template-row');
        if ($rows.length <= 1) {
            $rows.find('.wpbq-remove-template').hide();
        } else {
            $rows.find('.wpbq-remove-template').show();
        }
    }

    $('#wpbq-add-template').on('click', function() {
        var $row = $(
            '<div class="wpbq-template-row">' +
                '<textarea name="wpbq_post_templates[]" rows="6" class="large-text" placeholder="📝 {title}&#10;&#10;{excerpt}&#10;&#10;🔗 {url}"></textarea>' +
                '<p class="wpbq-template-row-actions">' +
                    '<button type="button" class="button wpbq-remove-template">🗑️ Remove This Template</button>' +
                '</p>' +
            '</div>'
        );
        $('#wpbq-template-list').append($row);
        wpbqUpdateTemplateRemoveButtons();
        $row.find('textarea').trigger('focus');
    });

    $(document).on('click', '.wpbq-remove-template', function() {
        $(this).closest('.wpbq-template-row').remove();
        wpbqUpdateTemplateRemoveButtons();
    });

    wpbqUpdateTemplateRemoveButtons();

    // =====================
    // Drag & Drop Reordering
    // =====================
    if ($('#wpbq-queue-body').length && $.fn.sortable) {
        $('#wpbq-queue-body').sortable({
            handle: '.wpbq-drag-handle',
            axis: 'y',
            placeholder: 'ui-sortable-placeholder',
            update: function(event, ui) {
                var order = [];
                $('#wpbq-queue-body tr').each(function(index) {
                    order.push({
                        id: $(this).data('id'),
                        position: index
                    });
                });

                // Save order via AJAX
                $.post(wpbq.ajax_url, {
                    action: 'wpbq_update_order',
                    nonce: wpbq.nonce,
                    order: JSON.stringify(order)
                });
            }
        });
    }

})(jQuery);