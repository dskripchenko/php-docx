<?php

declare(strict_types=1);

namespace Dskripchenko\PhpDocx\Style;

/**
 * Core properties of the document — the `docProps/core.xml` part.
 *
 * These are what Word shows under File → Info and what Windows Explorer
 * lists in its Title and Authors columns. A package without them opens as
 * an anonymous file: nothing says who produced it or what it is.
 *
 * All fields are optional; only the ones that are set are written out.
 */
final readonly class CoreProperties
{
    public function __construct(
        public ?string $title = null,
        public ?string $subject = null,
        /** dc:creator — the author of the content. */
        public ?string $creator = null,
        public ?string $keywords = null,
        public ?string $description = null,
        /** cp:lastModifiedBy — who saved it last. */
        public ?string $lastModifiedBy = null,
        public ?\DateTimeInterface $created = null,
        public ?\DateTimeInterface $modified = null,
        /** Free-form category and a revision counter, both rarely used. */
        public ?string $category = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->subject === null
            && $this->creator === null
            && $this->keywords === null
            && $this->description === null
            && $this->lastModifiedBy === null
            && $this->created === null
            && $this->modified === null
            && $this->category === null;
    }
}
