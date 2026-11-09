'use strict';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-confirm-cancel]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Cancel this unpaid order? If you approve an M-Pesa prompt afterward, contact the store.')) {
                event.preventDefault();
            }
        });
    });
});
