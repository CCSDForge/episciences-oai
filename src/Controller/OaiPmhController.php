<?php

declare(strict_types=1);


namespace App\Controller;

use DOMException;
use Psr\Cache\InvalidArgumentException;
use Solarium\Client;
use Solarium\Component\Result\Facet\Pivot\Pivot;
use Solarium\QueryType\Select\Query\Query;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use App\Enum\MetadataFormat;
use App\Exception\OaiException;
use App\Service\EpisciencesApiClient;
use App\Service\OaiQueryHelper;
use DOMDocument;
use DOMElement;
use Throwable;

use Solarium\Core\Query\DocumentInterface;

/**
 * @phpstan-import-type ParsedListParams from OaiQueryHelper
 */
class OaiPmhController extends AbstractController
{
    private const string ERROR_RECORD_NOT_FOUND = 'Record does not exist.';
    private const string NS_XMLNS = 'http://www.w3.org/2000/xmlns/';
    private const string NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    private const string DATE_FORMAT_ISO = 'Y-m-d\TH:i:s\Z';
    private const string CONTENT_TYPE_XML = 'text/xml; charset=utf-8';

    private Client $solrClient;
    private CacheItemPoolInterface $cache;
    private LoggerInterface $logger;
    private EpisciencesApiClient $apiClient;
    private OaiQueryHelper $queryHelper;

    public function __construct(
        Client $solrClient,
        CacheItemPoolInterface $cache,
        LoggerInterface $logger,
        EpisciencesApiClient $apiClient,
        OaiQueryHelper $queryHelper
    ) {
        $this->solrClient = $solrClient;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->apiClient = $apiClient;
        $this->queryHelper = $queryHelper;
    }

    /**
     * @throws InvalidArgumentException|DOMException
     */
    #[Route('/', name: 'oai_pmh_root', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $params = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $verb = $params['verb'] ?? null;
        if (!is_string($verb)) {
            $verb = null;
        }

        $xml = new DOMDocument('1.0', 'utf-8');
        $xml->formatOutput = true;

        $xml->appendChild($xml->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/oai/xsl"'));

        $root = $xml->createElement('OAI-PMH');
        $root->setAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/');
        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/ http://www.openarchives.org/OAI/2.0/OAI-PMH.xsd');
        $xml->appendChild($root);

        $root->appendChild($xml->createElement('responseDate', gmdate(self::DATE_FORMAT_ISO)));

        $requestNode = $xml->createElement('request', $request->getSchemeAndHttpHost() . $request->getPathInfo());
        foreach ($params as $k => $v) {
            if (is_string($v) && $v !== '') {
                $requestNode->setAttribute($k, $v);
            }
        }
        $root->appendChild($requestNode);

        if (!$verb) {
            $response = $this->createErrorResponse($xml, 'badVerb', 'Missing OAI-PMH verb.');
        } else {
            try {
                switch ($verb) {
                    case 'Identify':
                        $this->handleIdentify($xml, $root, $request->getSchemeAndHttpHost() . $request->getPathInfo(), $params);
                        break;

                    case 'ListMetadataFormats':
                        $this->handleListMetadataFormats($xml, $root, $params);
                        break;

                    case 'ListSets':
                        $this->handleListSets($xml, $root, $params);
                        break;

                    case 'ListIdentifiers':
                    case 'ListRecords':
                        $this->handleList($xml, $root, $verb, $params);
                        break;

                    case 'GetRecord':
                        $this->handleGetRecord($xml, $root, $params);
                        break;

                    default:
                        throw new OaiException('badVerb', 'Illegal OAI-PMH verb.');
                }
                $response = new Response($xml->saveXML(), Response::HTTP_OK, [
                    'Content-Type' => self::CONTENT_TYPE_XML
                ]);
            } catch (OaiException $e) {
                $response = $this->createErrorResponse($xml, $e->oaiCode, $e->getMessage());
            } catch (Throwable $e) {
                $this->logger->error(
                    sprintf('Unhandled error while processing OAI-PMH verb %s: %s', $verb, $e->getMessage()),
                    ['exception' => $e]
                );
                $response = new Response('Service temporarily unavailable.', Response::HTTP_SERVICE_UNAVAILABLE, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Retry-After' => '60',
                ]);
            }
        }

        return $response;
    }

    #[Route('/xsl', name: 'oai_pmh_xsl_legacy', methods: ['GET'])]
    #[Route('/oai/xsl', name: 'oai_pmh_xsl', methods: ['GET'])]
    public function xsl(): Response
    {
        $xslPath = $this->getParameter('kernel.project_dir') . '/config/oai2.xsl';
        if (!file_exists($xslPath)) {
            throw $this->createNotFoundException('Stylesheet not found');
        }

        $content = file_get_contents($xslPath);

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => self::CONTENT_TYPE_XML
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @throws OaiException|DOMException
     */
    private function handleIdentify(DOMDocument $xml, DOMElement $root, string $baseUrl, array $params): void
    {
        $this->validateParams($params, []);

        $identify = $xml->createElement('Identify');
        $identify->appendChild($xml->createElement('repositoryName', 'Episciences.org'));
        $identify->appendChild($xml->createElement('baseURL', $baseUrl));
        $identify->appendChild($xml->createElement('protocolVersion', '2.0'));
        $identify->appendChild($xml->createElement('adminEmail', 'contact@episciences.org'));
        $identify->appendChild($xml->createElement('earliestDatestamp', '1978-01-01T00:00:00Z'));
        $identify->appendChild($xml->createElement('deletedRecord', 'no'));
        $identify->appendChild($xml->createElement('granularity', 'YYYY-MM-DDThh:mm:ssZ'));

        // description: oai-identifier
        $desc1 = $xml->createElement('description');
        $oaiId = $xml->createElement('oai-identifier');
        $oaiId->setAttribute('xmlns', 'http://www.openarchives.org/OAI/2.0/oai-identifier');
        $oaiId->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/oai-identifier http://www.openarchives.org/OAI/2.0/oai-identifier.xsd');
        $oaiId->appendChild($xml->createElement('scheme', 'oai'));
        $oaiId->appendChild($xml->createElement('repositoryIdentifier', 'episciences.org'));
        $oaiId->appendChild($xml->createElement('delimiter', ':'));
        $oaiId->appendChild($xml->createElement('sampleIdentifier', 'oai:episciences.org:jdmdh:1'));
        $desc1->appendChild($oaiId);
        $identify->appendChild($desc1);

        // description: eprints
        $desc2 = $xml->createElement('description');
        $eprints = $xml->createElement('eprints');
        $eprints->setAttribute('xmlns', 'http://www.openarchives.org/OAI/1.1/eprints');
        $eprints->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.openarchives.org/OAI/1.1/eprints http://www.openarchives.org/OAI/1.1/eprints.xsd');

        $content = $xml->createElement('content');
        $content->appendChild($xml->createElement('text', 'Episciences is an overlay journal platform'));
        $eprints->appendChild($content);

        $metadataPolicy = $xml->createElement('metadataPolicy');
        $metadataPolicy->appendChild($xml->createElement('text', '1) CC0: https://creativecommons.org/publicdomain/zero/1.0/'));
        $eprints->appendChild($metadataPolicy);

        $dataPolicy = $xml->createElement('dataPolicy');
        $dataPolicy->appendChild($xml->createElement('text'));
        $eprints->appendChild($dataPolicy);

        $desc2->appendChild($eprints);
        $identify->appendChild($desc2);

        $root->appendChild($identify);
    }

    /**
     * @param array<string, mixed> $params
     * @throws OaiException|DOMException
     */
    private function handleListMetadataFormats(DOMDocument $xml, DOMElement $root, array $params): void
    {
        $this->validateParams($params, ['identifier']);

        $identifier = $params['identifier'] ?? null;

        if (is_string($identifier)) {
            $parts = explode(':', $identifier);
            $docid = end($parts);
            if (!ctype_digit($docid)) {
                throw new OaiException('idDoesNotExist', 'Invalid OAI identifier format.');
            }

            /** @var Query $query */
            $query = $this->solrClient->createQuery($this->solrClient::QUERY_SELECT);
            $query->setQuery(sprintf('docid:%d', $docid));
            $query->setRows(1);
            $resultset = $this->solrClient->select($query);
            if ($resultset->getNumFound() === 0) {
                throw new OaiException('idDoesNotExist', self::ERROR_RECORD_NOT_FOUND);
            }
        }

        $formats = $xml->createElement('ListMetadataFormats');

        foreach (MetadataFormat::cases() as $metadataFormat) {
            $format = $xml->createElement('metadataFormat');
            $format->appendChild($xml->createElement('metadataPrefix', $metadataFormat->value));
            $format->appendChild($xml->createElement('schema', $metadataFormat->schemaUrl()));
            $format->appendChild($xml->createElement('metadataNamespace', $metadataFormat->namespaceUri()));
            $formats->appendChild($format);
        }

        $root->appendChild($formats);
    }

    /**
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException
     * @throws OaiException|DOMException
     */
    private function handleListSets(DOMDocument $xml, DOMElement $root, array $params): void
    {
        $this->validateParams($params, ['resumptionToken']);

        if (isset($params['resumptionToken'])) {
            throw new OaiException('badResumptionToken', 'ListSets does not support resumptionToken in this implementation.');
        }

        $listSetsNode = $xml->createElement('ListSets');

        $listSetsNode->appendChild($this->createSetNode($xml, 'journal', 'All journals'));
        $listSetsNode->appendChild($this->createSetNode($xml, 'openaire', 'OpenAIRE'));
        $listSetsNode->appendChild($this->createSetNode($xml, 'driver', 'Open Access DRIVERset'));

        $facetData = $this->fetchSetsFacetData();
        foreach ($facetData as $item) {
            $listSetsNode->appendChild($this->createSetNode($xml, 'journal:' . $item['code'], $item['title'], $item));
        }

        $root->appendChild($listSetsNode);
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException
     */
    private function fetchSetsFacetData(): array
    {
        $cacheItem = $this->cache->getItem('oai-sets-facet-data');
        if ($cacheItem->isHit()) {
            return (array)$cacheItem->get();
        }

        /** @var Query $query */
        $query = $this->solrClient->createQuery($this->solrClient::QUERY_SELECT);
        $query->setQuery('*:*');
        $query->setRows(0);
        $query->getFacetSet()->createFacetPivot('journal_pivot')->addFields('revue_code_t,revue_title_s');

        $resultset = $this->solrClient->select($query);
        /** @var Pivot|null $facetPivot */
        $facetPivot = $resultset->getFacetSet()->getFacet('journal_pivot');

        $facetData = [];
        if ($facetPivot !== null) {
            $titles = [];
            foreach ($facetPivot as $pivotItem) {
                $code = (string)$pivotItem->getValue();
                $title = $code;
                $nestedPivot = $pivotItem->getPivot();
                if (!empty($nestedPivot)) {
                    $title = (string)$nestedPivot[0]->getValue();
                }
                $titles[$code] = $title;
            }

            $apiData = $this->apiClient->fetchJournalsMetadata(array_keys($titles));
            foreach ($titles as $code => $title) {
                $facetData[] = $apiData[$code] ?? [
                    'code' => $code,
                    'title' => $title,
                    'description' => null,
                    'publisher' => null,
                    'date' => null,
                    'issn' => null,
                    'subjects' => [],
                ];
            }
        }

        $cacheItem->set($facetData)->expiresAfter(86400); // 24 hours
        $this->cache->save($cacheItem);

        return $facetData;
    }

    /**
     * @param array<string, mixed>|null $metadata
     * @throws DOMException
     */
    private function createSetNode(DOMDocument $xml, string $spec, string $name, ?array $metadata = null): DOMElement
    {
        $setNode = $xml->createElement('set');
        $setNode->appendChild($xml->createElement('setSpec', $spec));

        $setNameNode = $xml->createElement('setName');
        $setNameNode->appendChild($xml->createTextNode($name));
        $setNode->appendChild($setNameNode);

        if ($metadata === null) {
            return $setNode;
        }

        $hasMetadata = !empty($metadata['description']) || !empty($metadata['publisher']) ||
                       !empty($metadata['date']) || !empty($metadata['issn']) || !empty($metadata['subjects']);

        if ($hasMetadata) {
            $this->appendSetDescription($xml, $setNode, $name, $metadata);
        }

        return $setNode;
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws DOMException
     */
    private function appendSetDescription(DOMDocument $xml, DOMElement $setNode, string $name, array $metadata): void
    {
        $setDescription = $xml->createElement('setDescription');
        $setDescription->setAttributeNS(self::NS_XMLNS, 'xmlns:xsi', self::NS_XSI);

        $nsOaiDc = 'http://www.openarchives.org/OAI/2.0/oai_dc/';
        $nsDc = 'http://purl.org/dc/elements/1.1/';

        $oaiDcNode = $xml->createElementNS($nsOaiDc, 'oai_dc:dc');
        $oaiDcNode->setAttributeNS(self::NS_XMLNS, 'xmlns:oai_dc', $nsOaiDc);
        $oaiDcNode->setAttributeNS(self::NS_XMLNS, 'xmlns:dc', $nsDc);
        $oaiDcNode->setAttributeNS(self::NS_XMLNS, 'xmlns:xsi', self::NS_XSI);
        $oaiDcNode->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.openarchives.org/OAI/2.0/oai_dc/ http://www.openarchives.org/OAI/2.0/oai_dc.xsd');

        $dcTitle = $xml->createElementNS($nsDc, 'dc:title');
        $dcTitle->appendChild($xml->createTextNode($name));
        $oaiDcNode->appendChild($dcTitle);

        $fields = [
            'publisher' => 'dc:publisher',
            'date' => 'dc:date',
            'description' => 'dc:description',
        ];

        foreach ($fields as $key => $tag) {
            if (!empty($metadata[$key])) {
                $node = $xml->createElementNS($nsDc, $tag);
                $node->appendChild($xml->createTextNode((string)$metadata[$key]));
                $oaiDcNode->appendChild($node);
            }
        }

        if (!empty($metadata['subjects'])) {
            foreach ($metadata['subjects'] as $subject) {
                $node = $xml->createElementNS($nsDc, 'dc:subject');
                $node->appendChild($xml->createTextNode((string)$subject));
                $oaiDcNode->appendChild($node);
            }
        }

        if (!empty($metadata['issn'])) {
            $node = $xml->createElementNS($nsDc, 'dc:identifier');
            $node->appendChild($xml->createTextNode('urn:ISSN:' . $metadata['issn']));
            $oaiDcNode->appendChild($node);
        }

        $setDescription->appendChild($oaiDcNode);
        $setNode->appendChild($setDescription);
    }

    /**
     * @param array<string, mixed> $params
     * @throws InvalidArgumentException
     * @throws OaiException|DOMException
     */
    private function handleList(DOMDocument $xml, DOMElement $root, string $verb, array $params): void
    {
        $this->validateParams($params, ['metadataPrefix', 'from', 'until', 'set', 'resumptionToken']);

        $parsedParams = $this->queryHelper->parseListParameters($params);

        $query = $this->queryHelper->buildListQuery($parsedParams, $verb);

        $resultset = $this->solrClient->select($query);
        $numFound = $resultset->getNumFound();
        $nextCursorMark = $resultset->getNextCursorMark();
        $docs = $resultset->getDocuments();

        $cursor = $parsedParams['cursor'];

        if ($numFound === 0 || (count($docs) === 0 && $cursor === 0)) {
            throw new OaiException('noRecordsMatch', 'No records match the query.');
        }

        $listNode = $xml->createElement($verb);
        foreach ($docs as $document) {
            $this->appendSingleRecordToXml($xml, $listNode, $verb, $parsedParams['metadataFormat'], $document);
        }
        $root->appendChild($listNode);

        $this->handleResumptionToken($xml, $root, $parsedParams, $numFound, $cursor, count($docs), (string)$nextCursorMark);
    }

    /**
     * @throws DOMException
     */
    private function appendSingleRecordToXml(DOMDocument $xml, DOMElement $listNode, string $verb, MetadataFormat $metadataFormat, mixed $document): void
    {
        $docid = $document['docid'] ?? null;
        $revueCode = $document['revue_code_t'] ?? 'unknown';
        $pubDate = $document['publication_date_tdate'] ?? '';

        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('identifier', sprintf('oai:episciences.org:%s:%s', $revueCode, $docid)));
        $header->appendChild($xml->createElement('datestamp', substr($pubDate, 0, 10)));
        $header->appendChild($xml->createElement('setSpec', 'journal'));
        $header->appendChild($xml->createElement('setSpec', 'journal:' . $revueCode));

        if ($verb === 'ListIdentifiers') {
            $listNode->appendChild($header);
            return;
        }

        $record = $xml->createElement('record');
        $record->appendChild($header);

        if (!$this->appendMetadata($xml, $record, $metadataFormat, $document, (int)$docid)) {
            // A record without <metadata> would be invalid against the OAI-PMH schema.
            $this->logger->warning(sprintf('Skipping document %s: no valid %s metadata available', (string)$docid, $metadataFormat->value));
            return;
        }

        $listNode->appendChild($record);
    }

    /**
     * Append the pre-rendered metadata to the record.
     * Returns false when the Solr document holds no valid XML for the requested format.
     *
     * @throws DOMException
     */
    private function appendMetadata(
        DOMDocument $xml,
        DOMElement $record,
        MetadataFormat $metadataFormat,
        DocumentInterface $document,
        int $docid
    ): bool {
        $metadataXml = $document[$metadataFormat->solrField()] ?? '';

        if (!is_string($metadataXml) || $metadataXml === '') {
            return false;
        }

        $fragment = $xml->createDocumentFragment();
        if (!@$fragment->appendXML($metadataXml)) {
            $this->logger->error(sprintf('Malformed XML metadata detected for document %d (format: %s)', $docid, $metadataFormat->value));
            return false;
        }

        $metadata = $xml->createElement('metadata');
        foreach ($metadataFormat->wrapperNamespaces() as $prefix => $namespaceUri) {
            $metadata->setAttributeNS(self::NS_XMLNS, 'xmlns:' . $prefix, $namespaceUri);
        }
        $metadata->appendChild($fragment);
        $record->appendChild($metadata);

        return true;
    }

    /**
     * @param ParsedListParams $parsedParams
     * @throws InvalidArgumentException|DOMException
     */
    private function handleResumptionToken(
        DOMDocument $xml,
        DOMElement $root,
        array $parsedParams,
        int $numFound,
        int $cursor,
        int $docsCount,
        string $nextCursorMark
    ): void {
        $isResumedRequest = $parsedParams['solrCursorMark'] !== '*';

        $nextCursor = $cursor + $docsCount;

        if ($numFound > $nextCursor && $docsCount > 0) {
            $expirationTime = 3600;
            $expirationDate = gmdate(self::DATE_FORMAT_ISO, time() + $expirationTime);

            $tokenConf = [
                'metadataPrefix' => $parsedParams['metadataFormat']->value,
                'from' => $parsedParams['from'],
                'until' => $parsedParams['until'],
                'set' => $parsedParams['set'],
                'cursor' => $nextCursor,
                'cursorMark' => $nextCursorMark,
            ];

            // Expose an opaque URL-safe token instead of the raw Solr cursorMark,
            // which may contain characters (+, =) broken by URL decoding.
            $token = $this->queryHelper->storeResumptionToken($tokenConf, $expirationTime);

            $resumptionTokenNode = $xml->createElement('resumptionToken', $token);
            $resumptionTokenNode->setAttribute('expirationDate', $expirationDate);
            $resumptionTokenNode->setAttribute('completeListSize', (string)$numFound);
            $resumptionTokenNode->setAttribute('cursor', (string)$cursor);
            $root->appendChild($resumptionTokenNode);
        } elseif ($isResumedRequest) {
            $resumptionTokenNode = $xml->createElement('resumptionToken');
            $resumptionTokenNode->setAttribute('completeListSize', (string)$numFound);
            $resumptionTokenNode->setAttribute('cursor', (string)$cursor);
            $root->appendChild($resumptionTokenNode);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @throws OaiException|DOMException
     */
    private function handleGetRecord(DOMDocument $xml, DOMElement $root, array $params): void
    {
        $this->validateParams($params, ['identifier', 'metadataPrefix']);

        $identifier = $params['identifier'] ?? null;
        $metadataPrefix = $params['metadataPrefix'] ?? null;

        if (!is_string($identifier) || $identifier === '' || !is_string($metadataPrefix) || $metadataPrefix === '') {
            throw new OaiException('badArgument', 'Missing identifier or metadataPrefix.');
        }

        $metadataFormat = MetadataFormat::tryFrom($metadataPrefix);
        if ($metadataFormat === null) {
            throw new OaiException('cannotDisseminateFormat', sprintf('The metadata format %s is not supported.', $metadataPrefix));
        }

        $parts = explode(':', $identifier);
        $docid = end($parts);

        if (!ctype_digit($docid)) {
            throw new OaiException('badArgument', 'Invalid OAI identifier.');
        }

        $docidInt = (int)$docid;
        $document = $this->fetchSolrDocument($docidInt, $metadataFormat);
        $revueCode = $document['revue_code_t'] ?? 'unknown';
        $pubDate = $document['publication_date_tdate'] ?? '';

        $getRecord = $xml->createElement('GetRecord');
        $record = $xml->createElement('record');

        $header = $xml->createElement('header');
        $header->appendChild($xml->createElement('identifier', $identifier));
        $header->appendChild($xml->createElement('datestamp', substr($pubDate, 0, 10)));
        $header->appendChild($xml->createElement('setSpec', 'journal'));
        $header->appendChild($xml->createElement('setSpec', 'journal:' . $revueCode));
        $record->appendChild($header);

        if (!$this->appendMetadata($xml, $record, $metadataFormat, $document, $docidInt)) {
            throw new OaiException('cannotDisseminateFormat', sprintf('The item does not provide metadata in the %s format.', $metadataPrefix));
        }

        $getRecord->appendChild($record);
        $root->appendChild($getRecord);
    }

    /**
     * @throws OaiException
     */
    private function fetchSolrDocument(int $docid, MetadataFormat $metadataFormat): DocumentInterface
    {
        /** @var Query $query */
        $query = $this->solrClient->createQuery($this->solrClient::QUERY_SELECT);
        $query->setQuery(sprintf('docid:%d', $docid));
        $query->setRows(1);
        $query->setFields(['docid', 'revue_code_t', 'publication_date_tdate', $metadataFormat->solrField()]);

        $resultset = $this->solrClient->select($query);

        if ($resultset->getNumFound() === 0) {
            throw new OaiException('idDoesNotExist', self::ERROR_RECORD_NOT_FOUND);
        }

        $documents = $resultset->getDocuments();
        if (empty($documents)) {
            throw new OaiException('idDoesNotExist', self::ERROR_RECORD_NOT_FOUND);
        }

        return $documents[0];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $allowedParams
     * @throws OaiException
     */
    private function validateParams(array $params, array $allowedParams): void
    {
        $allowed = array_merge(['verb'], $allowedParams);
        foreach ($params as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                throw new OaiException('badArgument', sprintf('Illegal argument: %s', $k));
            }
            if (!is_string($v)) {
                throw new OaiException('badArgument', sprintf('Argument %s must be a string.', $k));
            }
        }
    }

    /**
     * @throws DOMException
     */
    private function createErrorResponse(DOMDocument $xml, string $code, string $message): Response
    {
        $root = $xml->documentElement;

        // The OAI-PMH spec requires the <request> element to carry no attributes
        // when the error is badVerb or badArgument.
        if ($root !== null && in_array($code, ['badVerb', 'badArgument'], true)) {
            $requestNode = $root->getElementsByTagName('request')->item(0);
            if ($requestNode instanceof DOMElement) {
                foreach (iterator_to_array($requestNode->attributes) as $attribute) {
                    $requestNode->removeAttributeNode($attribute);
                }
            }
        }

        $error = $xml->createElement('error', $message);
        $error->setAttribute('code', $code);
        $root?->appendChild($error);

        return new Response($xml->saveXML(), Response::HTTP_OK, [
            'Content-Type' => self::CONTENT_TYPE_XML
        ]);
    }
}
