<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\OaiException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Solarium\Client;
use Solarium\QueryType\Select\Query\Query;
use DateTime;

class OaiQueryHelper
{
    private const string DATE_FORMAT_ISO = 'Y-m-d\TH:i:s\Z';

    private Client $solrClient;
    private CacheItemPoolInterface $cache;

    public function __construct(Client $solrClient, CacheItemPoolInterface $cache)
    {
        $this->solrClient = $solrClient;
        $this->cache = $cache;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $supportedFormats
     * @return array<string, mixed>
     * @throws OaiException
     * @throws InvalidArgumentException
     */
    public function parseListParameters(array $params, array $supportedFormats): array
    {
        $resumptionToken = $params['resumptionToken'] ?? null;
        if ($resumptionToken !== null) {
            return $this->parseResumptionToken($resumptionToken, $params);
        }

        return $this->parseQueryParameters($params, $supportedFormats);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OaiException
     * @throws InvalidArgumentException
     */
    private function parseResumptionToken(string $resumptionToken, array $params): array
    {
        $metadataPrefix = $params['metadataPrefix'] ?? null;
        $from = $params['from'] ?? null;
        $until = $params['until'] ?? null;
        $set = $params['set'] ?? null;

        if ($metadataPrefix !== null || $from !== null || $until !== null || $set !== null) {
            throw new OaiException('badArgument', 'resumptionToken cannot be combined with other arguments (metadataPrefix, from, until, set).');
        }

        $cacheItem = $this->cache->getItem('oai-token-' . hash('sha256', $resumptionToken));
        if (!$cacheItem->isHit()) {
            throw new OaiException('badResumptionToken', 'The resumptionToken is invalid or has expired.');
        }

        $conf = $cacheItem->get();
        if (!is_array($conf)) {
            throw new OaiException('badResumptionToken', 'The resumptionToken is invalid or has expired.');
        }

        return [
            'metadataPrefix' => $conf['metadataPrefix'],
            'from' => $conf['from'],
            'until' => $conf['until'],
            'set' => $conf['set'],
            'cursor' => $conf['cursor'],
            'solrCursorMark' => $resumptionToken,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, string> $supportedFormats
     * @return array<string, mixed>
     * @throws OaiException
     */
    private function parseQueryParameters(array $params, array $supportedFormats): array
    {
        $metadataPrefix = $params['metadataPrefix'] ?? null;
        $from = $params['from'] ?? null;
        $until = $params['until'] ?? null;
        $set = $params['set'] ?? null;

        if ($metadataPrefix === null) {
            throw new OaiException('badArgument', 'Missing metadataPrefix.');
        }
        if (!in_array($metadataPrefix, $supportedFormats, true)) {
            throw new OaiException('cannotDisseminateFormat', sprintf('The metadata format %s is not supported.', $metadataPrefix));
        }

        if ($from !== null && !$this->validateDate($from)) {
            throw new OaiException('badArgument', 'Invalid from date format.');
        }
        if ($until !== null && !$this->validateDate($until)) {
            throw new OaiException('badArgument', 'Invalid until date format.');
        }
        if ($from !== null && $until !== null && strlen($from) !== strlen($until)) {
            throw new OaiException('badArgument', 'from and until parameters must have the same granularity.');
        }

        return [
            'metadataPrefix' => $metadataPrefix,
            'from' => $from,
            'until' => $until,
            'set' => $set,
            'cursor' => 0,
            'solrCursorMark' => '*',
        ];
    }

    /**
     * @param array<string, mixed> $parsedParams
     */
    public function buildListQuery(array $parsedParams, string $verb): Query
    {
        /** @var Query $query */
        $query = $this->solrClient->createQuery($this->solrClient::QUERY_SELECT);

        $from = $parsedParams['from'];
        $until = $parsedParams['until'];
        $set = $parsedParams['set'];
        $solrCursorMark = $parsedParams['solrCursorMark'];
        $metadataPrefix = $parsedParams['metadataPrefix'];

        $fq = [];
        $dateFilter = $this->buildDateFilter($from, $until);
        if ($dateFilter !== null) {
            $fq[] = $dateFilter;
        }

        if ($set !== null && str_starts_with($set, 'journal:')) {
            $journalCode = substr($set, 8);
            $helper = $query->getHelper();
            $escapedJournalCode = $helper->escapeTerm($journalCode);
            $fq[] = sprintf('revue_code_t:%s', $escapedJournalCode);
        }

        if (!empty($fq)) {
            $query->setQuery(implode(' AND ', $fq));
        } else {
            $query->setQuery('*:*');
        }

        $query->addSort('docid', 'desc');
        $query->setRows(100);
        $query->setCursorMark($solrCursorMark);

        // Restrict fields returned by Solr
        if ($verb === 'ListIdentifiers') {
            $query->setFields(['docid', 'revue_code_t', 'publication_date_tdate']);
        } else {
            $fieldName = match ($metadataPrefix) {
                'oai_dc' => 'doc_dc',
                'tei' => 'doc_tei',
                'oai_openaire' => 'doc_openaire',
                'crossref' => 'doc_crossref',
                default => null,
            };
            $fields = ['docid', 'revue_code_t', 'publication_date_tdate'];
            if ($fieldName !== null) {
                $fields[] = $fieldName;
            }
            $query->setFields($fields);
        }

        return $query;
    }

    private function validateDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $d = DateTime::createFromFormat('Y-m-d', $date);
            return $d && $d->format('Y-m-d') === $date;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) {
            $d = DateTime::createFromFormat(self::DATE_FORMAT_ISO, $date);
            return $d && $d->format(self::DATE_FORMAT_ISO) === $date;
        }

        return false;
    }

    private function buildDateFilter(?string $from, ?string $until): ?string
    {
        if ($from === null && $until === null) {
            return null;
        }

        $solrFrom = '*';
        if ($from !== null) {
            $solrFrom = strlen($from) === 10 ? $from . 'T00:00:00Z' : $from;
        }

        $solrUntil = '*';
        if ($until !== null) {
            $solrUntil = strlen($until) === 10 ? $until . 'T23:59:59Z' : $until;
        }

        return sprintf('publication_date_tdate:[%s TO %s]', $solrFrom, $solrUntil);
    }
}
