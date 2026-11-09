'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const printButton = document.getElementById('print-receipt');
    if (printButton) {
        printButton.addEventListener('click', () => window.print());
    }
});
