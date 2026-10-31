<?php

declare(strict_types=1);

namespace Lunixi\Payments\Controller\Notification;

use Lunixi\Payments\Model\Runtime;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\RawFactory;

/** Signatures replace browser CSRF only for this machine-to-machine receiver. */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Runtime $runtime,
        private readonly \Magento\Framework\App\Request\Http $request,
        private readonly RawFactory $results,
    ) {
    }
    public function execute()
    {
        $response = $this->results->create()->setHeader('Cache-Control', 'no-store', true);
        try {
            if (!$this->request->isSecure()) {
                return $response->setHttpResponseCode(400)->setContents('Invalid delivery');
            }
            $raw = $this->request->getContent();
            if (!is_string($raw) || strlen($raw) > 1048576) {
                return $response->setHttpResponseCode(413)->setContents('Delivery too large');
            }
            $headers = [];
            $nativeHeaders = $this->request->getHeaders();
            if (!($nativeHeaders instanceof \Laminas\Http\Headers)) {
                throw new \RuntimeException('Native headers required.');
            }
            foreach ($nativeHeaders as $header) {
                if (!($header instanceof \Laminas\Http\Header\HeaderInterface)) {
                    throw new \RuntimeException('Malformed delivery header.');
                }
                $name = strtolower($header->getFieldName());
                if (isset($headers[$name])) {
                    throw new \RuntimeException('Duplicate signature header.');
                }
                $headers[$name] = $header->getFieldValue();
            }
            $this->runtime->receive((string) $this->request->getParam('profile'), $raw, $headers);
            return $response->setContents('OK');
        } catch (\Throwable) {
            return $response->setHttpResponseCode(503)->setContents('Verification pending');
        }
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
