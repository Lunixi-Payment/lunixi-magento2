<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Checkout;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\RawFactory;

/** Anonymous browser POST is informational: no session, order, gateway or body-field access. */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Http $request,
        private readonly RawFactory $results,
    ) {
    }

    public function execute()
    {
        $result = $this->results->create()->setHeader('Cache-Control', 'no-store', true);
        if (!$this->request->isSecure()) {
            return $result->setHttpResponseCode(400)->setContents('');
        }
        // A known bounded Content-Length lets us ignore the body without buffering it.
        // Chunked/unknown-length submissions are rejected instead of materialized.
        $length = $this->request->getServer('CONTENT_LENGTH');
        if ($length === null || $length === '') {
            return $result->setHttpResponseCode(411)->setContents('');
        }
        if (
            filter_var($length, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 65536],
            ]) === false
        ) {
            return $result->setHttpResponseCode(413)->setContents('');
        }
        $prefix = $this->request->getBaseUrl();
        if (
            !preg_match('#^(?:/[A-Za-z0-9._~-]+)*$#D', $prefix) ||
            array_intersect(explode('/', $prefix), ['.', '..']) !== []
        ) {
            return $result->setHttpResponseCode(400)->setContents('');
        }
        return $result
            ->setHttpResponseCode(303)
            ->setHeader('Location', $prefix . '/lunixi/checkout/returned', true)
            ->setContents('');
    }

    public function createCsrfValidationException(
        RequestInterface $request,
    ): ?InvalidRequestException {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
