<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EpisciencesApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EpisciencesApiClientTest extends TestCase
{
    public function testFetchJournalMetadataSuccess(): void
    {
        $mockData = [
            'code' => 'dmtcs',
            'name' => 'Discrete Mathematics & Theoretical Computer Science',
            'settings' => [
                ['setting' => 'ISSN', 'value' => '1860-5974'],
                ['setting' => 'journalCreationYear', 'value' => '2000'],
                ['setting' => 'journalPublisher', 'value' => 'Logical Methods in Computer Science e.V.'],
                ['setting' => 'journalDescription', 'value' => 'Logical Methods in Computer Science is a fully refereed...'],
                ['setting' => 'journalKeywords', 'value' => 'mathematics; physics'],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/ld+json'], json_encode($mockData)),
        ]);

        $container = [];
        $history = Middleware::history($container);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);

        $httpClient = new Client(['handler' => $handlerStack]);
        $logger = $this->createStub(LoggerInterface::class);

        $client = new EpisciencesApiClient(
            'https://api-dev.episciences.org/',
            $logger,
            false,
            'api-dev.episciences.org',
            $httpClient
        );

        $result = $client->fetchJournalMetadata('dmtcs');

        $this->assertNotNull($result);
        $this->assertSame('dmtcs', $result['code']);
        $this->assertSame('Discrete Mathematics & Theoretical Computer Science', $result['title']);
        $this->assertSame('1860-5974', $result['issn']);
        $this->assertSame('2000', $result['date']);
        $this->assertSame('Logical Methods in Computer Science e.V.', $result['publisher']);
        $this->assertSame('Logical Methods in Computer Science is a fully refereed...', $result['description']);
        $this->assertSame(['mathematics', 'physics'], $result['subjects']);

        // Verify request and headers
        $this->assertCount(1, $container);
        $request = $container[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api-dev.episciences.org/api/journals/dmtcs', (string)$request->getUri());
    }

    public function testFetchJournalMetadataNotFound(): void
    {
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);
        $logger = $this->createStub(LoggerInterface::class);

        $client = new EpisciencesApiClient(
            'https://api-dev.episciences.org/',
            $logger,
            false,
            '',
            $httpClient
        );

        $result = $client->fetchJournalMetadata('unknown');

        $this->assertNull($result);
    }

    public function testFetchJournalsMetadataMixesSuccessAndFailure(): void
    {
        $mockData = [
            'code' => 'dmtcs',
            'name' => 'Discrete Mathematics & Theoretical Computer Science',
            'settings' => [],
        ];

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/ld+json'], json_encode($mockData)),
            new Response(404, [], 'Not Found'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);
        $logger = $this->createStub(LoggerInterface::class);

        $client = new EpisciencesApiClient(
            'https://api-dev.episciences.org/',
            $logger,
            false,
            '',
            $httpClient
        );

        $results = $client->fetchJournalsMetadata(['dmtcs', 'unknown']);

        $this->assertCount(2, $results);
        $this->assertNotNull($results['dmtcs']);
        $this->assertSame('Discrete Mathematics & Theoretical Computer Science', $results['dmtcs']['title']);
        $this->assertNull($results['unknown']);
    }

    public function testFetchJournalMetadataNetworkError(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'https://api-dev.episciences.org/api/journals/dmtcs')),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);
        
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error');

        $client = new EpisciencesApiClient(
            'https://api-dev.episciences.org/',
            $logger,
            false,
            '',
            $httpClient
        );

        $result = $client->fetchJournalMetadata('dmtcs');

        $this->assertNull($result);
    }
}
