<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Maps the `videos` and `watch/providers` parts of a TMDB details response to
 * the small, validated shape the frontend shows. Nothing from TMDB reaches the
 * browser unchecked: video keys, logo paths and links are validated here.
 */
final class TmdbExtras {
    public const MAX_PROVIDERS = 10;
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';
    private const TMDB_LINK_PREFIX = 'https://www.themoviedb.org/';
    private const TYPE_RANK = ['Trailer' => 0, 'Teaser' => 1];

    /**
     * Pick the best trailer: a Trailer before a Teaser, then the user's
     * language before English before anything else, then official uploads,
     * then the newest.
     *
     * @param array<int,mixed> $videos TMDB `videos.results`
     * @param string $language TMDB language such as "de-DE"
     * @return array{site:string,key:string,name:string,url:string}|null
     */
    public static function pickTrailer(array $videos, string $language): ?array {
        $userLanguage = strtolower(substr($language, 0, 2));
        $candidates = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }
            $site = $video['site'] ?? null;
            $type = $video['type'] ?? null;
            $key = $video['key'] ?? null;
            if (!in_array($site, ['YouTube', 'Vimeo'], true)
                || !is_string($type) || !isset(self::TYPE_RANK[$type])
                || !is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }
            $videoLanguage = is_string($video['iso_639_1'] ?? null) ? strtolower($video['iso_639_1']) : '';
            $candidates[] = [
                'rank' => [
                    self::TYPE_RANK[$type],
                    $videoLanguage === $userLanguage ? 0 : ($videoLanguage === 'en' ? 1 : 2),
                    ($video['official'] ?? false) === true ? 0 : 1,
                ],
                'published' => is_string($video['published_at'] ?? null) ? $video['published_at'] : '',
                'site' => $site,
                'key' => $key,
                'name' => is_string($video['name'] ?? null) ? mb_substr(trim($video['name']), 0, 200) : '',
            ];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static function (array $a, array $b): int {
            return ($a['rank'] <=> $b['rank']) ?: strcmp($b['published'], $a['published']);
        });
        $best = $candidates[0];

        return [
            'site' => $best['site'],
            'key' => $best['key'],
            'name' => $best['name'],
            'url' => $best['site'] === 'YouTube'
                ? 'https://www.youtube.com/watch?v=' . $best['key']
                : 'https://vimeo.com/' . $best['key'],
        ];
    }

    /**
     * @param array<string,mixed> $results TMDB `watch/providers.results`, keyed by country
     * @return array{region:string,link:?string,stream:list<array>,free:list<array>,rent:list<array>,buy:list<array>}
     */
    public static function mapProviders(array $results, string $region): array {
        $entry = is_array($results[$region] ?? null) ? $results[$region] : [];
        $link = $entry['link'] ?? null;

        return [
            'region' => $region,
            'link' => is_string($link) && str_starts_with($link, self::TMDB_LINK_PREFIX) ? $link : null,
            'stream' => self::providers($entry['flatrate'] ?? []),
            'free' => self::providers(array_merge(
                is_array($entry['free'] ?? null) ? $entry['free'] : [],
                is_array($entry['ads'] ?? null) ? $entry['ads'] : [],
            )),
            'rent' => self::providers($entry['rent'] ?? []),
            'buy' => self::providers($entry['buy'] ?? []),
        ];
    }

    /**
     * @param mixed $list
     * @return list<array{id:int,name:string,logoPath:?string}>
     */
    private static function providers(mixed $list): array {
        if (!is_array($list)) {
            return [];
        }
        $byId = [];
        foreach ($list as $provider) {
            if (!is_array($provider) || !is_int($provider['provider_id'] ?? null)) {
                continue;
            }
            $name = is_string($provider['provider_name'] ?? null) ? mb_substr(trim($provider['provider_name']), 0, 100) : '';
            if ($name === '' || isset($byId[$provider['provider_id']])) {
                continue;
            }
            $logo = $provider['logo_path'] ?? null;
            $priority = $provider['display_priority'] ?? 999;
            $byId[$provider['provider_id']] = [
                'priority' => is_int($priority) ? $priority : 999,
                'id' => $provider['provider_id'],
                'name' => $name,
                'logoPath' => is_string($logo) && TmdbPath::isValid($logo) ? $logo : null,
            ];
        }
        $sorted = array_values($byId);
        usort($sorted, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_map(
            static fn (array $p): array => ['id' => $p['id'], 'name' => $p['name'], 'logoPath' => $p['logoPath']],
            array_slice($sorted, 0, self::MAX_PROVIDERS),
        );
    }
}
