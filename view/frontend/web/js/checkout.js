define(['require', 'mage/translate'], function (require, $t) {
    'use strict';
    return function (config, element) {
        var notice = document.getElementById('lunixi-checkout-unavailable');
        function unavailable() {
            if (notice) {
                notice.hidden = false;
                notice.textContent = $t('The payment form could not load. Contact the merchant before attempting another payment.');
            }
        }
        require(['lunixi-official-checkout'], function (sdk) {
            try {
                var lunixi = sdk && sdk.checkout ? sdk : window.Lunixi;
                if (!lunixi || !lunixi.checkout || !config || typeof config !== 'object') { throw new Error('Unavailable'); }
                var result = lunixi.checkout.create(config).mount('#' + element.id);
                if (notice) { notice.hidden = true; }
                if (result && typeof result.catch === 'function') { result.catch(unavailable); }
            } catch (error) { unavailable(); }
        }, unavailable);
    };
});
