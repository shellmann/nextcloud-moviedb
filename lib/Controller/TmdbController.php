<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\TmdbService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
        LoggerInterface $logger
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

        try {
            $results = $this->service->searchMovies(
                $query,
                $year ? (int)$year : null,
                $page,
                $this->userId,
                $language
            );
            return new JSONResponse($results);
        } catch (\Exception $e) {
            $this->logger->error('Failed to search TMDB movies', [
                'exception' => $e,
                'userId' => $this->userId,
                'query' => $query,
            ]);
            return new JSONResponse(
                ['error' => 'Failed to search movies. Please check your API key and try again.'],
                Http::STATUS_BAD_REQUEST
            );
        }
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
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp|svg)$/', ltrim($decodedPath, '/'))) {
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
