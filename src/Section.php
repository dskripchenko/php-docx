<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx;

use Dskripchenko\PhpDocx\Element\BlockElement;
use Dskripchenko\PhpDocx\Style\PageSetup;

/**
 * Section — логическая секция документа: набор block-элементов + page setup.
 *
 * В простом случае весь документ — одна секция. Multi-section нужен для
 * mid-document смены ориентации/полей (не покрывается v1).
 */
final readonly class Section
{
    /**
     * @param  list<BlockElement>  $body
     * @param  list<BlockElement>  $header
     * @param  list<BlockElement>  $footer
     */
    public function __construct(
        public array $body,
        public array $header = [],
        public array $footer = [],
        public PageSetup $pageSetup = new PageSetup,
    ) {}
}
