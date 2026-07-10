<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class EpisciencesApiClient
{
    private string $apiUrl;
    private LoggerInterface $logger;
    private bool $verifySsl;
    private string $apiHost;
    private ?\GuzzleHttp\ClientInterface $httpClient;

    public function __construct(
        string $episciencesApiUrl,
        LoggerInterface $logger,
        bool $episciencesApiVerifySsl,
        string $episciencesApiHost,
        ?\GuzzleHttp\ClientInterface $httpClient = null
    ) {
        $this->apiUrl = $episciencesApiUrl;
        $this->logger = $logger;
        $this->verifySsl = $episciencesApiVerifySsl;
        $this->apiHost = $episciencesApiHost;
        $this->httpClient = $httpClient;
    }

    /**
     * Fetch journal metadata from Episciences API.
     *
     * @param string $code
     * @return array<string, mixed>|null
     */
    public function fetchJournalMetadata(string $code): ?array
    {
        $url = rtrim($this->apiUrl, '/') . '/api/journals/' . urlencode($code);

        $client = $this->httpClient;
        if ($client === null) {
            $headers = [
                'Accept' => 'application/ld+json',
                'User-Agent' => 'Episciences-OAI-Service',
                'X-Forwarded-Proto' => 'https',
            ];
            if ($this->apiHost !== '') {
                $headers['Host'] = $this->apiHost;
            }

            $client = new Client([
                'timeout' => 2.0,
                'verify' => $this->verifySsl,
                'headers' => $headers,
            ]);
        }

        $result = null;

        try {
            $response = $client->request('GET', $url);
            if ($response->getStatusCode() === 200) {
                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $result = $this->parseMetadata($data, $code);
                }
            }
        } catch (\Throwable $t) {
            $this->logger->error(sprintf('Error fetching metadata for journal %s: %s', $code, $t->getMessage()));
        }

        return $result;
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
