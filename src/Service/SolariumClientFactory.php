<?php

declare(strict_types=1);


namespace App\Service;

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SolariumClientFactory
{
    public static function createClient(
        string $solrUrl,
        EventDispatcherInterface $eventDispatcher
    ): Client {
        $parsedUrl = parse_url($solrUrl);
        if ($parsedUrl === false) {
            $parsedUrl = [];
        }

        $host = $parsedUrl['host'] ?? '127.0.0.1';
        $port = $parsedUrl['port'] ?? 8983;
        
        // We set the path to '/' and context to 'solr' to match Solr's standard URL structure
        // avoiding path duplication (e.g. /solr/solr/core)
        $config = [
            'endpoint' => [
                'episciences' => [
                    'host' => $host,
                    'port' => (int) $port,
                    'path' => '/',
                    'context' => 'solr',
                    'core' => 'episciences',
                ]
            ]
        ];

        $adapter = new Curl();

        return new Client($adapter, $eventDispatcher, $config);
    }
}
