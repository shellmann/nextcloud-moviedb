<?php

declare(strict_types=1);

namespace OCA\MovieDB\Calendar;

use Sabre\VObject\Component\VEvent;

/**
 * A VEVENT that also answers $event['UID'].
 *
 * Core's AppCalendar::getChildren() groups the results of ICalendar::search()
 * with `(string)$object['UID']`. On a plain component that hits
 * Node::offsetGet and logs "Undefined array key UID".
 */
class MovieDbEvent extends VEvent {
    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        if ($offset === 'UID') {
            return $this->UID;
        }
        return parent::offsetGet($offset);
    }
}
