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

        // Series CRUD + mark-watched fan-out
        ['name' => 'series#index', 'url' => '/api/series', 'verb' => 'GET'],
        ['name' => 'series#create', 'url' => '/api/series', 'verb' => 'POST'],
        ['name' => 'series#show', 'url' => '/api/series/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'series#update', 'url' => '/api/series/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
        ['name' => 'series#destroy', 'url' => '/api/series/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'series#episodes', 'url' => '/api/series/{id}/episodes', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'series#markWatched', 'url' => '/api/series/{id}/watched', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'series#markSeasonWatched', 'url' => '/api/series/{id}/seasons/{seasonNumber}/watched', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'seasonNumber' => '\d+']],


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

        // TMDB series API proxy — declare literal routes BEFORE the {tmdbId}
        // catch-all so "search"/"genres" are not captured as a tmdbId.
        ['name' => 'tmdb#searchSeries', 'url' => '/api/tmdb/series/search', 'verb' => 'GET'],
        ['name' => 'tmdb#seriesGenres', 'url' => '/api/tmdb/series/genres', 'verb' => 'GET'],
        ['name' => 'tmdb#seasonDetails', 'url' => '/api/tmdb/series/{tmdbId}/season/{seasonNumber}', 'verb' => 'GET', 'requirements' => ['tmdbId' => '\d+', 'seasonNumber' => '\d+']],
        ['name' => 'tmdb#seriesDetails', 'url' => '/api/tmdb/series/{tmdbId}', 'verb' => 'GET', 'requirements' => ['tmdbId' => '\d+']],

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

        // Libraries — declare the literal 'sharees' route BEFORE the {id}
        // catch-all so the string 'sharees' is not captured as a numeric id.
        ['name' => 'library#sharees',      'url' => '/api/libraries/sharees',                  'verb' => 'GET'],
        ['name' => 'library#index',        'url' => '/api/libraries',                          'verb' => 'GET'],
        ['name' => 'library#create',       'url' => '/api/libraries',                          'verb' => 'POST'],
        ['name' => 'library#update',       'url' => '/api/libraries/{id}',                     'verb' => 'PUT',    'requirements' => ['id' => '\d+']],
        ['name' => 'library#destroy',      'url' => '/api/libraries/{id}',                     'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'library#members',      'url' => '/api/libraries/{id}/members',             'verb' => 'GET',    'requirements' => ['id' => '\d+']],
        ['name' => 'library#addMember',    'url' => '/api/libraries/{id}/members',             'verb' => 'POST',   'requirements' => ['id' => '\d+']],
        ['name' => 'library#removeMember', 'url' => '/api/libraries/{id}/members/{userId}',    'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
        ['name' => 'library#leave',        'url' => '/api/libraries/{id}/leave',               'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

        // Settings
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
    ],
];
