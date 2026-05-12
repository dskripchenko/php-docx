<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Element;

/**
 * Горизонтальная разделительная линия. Маппится в параграф с
 * border-bottom (single thin grey) — стандартный OOXML паттерн.
 */
final readonly class HorizontalRule implements BlockElement {}
