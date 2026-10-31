<?php

declare(strict_types=1);

namespace Lunixi\Payments\Block\Adminhtml;

use Lunixi\Payments\Model\Profiles;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Store\Model\StoreManagerInterface;

class Instructions extends Field
{
    /** @param array<string,mixed> $data */
    public function __construct(
        Context $context,
        private readonly Profiles $profiles,
        private readonly StoreManagerInterface $stores,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }
    protected function _getElementHtml(AbstractElement $element)
    {
        try {
            $store = (int) $this->getRequest()->getParam('store');
            if ($store === 0) {
                $website = (int) $this->getRequest()->getParam('website');
                $site = $this->stores->getWebsite($website ?: null);
                if (!($site instanceof \Magento\Store\Model\Website)) {
                    throw new \RuntimeException('Native website required.');
                }
                $default = $website
                    ? $site->getDefaultStore()
                    : $this->stores->getDefaultStoreView();
                if ($default === null) {
                    throw new \RuntimeException('Native default store required.');
                }
                $store = (int) $default->getId();
            }
            $id = $this->profiles->current($store);
            $nativeStore = $this->stores->getStore($store);
            if (!($nativeStore instanceof \Magento\Store\Model\Store)) {
                throw new \RuntimeException('Native store required.');
            }
            $url =
                $nativeStore->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, true) .
                'lunixi/notification/webhook/profile/' .
                $id;
            return '<p>' .
                $this->escapeHtml(
                    __(
                        'Register this HTTPS webhook URL in Lunixi. Retain prior profiles for refunds.',
                    ),
                ) .
                '</p><code>' .
                $this->escapeHtml($url) .
                '</code>';
        } catch (\Throwable) {
            return '<p>' .
                $this->escapeHtml(
                    __(
                        'Save valid credentials and configure the server encryption key to display the webhook URL.',
                    ),
                ) .
                '</p>';
        }
    }
}
