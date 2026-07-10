<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\MetadataFormat;
use App\Exception\OaiException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Solarium\Client;
use Solarium\QueryType\Select\Query\Query;
use DateTime;

/**
 * @phpstan-type ParsedListParams array{
 *     metadataFormat: MetadataFormat,
 *     from: string|null,
 *     until: string|null,
 *     set: string|null,
 *     cursor: int,
 *     solrCursorMark: string,
 * }
 */
class OaiQueryHelper
{
    private const string DATE_FORMAT_ISO = 'Y-m-d\TH:i:s\Z';
    private const string ERROR_BAD_TOKEN = 'The resumptionToken is invalid or has expired.';
    private const string TOKEN_CACHE_PREFIX = 'oai-token-';

    /**
     * Sets that deliberately match the whole repository: Episciences publishes
     * exclusively in Diamond Open Access, so every record belongs to the
     * OpenAIRE and Open Access DRIVERset sets (same behaviour as the legacy
     * application). The "journal" set is the parent of all journal sub-sets.
     */
    private const array NON_FILTERING_SETS = ['journal', 'openaire', 'driver'];

    private Client $solrClient;
    private CacheItemPoolInterface $cache;

    public function __construct(Client $solrClient, CacheItemPoolInterface $cache)
    {
        $this->solrClient = $solrClient;
        $this->cache = $cache;
    }

    /**
     * @param array<string, mixed> $params
     * @return ParsedListParams
     * @throws OaiException
     * @throws InvalidArgumentException
     */
    public function parseListParameters(array $params): array
    {
        $resumptionToken = $params['resumptionToken'] ?? null;
        if (is_string($resumptionToken)) {
            return $this->parseResumptionToken($resumptionToken, $params);
        }

        return $this->parseQueryParameters($params);
    }

    /**
     * Store the token configuration and return an opaque URL-safe token.
     *
     * @param array<string, mixed> $tokenConf
     * @throws InvalidArgumentException
     */
    public function storeResumptionToken(array $tokenConf, int $expirationTime): string
    {
        $token = hash('sha256', (string) json_encode($tokenConf));

        $cacheItem = $this->cache->getItem(self::TOKEN_CACHE_PREFIX . $token);
        $cacheItem->set($tokenConf)->expiresAfter($expirationTime);
        $this->cache->save($cacheItem);

        return $token;
    }

    /**
     * @param array<string, mixed> $params
     * @return ParsedListParams
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

        if (preg_match('/^[a-f0-9]{64}$/', $resumptionToken) !== 1) {
            throw new OaiException('badResumptionToken', self::ERROR_BAD_TOKEN);
        }

        $cacheItem = $this->cache->getItem(self::TOKEN_CACHE_PREFIX . $resumptionToken);
        if (!$cacheItem->isHit()) {
            throw new OaiException('badResumptionToken', self::ERROR_BAD_TOKEN);
        }

        $conf = $cacheItem->get();
        if (!is_array($conf) || !is_string($conf['cursorMark'] ?? null)) {
            throw new OaiException('badResumptionToken', self::ERROR_BAD_TOKEN);
        }

        $metadataFormat = MetadataFormat::tryFrom((string) ($conf['metadataPrefix'] ?? ''));
        if ($metadataFormat === null) {
            throw new OaiException('badResumptionToken', self::ERROR_BAD_TOKEN);
        }

        return [
            'metadataFormat' => $metadataFormat,
            'from' => isset($conf['from']) ? (string) $conf['from'] : null,
            'until' => isset($conf['until']) ? (string) $conf['until'] : null,
            'set' => isset($conf['set']) ? (string) $conf['set'] : null,
            'cursor' => (int) ($conf['cursor'] ?? 0),
            'solrCursorMark' => $conf['cursorMark'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return ParsedListParams
     * @throws OaiException
     */
    private function parseQueryParameters(array $params): array
    {
        $metadataPrefix = $params['metadataPrefix'] ?? null;
        $from = $params['from'] ?? null;
        $until = $params['until'] ?? null;
        $set = $params['set'] ?? null;

        if (!is_string($metadataPrefix)) {
            throw new OaiException('badArgument', 'Missing metadataPrefix.');
        }
        $metadataFormat = MetadataFormat::tryFrom($metadataPrefix);
        if ($metadataFormat === null) {
            throw new OaiException('cannotDisseminateFormat', sprintf('The metadata format %s is not supported.', $metadataPrefix));
        }

        if ($from !== null && (!is_string($from) || !$this->validateDate($from))) {
            throw new OaiException('badArgument', 'Invalid from date format.');
        }
        if ($until !== null && (!is_string($until) || !$this->validateDate($until))) {
            throw new OaiException('badArgument', 'Invalid until date format.');
        }
        if ($from !== null && $until !== null && strlen($from) !== strlen($until)) {
            throw new OaiException('badArgument', 'from and until parameters must have the same granularity.');
        }

        if ($set !== null) {
            if (!is_string($set)) {
                throw new OaiException('badArgument', 'Invalid set argument.');
            }
            $this->validateSet($set);
        }

        return [
            'metadataFormat' => $metadataFormat,
            'from' => $from,
            'until' => $until,
            'set' => $set,
            'cursor' => 0,
            'solrCursorMark' => '*',
        ];
    }

    /**
     * @throws OaiException
     */
    private function validateSet(string $set): void
    {
        if (in_array($set, self::NON_FILTERING_SETS, true)) {
            return;
        }
        if (str_starts_with($set, 'journal:') && strlen($set) > strlen('journal:')) {
            return;
        }

        throw new OaiException('badArgument', sprintf('The set %s does not exist.', $set));
    }

    /**
     * @param ParsedListParams $parsedParams
     */
    public function buildListQuery(array $parsedParams, string $verb): Query
    {
        /** @var Query $query */
        $query = $this->solrClient->createQuery($this->solrClient::QUERY_SELECT);

        $from = $parsedParams['from'];
        $until = $parsedParams['until'];
        $set = $parsedParams['set'];
        $solrCursorMark = $parsedParams['solrCursorMark'];
        $metadataFormat = $parsedParams['metadataFormat'];

        $fq = [];
        $dateFilter = $this->buildDateFilter($from, $until);
        if ($dateFilter !== null) {
            $fq[] = $dateFilter;
        }

        // Only journal:<code> sets filter the result; see NON_FILTERING_SETS.
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
        $fields = ['docid', 'revue_code_t', 'publication_date_tdate'];
        if ($verb !== 'ListIdentifiers') {
            $fields[] = $metadataFormat->solrField();
        }
        $query->setFields($fields);

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
