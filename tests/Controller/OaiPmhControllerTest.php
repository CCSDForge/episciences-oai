<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Solarium\Client;
use Solarium\QueryType\Select\Query\Query as SelectQuery;
use Solarium\QueryType\Select\Result\Document as SelectDocument;
use Solarium\QueryType\Select\Result\Result as SelectResult;
use Solarium\Component\Result\FacetSet as FacetSetResult;
use Solarium\Component\Result\Facet\Pivot\Pivot as PivotResult;
use Solarium\Component\Result\Facet\Pivot\PivotItem as PivotItemResult;

class OaiPmhControllerTest extends WebTestCase
{
    private const TEST_DATE = '2026-07-10T12:00:00Z';
    private const DC_XML = '<oai_dc:dc xmlns:oai_dc="http://www.openarchives.org/OAI/2.0/oai_dc/" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>Test Title</dc:title></oai_dc:dc>';

    public function testIdentify(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', ['verb' => 'Identify']);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'text/xml; charset=utf-8');

        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<repositoryName>Episciences.org</repositoryName>', $responseContent);
        $this->assertStringContainsString('<protocolVersion>2.0</protocolVersion>', $responseContent);
        $this->assertStringContainsString('<earliestDatestamp>1978-01-01T00:00:00Z</earliestDatestamp>', $responseContent);
        $this->assertStringContainsString('<granularity>YYYY-MM-DDThh:mm:ssZ</granularity>', $responseContent);
        $this->assertStringContainsString('<repositoryIdentifier>episciences.org</repositoryIdentifier>', $responseContent);
        $this->assertStringContainsString('Episciences is an overlay journal platform', $responseContent);
    }

    public function testIdentifyWithExtraParamsReturnsBadArgument(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', ['verb' => 'Identify', 'foo' => 'bar']);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badArgument">', $responseContent);
        // The <request> element must carry no attributes on badArgument errors
        $this->assertStringContainsString('<request>http://localhost/</request>', $responseContent);
    }

    public function testInvalidVerbReturnsBadVerb(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', ['verb' => 'InvalidVerb']);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badVerb">', $responseContent);
        // The <request> element must carry no attributes on badVerb errors
        $this->assertStringContainsString('<request>http://localhost/</request>', $responseContent);
    }

    public function testListMetadataFormatsWithInvalidIdentifierReturnsIdDoesNotExist(): void
    {
        $client = static::createClient();

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(0, [])
        ));

        $client->request('GET', '/', [
            'verb' => 'ListMetadataFormats',
            'identifier' => 'oai:episciences.org:jdmdh:99999'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="idDoesNotExist">', $responseContent);
    }

    public function testListSets(): void
    {
        $client = static::createClient();

        // Stub Solarium Client and components
        $solariumClientMock = $this->createStub(Client::class);

        $queryMock = $this->createStub(SelectQuery::class);
        $queryFacetSetMock = $this->createStub(\Solarium\Component\FacetSet::class);
        $queryPivotMock = $this->createStub(\Solarium\Component\Facet\Pivot::class);

        $resultMock = $this->createStub(SelectResult::class);
        $resultFacetSetMock = $this->createStub(FacetSetResult::class);
        $resultPivotMock = $this->createStub(PivotResult::class);

        $solariumClientMock->method('createQuery')
            ->willReturn($queryMock);

        $solariumClientMock->method('select')
            ->willReturn($resultMock);

        // Stub query getFacetSet() flow
        $queryMock->method('getFacetSet')
            ->willReturn($queryFacetSetMock);

        $queryFacetSetMock->method('createFacetPivot')
            ->willReturn($queryPivotMock);

        $queryPivotMock->method('addFields')
            ->willReturn($queryPivotMock);

        // Stub result getFacetSet() flow
        $resultMock->method('getFacetSet')
            ->willReturn($resultFacetSetMock);

        $resultFacetSetMock->method('getFacet')
            ->willReturn($resultPivotMock);

        // Build list of PivotItem stubs
        $pivotItemMock = $this->createStub(PivotItemResult::class);
        $pivotItemMock->method('getValue')->willReturn('dmtcs');

        $nestedPivotItemMock = $this->createStub(PivotItemResult::class);
        $nestedPivotItemMock->method('getValue')->willReturn('Discrete Mathematics & Theoretical Computer Science');

        $pivotItemMock->method('getPivot')->willReturn([$nestedPivotItemMock]);

        $resultPivotMock->method('getIterator')
            ->willReturn(new \ArrayIterator([$pivotItemMock]));

        // Stub EpisciencesApiClient to avoid real HTTP requests
        $apiClientMock = $this->createStub(\App\Service\EpisciencesApiClient::class);
        $apiClientMock->method('fetchJournalsMetadata')
            ->willReturn([
                'dmtcs' => [
                    'code' => 'dmtcs',
                    'title' => 'Discrete Mathematics & Theoretical Computer Science',
                    'description' => 'Logical Methods in Computer Science is a fully refereed...',
                    'publisher' => 'Logical Methods in Computer Science e.V.',
                    'date' => '2000',
                    'issn' => '1860-5974',
                    'subjects' => ['mathematics', 'physics'],
                ],
            ]);

        $cache = static::getContainer()->get(\Psr\Cache\CacheItemPoolInterface::class);
        $cache->deleteItem('oai-sets-facet-data');

        static::getContainer()->set(Client::class, $solariumClientMock);
        static::getContainer()->set(\App\Service\EpisciencesApiClient::class, $apiClientMock);

        $client->request('GET', '/', ['verb' => 'ListSets']);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        // Verify default sets and our mocked set are present
        $this->assertStringContainsString('<setSpec>journal</setSpec>', $responseContent);
        $this->assertStringContainsString('<setSpec>openaire</setSpec>', $responseContent);
        $this->assertStringContainsString('<setSpec>driver</setSpec>', $responseContent);
        $this->assertStringContainsString('<setSpec>journal:dmtcs</setSpec>', $responseContent);
        $this->assertStringContainsString('<setName>Discrete Mathematics &amp; Theoretical Computer Science</setName>', $responseContent);

        // Verify setDescription elements are present
        $this->assertStringContainsString('<setDescription', $responseContent);
        $this->assertStringContainsString('<dc:title>Discrete Mathematics &amp; Theoretical Computer Science</dc:title>', $responseContent);
        $this->assertStringContainsString('<dc:publisher>Logical Methods in Computer Science e.V.</dc:publisher>', $responseContent);
        $this->assertStringContainsString('<dc:date>2000</dc:date>', $responseContent);
        $this->assertStringContainsString('<dc:description>Logical Methods in Computer Science is a fully refereed...</dc:description>', $responseContent);
        $this->assertStringContainsString('<dc:subject>mathematics</dc:subject>', $responseContent);
        $this->assertStringContainsString('<dc:subject>physics</dc:subject>', $responseContent);
        $this->assertStringContainsString('<dc:identifier>urn:ISSN:1860-5974</dc:identifier>', $responseContent);
    }

    public function testListMetadataFormatsSuccess(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListMetadataFormats'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<metadataPrefix>oai_dc</metadataPrefix>', $responseContent);
        $this->assertStringContainsString('<metadataPrefix>tei</metadataPrefix>', $responseContent);
        $this->assertStringContainsString('<metadataPrefix>oai_openaire</metadataPrefix>', $responseContent);
        $this->assertStringContainsString('<metadataPrefix>crossref</metadataPrefix>', $responseContent);
    }

    public function testGetRecordSuccess(): void
    {
        $client = static::createClient();

        $documentStub = $this->createSolrDocumentStub([
            'docid' => 123,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
            'doc_dc' => self::DC_XML,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(1, [$documentStub])
        ));

        $client->request('GET', '/', [
            'verb' => 'GetRecord',
            'identifier' => 'oai:episciences.org:jdmdh:123',
            'metadataPrefix' => 'oai_dc'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:123</identifier>', $responseContent);
        $this->assertStringContainsString('<setSpec>journal:jdmdh</setSpec>', $responseContent);
        $this->assertStringContainsString('<dc:title>Test Title</dc:title>', $responseContent);
    }

    public function testGetRecordInvalidFormatReturnsCannotDisseminateFormat(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'GetRecord',
            'identifier' => 'oai:episciences.org:jdmdh:123',
            'metadataPrefix' => 'invalid_format'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="cannotDisseminateFormat">', $responseContent);
    }

    public function testGetRecordWithoutPreRenderedMetadataReturnsCannotDisseminateFormat(): void
    {
        $client = static::createClient();

        // The Solr document exists but holds no pre-rendered TEI XML
        $documentStub = $this->createSolrDocumentStub([
            'docid' => 123,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(1, [$documentStub])
        ));

        $client->request('GET', '/', [
            'verb' => 'GetRecord',
            'identifier' => 'oai:episciences.org:jdmdh:123',
            'metadataPrefix' => 'tei'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="cannotDisseminateFormat">', $responseContent);
    }

    public function testListRecordsSuccess(): void
    {
        $client = static::createClient();

        $documentStub = $this->createSolrDocumentStub([
            'docid' => 456,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
            'doc_dc' => self::DC_XML,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(1, [$documentStub])
        ));

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:456</identifier>', $responseContent);
        $this->assertStringContainsString('<dc:title>Test Title</dc:title>', $responseContent);
    }

    public function testListIdentifiersSuccess(): void
    {
        $client = static::createClient();

        $documentStub = $this->createSolrDocumentStub([
            'docid' => 456,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(1, [$documentStub])
        ));

        $client->request('GET', '/', [
            'verb' => 'ListIdentifiers',
            'metadataPrefix' => 'oai_dc'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<ListIdentifiers>', $responseContent);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:456</identifier>', $responseContent);
        $this->assertStringNotContainsString('<metadata', $responseContent);
    }

    public function testListRecordsWithIllegalArgumentReturnsBadArgument(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc',
            'foo' => 'bar'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badArgument">', $responseContent);
    }

    public function testListRecordsWithArrayParameterReturnsBadArgument(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc',
            'from' => ['2026-01-01']
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badArgument">', $responseContent);
    }

    public function testListRecordsWithUnknownSetReturnsBadArgument(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc',
            'set' => 'nonexistent'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badArgument">', $responseContent);
    }

    public function testListRecordsWithOpenaireSetReturnsAllRecords(): void
    {
        $client = static::createClient();

        $documentStub = $this->createSolrDocumentStub([
            'docid' => 456,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
            'doc_dc' => self::DC_XML,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(1, [$documentStub])
        ));

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc',
            'set' => 'openaire'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:456</identifier>', $responseContent);
    }

    public function testListRecordsWithoutMatchReturnsNoRecordsMatch(): void
    {
        $client = static::createClient();

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(0, [])
        ));

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'metadataPrefix' => 'oai_dc',
            'set' => 'journal:emptyjournal'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="noRecordsMatch">', $responseContent);
    }

    public function testListRecordsPaginationWithResumptionToken(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $firstPageDocument = $this->createSolrDocumentStub([
            'docid' => 2,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
            'doc_dc' => self::DC_XML,
        ]);
        $secondPageDocument = $this->createSolrDocumentStub([
            'docid' => 1,
            'revue_code_t' => 'jdmdh',
            'publication_date_tdate' => self::TEST_DATE,
            'doc_dc' => self::DC_XML,
        ]);

        static::getContainer()->set(Client::class, $this->createSolrClientStub(
            $this->createSelectResultStub(2, [$firstPageDocument], 'AoE/cursor+page2=='),
            $this->createSelectResultStub(2, [$secondPageDocument], 'AoE/cursor+end==')
        ));

        // First page: an opaque URL-safe token is issued, not the raw Solr cursorMark
        $client->request('GET', '/', ['verb' => 'ListRecords', 'metadataPrefix' => 'oai_dc']);

        $this->assertResponseIsSuccessful();
        $firstPage = $client->getResponse()->getContent();
        $this->assertIsString($firstPage);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:2</identifier>', $firstPage);
        $this->assertMatchesRegularExpression('#<resumptionToken[^>]*completeListSize="2" cursor="0">[a-f0-9]{64}</resumptionToken>#', $firstPage);

        preg_match('#<resumptionToken[^>]*>([a-f0-9]{64})</resumptionToken>#', $firstPage, $matches);
        $token = $matches[1];

        // Second page: resume with the token, list ends with an empty resumptionToken
        $client->request('GET', '/', ['verb' => 'ListRecords', 'resumptionToken' => $token]);

        $this->assertResponseIsSuccessful();
        $secondPage = $client->getResponse()->getContent();
        $this->assertIsString($secondPage);
        $this->assertStringContainsString('<identifier>oai:episciences.org:jdmdh:1</identifier>', $secondPage);
        $this->assertStringContainsString('<resumptionToken completeListSize="2" cursor="1"/>', $secondPage);
    }

    public function testListRecordsWithInvalidResumptionTokenReturnsBadResumptionToken(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'resumptionToken' => str_repeat('0', 64)
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badResumptionToken">', $responseContent);
    }

    public function testListRecordsWithResumptionTokenAndOtherArgumentsReturnsBadArgument(): void
    {
        $client = static::createClient();

        $client->request('GET', '/', [
            'verb' => 'ListRecords',
            'resumptionToken' => str_repeat('0', 64),
            'metadataPrefix' => 'oai_dc'
        ]);

        $this->assertResponseIsSuccessful();
        $responseContent = $client->getResponse()->getContent();
        $this->assertIsString($responseContent);
        $this->assertStringContainsString('<error code="badArgument">', $responseContent);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSolrDocumentStub(array $data): SelectDocument
    {
        $documentStub = $this->createStub(SelectDocument::class);
        $documentStub->method('offsetExists')->willReturnCallback(
            static fn (mixed $offset): bool => array_key_exists($offset, $data)
        );
        $documentStub->method('offsetGet')->willReturnCallback(
            static fn (mixed $offset): mixed => $data[$offset] ?? null
        );

        return $documentStub;
    }

    private function createSolrClientStub(SelectResult ...$results): Client
    {
        $solariumClientMock = $this->createStub(Client::class);
        $solariumClientMock->method('createQuery')->willReturn($this->createStub(SelectQuery::class));
        $solariumClientMock->method('select')->willReturnOnConsecutiveCalls(...array_values($results));

        return $solariumClientMock;
    }

    /**
     * @param array<int, SelectDocument> $documents
     */
    private function createSelectResultStub(int $numFound, array $documents, string $nextCursorMark = 'cursor'): SelectResult
    {
        $resultStub = $this->createStub(SelectResult::class);
        $resultStub->method('getNumFound')->willReturn($numFound);
        $resultStub->method('getDocuments')->willReturn($documents);
        $resultStub->method('getNextCursorMark')->willReturn($nextCursorMark);

        return $resultStub;
    }
}
