<?php

declare(strict_types=1);

namespace Lunixi\Payments\Model;

use Lunixi\Payments\Vendor\Lunixi\Sdk\{Configuration, LunixiClient};
use Lunixi\Payments\Vendor\Lunixi\Sdk\Http\HttpClientInterface;

class ClientFactory
{
    /** @var array<string,LunixiClient> */
    private array $clients = [];
    public function __construct(
        private readonly TokenCache $tokens,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }
    /** @param array{merchantId:string,keyId:string,privateKey:string,webhookSecret:string} $credentials */
    public function create(array $credentials): LunixiClient
    {
        $key = hash('sha256', json_encode($credentials, JSON_THROW_ON_ERROR));
        return $this->clients[$key] ??= new LunixiClient(
            new Configuration([
                'baseUrl' => 'https://api-gateway.lunixi.com',
                'keyId' => $credentials['keyId'],
                'privateKey' => $credentials['privateKey'],
                'maxRetries' => 1,
                'timeout' => 20,
                'userAgent' => 'lunixi-magento2/1.0.0',
            ]),
            $this->http,
            $this->tokens,
        );
    }
}
