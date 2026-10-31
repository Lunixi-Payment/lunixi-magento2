<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

/** Immutable encrypted signing profiles retain credentials needed by old payments. */
class Profiles
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Settings $settings,
        private readonly ClientFactory $clients,
    ) {
    }
    public function current(int $store): string
    {
        $credentials = $this->settings->credentials($store);
        $this->clients->create($credentials); // Import/validate the actual Ed25519 key before persistence.
        $this->ledger->verify();
        $id = hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
        $this->ledger->db()->insertOnDuplicate(
            $this->ledger->table('lunixi_payment_profile'),
            [
                'profile_id' => $id,
                'merchant_id' => $credentials['merchantId'],
                'payload' => $this->settings
                    ->box()
                    ->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR)),
            ],
            ['profile_id'],
        );
        return $id;
    }
    /** @return array{merchantId:string,keyId:string,privateKey:string,webhookSecret:string} */
    public function get(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new \RuntimeException('Unknown credential profile.');
        }
        $cipher = $this->ledger
            ->db()
            ->fetchOne(
                'SELECT payload FROM ' .
                    $this->ledger->quote('lunixi_payment_profile') .
                    ' WHERE profile_id=?',
                [$id],
            );
        if (!is_string($cipher)) {
            throw new \RuntimeException('Unknown credential profile.');
        }
        return json_decode($this->settings->box()->decrypt($cipher), true, 32, JSON_THROW_ON_ERROR);
    }
}
