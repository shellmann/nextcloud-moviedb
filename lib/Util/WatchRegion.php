<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * The country whose streaming offers are shown. There is no setting for it:
 * it follows the user's TMDB language ("de-DE" -> DE), then the Nextcloud
 * locale ("de_DE" -> DE), and falls back to US.
 */
final class WatchRegion {
    public const FALLBACK = 'US';

    public static function resolve(string $language, ?string $locale): string {
        foreach ([$language, $locale] as $candidate) {
            if ($candidate !== null && preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})$/', $candidate, $m) === 1) {
                return strtoupper($m[1]);
            }
        }

        return self::FALLBACK;
    }
}
