<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class EpisciencesApiClient
{
    private const float REQUEST_TIMEOUT = 2.0;

    private string $apiUrl;
    private LoggerInterface $logger;
    private ClientInterface $httpClient;

    public function __construct(
        string $episciencesApiUrl,
        LoggerInterface $logger,
        bool $episciencesApiVerifySsl,
        string $episciencesApiHost,
        ?ClientInterface $httpClient = null
    ) {
        $this->apiUrl = $episciencesApiUrl;
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? $this->createDefaultClient($episciencesApiVerifySsl, $episciencesApiHost);
    }

    private function createDefaultClient(bool $verifySsl, string $apiHost): ClientInterface
    {
        $headers = [
            'Accept' => 'application/ld+json',
            'User-Agent' => 'Episciences-OAI-Service',
            'X-Forwarded-Proto' => 'https',
        ];
        if ($apiHost !== '') {
            $headers['Host'] = $apiHost;
        }

        return new Client([
            'timeout' => self::REQUEST_TIMEOUT,
            'verify' => $verifySsl,
            'headers' => $headers,
        ]);
    }

    /**
     * Fetch journal metadata from Episciences API.
     *
     * @param string $code
     * @return array<string, mixed>|null
     */
    public function fetchJournalMetadata(string $code): ?array
    {
        return $this->fetchJournalsMetadata([$code])[$code] ?? null;
    }

    /**
     * Fetch metadata for several journals with concurrent HTTP requests.
     * Failed lookups map to null.
     *
     * @param array<int, string> $codes
     * @return array<string, array<string, mixed>|null>
     */
    public function fetchJournalsMetadata(array $codes): array
    {
        $promises = [];
        foreach ($codes as $code) {
            $url = rtrim($this->apiUrl, '/') . '/api/journals/' . urlencode($code);
            $promises[$code] = $this->httpClient->requestAsync('GET', $url);
        }

        $results = [];
        foreach (Utils::settle($promises)->wait() as $code => $settled) {
            $results[$code] = null;

            if ($settled['state'] !== PromiseInterface::FULFILLED) {
                $reason = $settled['reason'];
                $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                $this->logger->error(sprintf('Error fetching metadata for journal %s: %s', $code, $message));
                continue;
            }

            /** @var ResponseInterface $response */
            $response = $settled['value'];
            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $data = json_decode($response->getBody()->getContents(), true);
            if (is_array($data)) {
                $results[$code] = $this->parseMetadata($data, (string) $code);
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $data
     * @param string $code
     * @return array<string, mixed>
     */
    private function parseMetadata(array $data, string $code): array
    {
        $title = $data['name'] ?? $code;
        $description = $this->getSettingValue($data, 'journalDescription');
        $publisher = $this->getSettingValue($data, 'journalPublisher');
        $date = $this->getSettingValue($data, 'journalCreationYear');
        $issn = $this->getSettingValue($data, 'ISSN');

        $subjects = [];
        $keywords = $this->getSettingValue($data, 'journalKeywords');
        if ($keywords) {
            $parsedSubjects = array_map('trim', explode(';', $keywords));
            $subjects = array_values(array_filter($parsedSubjects));
        }

        return [
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'publisher' => $publisher,
            'date' => $date,
            'issn' => $issn,
            'subjects' => $subjects,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param string $settingName
     * @return string|null
     */
    private function getSettingValue(array $data, string $settingName): ?string
    {
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            return null;
        }

        foreach ($settings as $setting) {
            if (isset($setting['setting'], $setting['value']) && $setting['setting'] === $settingName) {
                return (string)$setting['value'];
            }
        }

        return null;
    }
}
