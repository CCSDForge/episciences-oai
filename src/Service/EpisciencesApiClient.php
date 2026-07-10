<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class EpisciencesApiClient
{
    private string $apiUrl;
    private LoggerInterface $logger;

    public function __construct(string $episciencesApiUrl, LoggerInterface $logger)
    {
        $this->apiUrl = $episciencesApiUrl;
        $this->logger = $logger;
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
        $client = new \GuzzleHttp\Client([
            'timeout' => 2.0,
            'headers' => [
                'Accept' => 'application/ld+json',
                'User-Agent' => 'Episciences-OAI-Service',
            ]
        ]);

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
