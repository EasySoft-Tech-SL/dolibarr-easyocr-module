/**
 * EasyOcr Panel JavaScript
 *
 * @package    EasyOcr
 * @copyright  2025-2026 EasySoft Tech S.L.
 * @license    GPL-3.0+
 */

(function() {
    'use strict';

    $(document).ready(function() {

        // Select All checkbox
        $('#checkall').on('change', function() {
            $('.checkforselect').not('#checkall').prop('checked', $(this).prop('checked'));
        });

        // Sync "select all" when individual checkboxes change
        $(document).on('change', '.checkforselect:not(#checkall)', function() {
            var total = $('.checkforselect:not(#checkall)').length;
            var checked = $('.checkforselect:not(#checkall):checked').length;
            $('#checkall').prop('checked', total === checked);
        });

        // Modal close
        $('.eo-modal-close').on('click', function() {
            $(this).closest('.eo-modal').hide();
        });

        // Close modal on outside click
        $('.eo-modal').on('click', function(e) {
            if ($(e.target).hasClass('eo-modal')) {
                $(this).hide();
            }
        });

        // ESC to close modals
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.eo-modal:visible').hide();
            }
        });

        // Inline edit (templates)
        $('.eo-editable').on('click', function() {
            var id = $(this).data('id');
            var currentValue = $(this).text().trim();
            $('#eoEditId').val(id);
            $('#eoEditName').val(currentValue);
            $('#eoEditModal').show();
            $('#eoEditName').focus();
        });

    });

})();
    
})();
