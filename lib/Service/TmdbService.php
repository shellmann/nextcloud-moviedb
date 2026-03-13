<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

use OCA\MovieDB\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

class TmdbService {
    private const BASE_URL = 'https://api.themoviedb.org/3';
    public const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p';

    private IClientService $clientService;
    private IConfig $config;

    public function __construct(
        IClientService $clientService,
        IConfig $config
    ) {
        $this->clientService = $clientService;
        $this->config = $config;
    }

    private function getApiKey(?string $userId = null): string {
        // Check user-level API key first
        if ($userId !== null) {
            $userKey = $this->config->getUserValue($userId, Application::APP_ID, 'tmdb_api_key', '');
            if (!empty($userKey)) {
                return $userKey;
            }
        }
        // Fall back to app-level API key
        return $this->config->getAppValue(Application::APP_ID, 'tmdb_api_key', '');
    }

    public function hasApiKey(?string $userId = null): bool {
        return !empty($this->getApiKey($userId));
    }

    /**
     * @throws \Exception
     */
    public function searchMovies(string $query, ?int $year = null, int $page = 1, ?string $userId = null, string $language = 'en-US'): array {
        $apiKey = $this->getApiKey($userId);
        if (empty($apiKey)) {
            throw new \Exception('TMDB API key not configured. Please set your API key in settings.');
        }

        $client = $this->clientService->newClient();

        $params = [
            'query' => $query,
            'page' => $page,
            'language' => $language,
            'include_adult' => 'false',
        ];

        if ($year !== null) {
            $params['year'] = $year;
        }

        $response = $client->get(self::BASE_URL . '/search/movie', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            'query' => $params,
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * @throws \Exception
     */
    public function getMovieDetails(int $tmdbId, ?string $userId = null, string $language = 'en-US'): array {
        $apiKey = $this->getApiKey($userId);
        if (empty($apiKey)) {
            throw new \Exception('TMDB API key not configured. Please set your API key in settings.');
        }

        $client = $this->clientService->newClient();

        $response = $client->get(self::BASE_URL . '/movie/' . $tmdbId, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            'query' => [
                'append_to_response' => 'credits',
                'language' => $language,
            ],
        ]);

        $data = json_decode($response->getBody(), true);

        // Extract director and main cast
        $data['director'] = $this->extractDirector($data['credits']['crew'] ?? []);
        $data['cast'] = $this->extractMainCast($data['credits']['cast'] ?? [], 10);

        // Clean up credits to reduce response size
        unset($data['credits']);

        return $data;
    }

    /**
     * @throws \Exception
     */
    public function getGenres(?string $userId = null, string $language = 'en-US'): array {
        $apiKey = $this->getApiKey($userId);
        if (empty($apiKey)) {
            throw new \Exception('TMDB API key not configured. Please set your API key in settings.');
        }

        $client = $this->clientService->newClient();

        $response = $client->get(self::BASE_URL . '/genre/movie/list', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ],
            'query' => [
                'language' => $language,
            ],
        ]);

        return json_decode($response->getBody(), true);
    }

    public static function getPosterUrl(?string $posterPath, string $size = 'w500'): ?string {
        if ($posterPath === null) {
            return null;
        }
        return self::IMAGE_BASE_URL . '/' . $size . $posterPath;
    }

    public static function getBackdropUrl(?string $backdropPath, string $size = 'w1280'): ?string {
        if ($backdropPath === null) {
            return null;
        }
        return self::IMAGE_BASE_URL . '/' . $size . $backdropPath;
    }

    private function extractDirector(array $crew): string {
        $directors = [];
        foreach ($crew as $member) {
            if ($member['job'] === 'Director') {
                $directors[] = $member['name'];
            }
        }
        return implode(', ', $directors);
    }

    private function extractMainCast(array $cast, int $limit): array {
        return array_slice(array_map(function ($actor) {
            return [
                'name' => $actor['name'],
                'character' => $actor['character'],
                'profilePath' => $actor['profile_path'] ?? null,
            ];
        }, $cast), 0, $limit);
    }
}
