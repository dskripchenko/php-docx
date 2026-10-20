<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Writer;

/**
 * Gives every entry of a ZIP archive the same, fixed modification time, so
 * the same document always produces the same bytes.
 *
 * ZipArchive stamps each entry with the time it was written, so two writes of
 * one document differ. `ZipArchive::setMtimeName()` does not fix that on its
 * own: libzip turns the Unix timestamp into a DOS date and time through the C
 * `localtime()`, using the process's `TZ` (not PHP's `date.timezone`), so one
 * timestamp gives different bytes on machines in different time zones.
 *
 * The DOS date and time are therefore written straight into the archive: into
 * every local file header and every central directory record. libzip writes no
 * extended-timestamp extra fields, so these two fields are the only place the
 * time is kept, and the CRC does not cover them.
 */
final class ZipTimestamp
{
    private const LOCAL_HEADER = "PK\x03\x04";

    private const CENTRAL_HEADER = "PK\x01\x02";

    private const END_OF_CENTRAL_DIRECTORY = "PK\x05\x06";

    /**
     * Sets the time of every entry to `$at`, read as a wall-clock time in UTC,
     * or to 1980-01-01 00:00:00 (the earliest DOS time) when `$at` is null.
     *
     * An archive this class cannot read safely (ZIP64, a comment, an unexpected
     * layout) is returned unchanged.
     */
    public static function apply(string $zip, ?\DateTimeInterface $at = null): string
    {
        [$time, $date] = self::dosDateTime($at);

        $eocd = strlen($zip) - 22;
        if ($eocd < 0 || substr($zip, $eocd, 4) !== self::END_OF_CENTRAL_DIRECTORY) {
            return $zip;
        }

        /** @var array{entries: int, offset: int} $end */
        $end = unpack('ventries/x4/Voffset', $zip, $eocd + 10);
        if ($end['entries'] === 0xFFFF || $end['offset'] === 0xFFFFFFFF) {
            return $zip;
        }

        $stamp = pack('vv', $time, $date);
        $patched = $zip;
        $pos = $end['offset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($zip, $pos, 4) !== self::CENTRAL_HEADER) {
                return $zip;
            }
            /** @var array{nameLength: int, extraLength: int, commentLength: int, localOffset: int} $entry */
            $entry = unpack('vnameLength/vextraLength/vcommentLength/x8/VlocalOffset', $zip, $pos + 28);
            $local = $entry['localOffset'];
            if (substr($zip, $local, 4) !== self::LOCAL_HEADER) {
                return $zip;
            }

            $patched = substr_replace($patched, $stamp, $pos + 12, 4);
            $patched = substr_replace($patched, $stamp, $local + 10, 4);

            $pos += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
        }

        return $patched;
    }

    /**
     * MS-DOS time and date words. The DOS range is 1980–2107; a time outside it
     * is clamped to the nearest end.
     *
     * @return array{int, int}
     */
    private static function dosDateTime(?\DateTimeInterface $at): array
    {
        if ($at === null) {
            return [0, (1 << 5) | 1];
        }

        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        $year = (int) $utc->format('Y');
        if ($year < 1980) {
            return [0, (1 << 5) | 1];
        }
        if ($year > 2107) {
            return [(23 << 11) | (59 << 5) | 29, (127 << 9) | (12 << 5) | 31];
        }

        $time = ((int) $utc->format('G') << 11) | ((int) $utc->format('i') << 5) | intdiv((int) $utc->format('s'), 2);
        $date = (($year - 1980) << 9) | ((int) $utc->format('n') << 5) | (int) $utc->format('j');

        return [$time, $date];
    }
}
