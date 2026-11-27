<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\TmdbExtrasService;
use OCA\MovieDB\Service\TmdbExtrasUnavailableException;
use OCA\MovieDB\Service\TmdbService;
use OCA\MovieDB\Util\TmdbPath;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TmdbController extends AuthenticatedController {
    private TmdbService $service;
    private LoggerInterface $logger;

    public function __construct(
        IRequest $request,
        TmdbService $service,
        IUserSession $userSession,
        LoggerInterface $logger,
        private TmdbExtrasService $extras
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession);
        $this->service = $service;
        $this->logger = $logger;
    }

    #[NoAdminRequired]
    public function search(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $query = $this->request->getParam('query');
        if (empty($query)) {
            return new JSONResponse(['error' => 'Query parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $year = $this->request->getParam('year');
        $page = (int)$this->request->getParam('page', 1);
        $language = $this->request->getParam('language', 'en-US');
        $type = $this->request->getParam('type', 'movie');

        try {
            if ($type === 'series') {
                $results = $this->service->searchSeries(
                    $query,
                    $year ? (int)$year : null,
                    $page,
                    $this->userId,
                    $language
                );
            } else {
                $results = $this->service->searchMovies(
                    $query,
                    $year ? (int)$year : null,
                    $page,
                    $this->userId,
                    $language
                );
            }
            return new JSONResponse($results);
        } catch (\Exception $e) {
            $this->logger->error('Failed to search TMDB ' . ($type === 'series' ? 'series' : 'movies'), [
                'exception' => $e,
                'userId' => $this->userId,
                'query' => $query,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to search. Please check your API key and try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function searchSeries(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $query = $this->request->getParam('query');
        if (empty($query)) {
            return new JSONResponse(['error' => 'Query parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        $year = $this->request->getParam('year');
        $page = (int)$this->request->getParam('page', 1);
        $language = $this->request->getParam('language', 'en-US');

        try {
            $results = $this->service->searchSeries(
                $query,
                $year ? (int)$year : null,
                $page,
                $this->userId,
                $language
            );
            return new JSONResponse($results);
        } catch (\Exception $e) {
            $this->logger->error('Failed to search TMDB series', [
                'exception' => $e,
                'userId' => $this->userId,
                'query' => $query,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to search series. Please check your API key and try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function seriesDetails(int $tmdbId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $series = $this->service->getSeriesDetails($tmdbId, $this->userId, $language);
            return new JSONResponse(['series' => $series]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get TMDB series details', [
                'exception' => $e,
                'userId' => $this->userId,
                'tmdbId' => $tmdbId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load series details. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function seriesGenres(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $genres = $this->service->getSeriesGenres($this->userId, $language);
            return new JSONResponse($genres);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get TMDB series genres', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load series genres. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function seasonDetails(int $tmdbId, int $seasonNumber): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $season = $this->service->getSeasonDetails($tmdbId, $seasonNumber, $this->userId, $language);
            return new JSONResponse(['season' => $season]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get TMDB season details', [
                'exception' => $e,
                'userId' => $this->userId,
                'tmdbId' => $tmdbId,
                'seasonNumber' => $seasonNumber,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load season details. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    /**
     * Trailer and streaming offers ("where to watch") of a movie or show.
     * Quietly empty without a TMDB key, so the detail pages simply hide them.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 600)]
    public function extras(string $type, int $tmdbId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }
        if (!in_array($type, ['movie', 'tv'], true)) {
            return new JSONResponse(['error' => 'Unsupported media type'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->extras->hasApiKey($this->userId)) {
            return new JSONResponse(['trailer' => null, 'watch' => null, 'reason' => 'no_api_key']);
        }

        $language = (string)$this->request->getParam('language', 'en-US');
        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language) !== 1) {
            $language = 'en-US';
        }

        try {
            return new JSONResponse($this->extras->get($type, $tmdbId, $this->userId, $language));
        } catch (TmdbExtrasUnavailableException) {
            // The failure behind it was logged when it happened; don't log it on every page open.
        } catch (\Exception $e) {
            if ($e->getCode() === Http::STATUS_NOT_FOUND) {
                // TMDB doesn't know the title (an obscure or removed one): expected, not an error.
                // Guzzle puts the HTTP status into the exception code.
                $this->logger->info('TMDB has no trailer and providers for this title', [
                    'userId' => $this->userId,
                    'tmdbId' => $tmdbId,
                ]);
            } else {
                $this->logger->error('Failed to get TMDB trailer and providers', [
                    'exception' => $e,
                    'userId' => $this->userId,
                    'tmdbId' => $tmdbId,
                ]);
            }
        }

        return new JSONResponse(
            ['trailer' => null, 'watch' => null, 'reason' => 'tmdb_error'],
            Http::STATUS_BAD_GATEWAY
        );
    }

    #[NoAdminRequired]
    public function details(int $tmdbId): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $movie = $this->service->getMovieDetails($tmdbId, $this->userId, $language);
            return new JSONResponse(['movie' => $movie]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get TMDB movie details', [
                'exception' => $e,
                'userId' => $this->userId,
                'tmdbId' => $tmdbId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load movie details. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function genres(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $genres = $this->service->getGenres($this->userId, $language);
            return new JSONResponse($genres);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get TMDB genres', [
                'exception' => $e,
                'userId' => $this->userId,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to load genres. Please try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
    }

    #[NoAdminRequired]
    public function checkApiKey(): JSONResponse {
        if ($error = $this->requireAuth()) {
            return $error;
        }

        return new JSONResponse([
            'hasApiKey' => $this->service->hasApiKey($this->userId),
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function image(string $path, string $size = 'w200'): DataDownloadResponse {
        if ($error = $this->requireAuth()) {
            return new DataDownloadResponse('', 'image', 'image/jpeg');
        }

        $allowedSizes = ['w92', 'w154', 'w185', 'w200', 'w342', 'w500', 'w780', 'original'];
        if (!in_array($size, $allowedSizes)) {
            $size = 'w200';
        }

        // Validate path: must be a valid TMDB image filename (alphanumeric + extension)
        $decodedPath = urldecode($path);
        if (!TmdbPath::isValid($decodedPath)) {
            $this->logger->warning('Invalid TMDB image path requested', ['path' => $decodedPath]);
            return new DataDownloadResponse('', 'image', 'image/jpeg');
        }

        $url = 'https://image.tmdb.org/t/p/' . $size . '/' . ltrim($decodedPath, '/');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($imageData === false || $httpCode !== 200) {
            $imageData = '';
            $contentType = 'image/jpeg';
        }

        // Ensure response is actually an image
        if ($contentType && !str_starts_with($contentType, 'image/')) {
            $imageData = '';
            $contentType = 'image/jpeg';
        }

        $response = new DataDownloadResponse($imageData, 'image', $contentType ?: 'image/jpeg');
        $response->cacheFor(86400 * 7); // Cache for 7 days
        return $response;
    }
}
