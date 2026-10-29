<?php

declare(strict_types=1);

namespace OCA\MovieDB\Util;

/**
 * Normalizes values for DATE columns.
 *
 * TMDB sends "" as the release date of titles that have none yet; MySQL/MariaDB
 * (strict mode) and PostgreSQL reject that for a DATE column, SQLite does not.
 */
final class DateValue {
    /** A strict Y-m-d calendar date, or null for anything else (including ""). */
    public static function normalize(mixed $value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d');
    }
}
