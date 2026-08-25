<?php

declare(strict_types=1);

return [
    'routes' => [
        // Main page (serves Vue.js app)
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Movies CRUD
        ['name' => 'movie#index', 'url' => '/api/movies', 'verb' => 'GET'],
        ['name' => 'movie#show', 'url' => '/api/movies/{id}', 'verb' => 'GET'],
        ['name' => 'movie#create', 'url' => '/api/movies', 'verb' => 'POST'],
        ['name' => 'movie#update', 'url' => '/api/movies/{id}', 'verb' => 'PUT'],
        ['name' => 'movie#destroy', 'url' => '/api/movies/{id}', 'verb' => 'DELETE'],

        // Watchlist CRUD
        ['name' => 'watchlist#index', 'url' => '/api/watchlist', 'verb' => 'GET'],
        ['name' => 'watchlist#show', 'url' => '/api/watchlist/{id}', 'verb' => 'GET'],
        ['name' => 'watchlist#create', 'url' => '/api/watchlist', 'verb' => 'POST'],
        ['name' => 'watchlist#update', 'url' => '/api/watchlist/{id}', 'verb' => 'PUT'],
        ['name' => 'watchlist#destroy', 'url' => '/api/watchlist/{id}', 'verb' => 'DELETE'],
        ['name' => 'watchlist#moveToWatched', 'url' => '/api/watchlist/{id}/watched', 'verb' => 'POST'],

        // Platforms CRUD
        ['name' => 'platform#index', 'url' => '/api/platforms', 'verb' => 'GET'],
        ['name' => 'platform#create', 'url' => '/api/platforms', 'verb' => 'POST'],
        ['name' => 'platform#update', 'url' => '/api/platforms/{id}', 'verb' => 'PUT'],
        ['name' => 'platform#destroy', 'url' => '/api/platforms/{id}', 'verb' => 'DELETE'],

        // TMDB API proxy
        ['name' => 'tmdb#search', 'url' => '/api/tmdb/search', 'verb' => 'GET'],
        ['name' => 'tmdb#details', 'url' => '/api/tmdb/movie/{tmdbId}', 'verb' => 'GET'],
        ['name' => 'tmdb#genres', 'url' => '/api/tmdb/genres', 'verb' => 'GET'],
        ['name' => 'tmdb#checkApiKey', 'url' => '/api/tmdb/check', 'verb' => 'GET'],
        ['name' => 'tmdb#image', 'url' => '/api/tmdb/image/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+']],

        // Movie watch history
        ['name' => 'movie_watch#index',   'url' => '/api/movies/{movieId}/watches',           'verb' => 'GET'],
        ['name' => 'movie_watch#create',  'url' => '/api/movies/{movieId}/watches',           'verb' => 'POST'],
        ['name' => 'movie_watch#update',  'url' => '/api/movies/{movieId}/watches/{watchId}', 'verb' => 'PUT'],
        ['name' => 'movie_watch#destroy', 'url' => '/api/movies/{movieId}/watches/{watchId}', 'verb' => 'DELETE'],

        // Statistics
        ['name' => 'stats#overview', 'url' => '/api/stats', 'verb' => 'GET'],
        ['name' => 'stats#byYear', 'url' => '/api/stats/years', 'verb' => 'GET'],
        ['name' => 'stats#byPlatform', 'url' => '/api/stats/platforms', 'verb' => 'GET'],
        ['name' => 'stats#byGenre', 'url' => '/api/stats/genres', 'verb' => 'GET'],
        ['name' => 'stats#recent', 'url' => '/api/stats/recent', 'verb' => 'GET'],
        ['name' => 'stats#topRated', 'url' => '/api/stats/top-rated', 'verb' => 'GET'],

        // Settings
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
    ],
];
