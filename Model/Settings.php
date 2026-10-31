<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Commerce\SecretBox;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/** Native encrypted configuration. Attempt profiles retain the original credentials. */
class Settings
{
    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly EncryptorInterface $encryptor,
    ) {
    }
    public function value(string $name, int $store): string
    {
        $value = $this->config->getValue(
            'payment/lunixi/' . $name,
            ScopeInterface::SCOPE_STORE,
            $store,
        );
        return is_scalar($value) ? (string) $value : '';
    }
    public function flag(string $name, int $store): bool
    {
        return $this->value($name, $store) === '1';
    }
    public function box(): SecretBox
    {
        $encoded = getenv('LUNIXI_MAGENTO_ENCRYPTION_KEY');
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($raw) || strlen($raw) !== 32) {
            throw new \RuntimeException('A backed-up 32-byte server encryption key is required.');
        }
        return new SecretBox($raw);
    }
    /** @return array{merchantId:string,keyId:string,privateKey:string,webhookSecret:string} */
    public function credentials(int $store): array
    {
        $values = ['merchantId' => trim($this->value('merchant_id', $store))];
        $fields = [
                'keyId' => 'key_id',
                'privateKey' => 'private_key',
                'webhookSecret' => 'webhook_secret',
            ];
        foreach ($fields as $key => $field) {
            $cipher = $this->value($field, $store);
            $values[$key] = $cipher === '' ? '' : $this->encryptor->decrypt($cipher);
        }
        $values['privateKey'] = str_replace('\\n', "\n", $values['privateKey']);
        if (!str_contains($values['privateKey'], '-----BEGIN PRIVATE KEY-----')) {
            $der = base64_decode($values['privateKey'], true);
            if (!is_string($der) || strlen($der) > 4096 || strlen($der) < 32) {
                throw new \RuntimeException('An Ed25519 PKCS8 private key is required.');
            }
            $pemLabel = 'PRIVATE KEY';
            $values['privateKey'] =
                "-----BEGIN {$pemLabel}-----\n" .
                chunk_split(base64_encode($der), 64, "\n") .
                "-----END {$pemLabel}-----\n";
        }
        foreach ($values as $name => $value) {
            if (
                $value === '' ||
                strlen($value) > ($name === 'privateKey' ? 8192 : 512) ||
                ($name !== 'privateKey' && preg_match('/[\x00-\x20\x7f]/', $value))
            ) {
                throw new \RuntimeException('Complete valid merchant credentials are required.');
            }
        }
        return $values;
    }
}
