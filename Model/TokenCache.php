<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\Auth\TokenStoreInterface;
use Magento\Framework\App\CacheInterface;

/** Cache backends receive encrypted bearer tokens, never plaintext. */
class TokenCache implements TokenStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Settings $settings,
    ) {
    }
    public function get(string $key): ?string
    {
        $value = $this->cache->load('lunixi_' . hash('sha256', $key));
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return $this->settings->box()->decrypt($value);
        } catch (\Throwable) {
            $this->delete($key);
            return null;
        }
    }
    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->cache->save(
            $this->settings->box()->encrypt($value),
            'lunixi_' . hash('sha256', $key),
            ['LUNIXI_PAYMENT_TOKEN'],
            $ttlSeconds,
        );
    }
    public function delete(string $key): void
    {
        $this->cache->remove('lunixi_' . hash('sha256', $key));
    }
}
