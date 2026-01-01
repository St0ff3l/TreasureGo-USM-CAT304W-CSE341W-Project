document.addEventListener('DOMContentLoaded', () => {
    // Mount header bar component with base path
    if (window.TreasureGoHeaderbar) {
        TreasureGoHeaderbar.mount({
            basePath: '../../'
        });
    }

    // Initialize order details module
    if (window.OrderDetailsOrder) {
        if (typeof window.OrderDetailsOrder.bindSafetyNet === 'function') {
            window.OrderDetailsOrder.bindSafetyNet();
        }
        if (typeof window.OrderDetailsOrder.init === 'function') {
            window.OrderDetailsOrder.init();
        }
    }
});