<?php

declare(strict_types=1);

namespace OCA\MovieDB\Controller;

use OCA\MovieDB\AppInfo\Application;
use OCA\MovieDB\Service\TmdbService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class TmdbController extends Controller {
    private TmdbService $service;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        TmdbService $service,
        IUserSession $userSession
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userSession->getUser()?->getUID();
    }

    #[NoAdminRequired]
    public function search(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
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
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function details(int $tmdbId): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $movie = $this->service->getMovieDetails($tmdbId, $this->userId, $language);
            return new JSONResponse(['movie' => $movie]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function genres(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $language = $this->request->getParam('language', 'en-US');

        try {
            $genres = $this->service->getGenres($this->userId, $language);
            return new JSONResponse($genres);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    #[NoAdminRequired]
    public function checkApiKey(): JSONResponse {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'hasApiKey' => $this->service->hasApiKey($this->userId),
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function image(string $path, string $size = 'w200'): DataDownloadResponse {
        $allowedSizes = ['w92', 'w154', 'w185', 'w200', 'w342', 'w500', 'w780', 'original'];
        if (!in_array($size, $allowedSizes)) {
            $size = 'w200';
        }

        // Decode the path in case it was URL-encoded
        $decodedPath = urldecode($path);
        $url = 'https://image.tmdb.org/t/p/' . $size . '/' . ltrim($decodedPath, '/');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $imageData = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($imageData === false) {
            $imageData = '';
            $contentType = 'image/jpeg';
        }

        $response = new DataDownloadResponse($imageData, 'image', $contentType ?: 'image/jpeg');
        $response->cacheFor(86400 * 7); // Cache for 7 days
        return $response;
    }
}
