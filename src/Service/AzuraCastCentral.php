<?php

namespace App\Service;

use App\Entity;
use App\Environment;
use App\Version;
use Exception;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class AzuraCastCentral
{
    protected const BASE_URL = 'https://central.azuracast.com';

    protected Environment $environment;

    protected Client $httpClient;

    protected Entity\Repository\SettingsRepository $settingsRepo;

    protected Version $version;

    protected LoggerInterface $logger;

    public function __construct(
        Environment $environment,
        Version $version,
        Client $httpClient,
        LoggerInterface $logger,
        Entity\Repository\SettingsRepository $settingsRepo
    ) {
        $this->environment = $environment;
        $this->version = $version;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->settingsRepo = $settingsRepo;
    }

    /**
     * Ping the AzuraCast Central server for updates and return them if there are any.
     *
     * @return mixed[]|null
     */
    public function checkForUpdates(): ?array
    {
        $request_body = [
            'id' => $this->getUniqueIdentifier(),
            'is_docker' => $this->environment->isDocker(),
            'environment' => $this->environment->getAppEnvironment(),
            'release_channel' => $this->version->getReleaseChannel(),
        ];

        $commit_hash = $this->version->getCommitHash();
        if ($commit_hash) {
            $request_body['version'] = $commit_hash;
        } else {
            $request_body['release'] = Version::FALLBACK_VERSION;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::BASE_URL . '/api/update',
                ['json' => $request_body]
            );

            $update_data_raw = $response->getBody()->getContents();

            $update_data = json_decode($update_data_raw, true, 512, JSON_THROW_ON_ERROR);
            return $update_data['updates'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Error checking for updates: ' . $e->getMessage());
        }

        return null;
    }

    public function getUniqueIdentifier(): string
    {
        $settings = $this->settingsRepo->readSettings();
        $appUuid = $settings->getAppUniqueIdentifier();

        if (empty($appUuid)) {
            $appUuid = Uuid::uuid4()->toString();

            $settings->setAppUniqueIdentifier($appUuid);
            $this->settingsRepo->writeSettings($settings);
        }

        return $appUuid;
    }

    /**
     * Ping the AzuraCast Central server to retrieve this installation's likely public-facing IP.
     *
     * @param bool $cached
     */
    public function getIp(bool $cached = true): ?string
    {
        $settings = $this->settingsRepo->readSettings();
        $ip = ($cached)
            ? $settings->getExternalIp()
            : null;

        if (empty($ip)) {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    self::BASE_URL . '/ip'
                );

                $body_raw = $response->getBody()->getContents();
                $body = json_decode($body_raw, true, 512, JSON_THROW_ON_ERROR);

                $ip = $body['ip'] ?? null;
            } catch (Exception $e) {
                $this->logger->error('Could not fetch remote IP: ' . $e->getMessage());
                $ip = null;
            }

            if (!empty($ip) && $cached) {
                $settings->setExternalIp($ip);
                $this->settingsRepo->writeSettings($settings);
            }
        }

        return $ip;
    }
}
