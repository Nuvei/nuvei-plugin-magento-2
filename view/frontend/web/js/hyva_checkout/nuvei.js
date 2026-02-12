(() => {
    'use strict';
    
    const nuveiSimplyConnectScript  = document.getElementById('nuvei-simply-connect-script');
    let methodCode                  = '';

    // Wait for Hyva Checkout to be ready
    window.addEventListener('hyva-checkout-loaded', () => {
        console.log('Hyva Checkout is ready, initializing My_App SDK...');

        // You can now access the SDK or register
        // listeners to the Hyva Checkout Events
        // Example: window.myAppSdk.init();
    });
    
    window.addEventListener('checkout:payment:method-activate', (event) => {
        // Access the selected payment method code
        methodCode = event.detail.method;
        console.log("Payment Method Changed to:", methodCode);
        
        if ('nuvei' == methodCode && typeof simplyConnect != 'undefined') {
            console.log('nuvei is selected and simplyConnect is loaded');
        }
    });

    // run our logic when page is loaded
    if (nuveiSimplyConnectScript) {
        nuveiSimplyConnectScript.addEventListener('load', () => {
            console.log('simply is loaded...', typeof simplyConnect);
            
            if ('nuvei' == methodCode) {
                console.log('... and nuvei is selected');
            }
        });
    }
    
})();
