<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Reader;

use Dskripchenko\PhpDocx\Element\ListFormat;

/**
 * The definitions from `numbering.xml`, resolved from the concrete numId
 * through abstractNumId down to the per-level structure.
 *
 * Map numId → level → { format, startAt }
 */
final readonly class NumberingDefinitions
{
    /**
     * @param  array<int, array<int, array{format: ListFormat, startAt: int}>>  $byNumId
     */
    public function __construct(
        public array $byNumId = [],
    ) {}

    public function formatFor(int $numId, int $level = 0): ListFormat
    {
        return $this->byNumId[$numId][$level]['format']
            ?? $this->byNumId[$numId][0]['format']
            ?? ListFormat::Bullet;
    }

    public function startAtFor(int $numId, int $level = 0): int
    {
        return $this->byNumId[$numId][$level]['startAt']
            ?? $this->byNumId[$numId][0]['startAt']
            ?? 1;
    }

    public function isOrdered(int $numId, int $level = 0): bool
    {
        $fmt = $this->formatFor($numId, $level);

        return $fmt !== ListFormat::Bullet;
    }

    public function hasNumId(int $numId): bool
    {
        return isset($this->byNumId[$numId]);
    }
}
