<?php

declare(strict_types=1);

namespace OCA\MovieDB\Service;

/**
 * TMDB failed for this title a few minutes ago, and the failure is still
 * remembered. The first failure was logged already, so this one is not.
 */
class TmdbExtrasUnavailableException extends \RuntimeException {
}
