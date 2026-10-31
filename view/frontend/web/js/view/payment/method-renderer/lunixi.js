define(['Magento_Checkout/js/view/payment/default', 'jquery', 'mage/cookies'], function (Component, $) {
    'use strict';
    return Component.extend({
        defaults: {template: 'Lunixi_Payments/payment/lunixi'},
        redirectAfterPlaceOrder: false,
        afterPlaceOrder: function () {
            var form = document.createElement('form'), field = document.createElement('input');
            form.method = 'POST';
            form.action = window.checkoutConfig.payment.lunixi.startUrl;
            field.type = 'hidden'; field.name = 'form_key'; field.value = $.mage.cookies.get('form_key');
            form.appendChild(field); document.body.appendChild(form); form.submit();
        }
    });
});
