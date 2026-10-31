define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (Component, renderers) {
    'use strict';
    renderers.push({type: 'lunixi', component: 'Lunixi_Payments/js/view/payment/method-renderer/lunixi'});
    return Component.extend({});
});
