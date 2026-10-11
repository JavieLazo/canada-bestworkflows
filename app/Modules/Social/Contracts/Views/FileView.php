<?php

declare(strict_types=1);

namespace App\Modules\Social\Contracts\Views;

/**
 * A file on a post, comment or message, as its chip draws it: the name it
 * was uploaded with, how big it is, and what kind of file it is ("PDF",
 * "Word").
 */
final readonly class FileView
{
    public function __construct(
        public string $id,
        public string $name,
        public int $size,
        public string $label,
    ) {}

    /** "pdf", "docx", from the name, for the chip's icon. */
    public function extension(): string
    {
        return strtolower((string) pathinfo($this->name, PATHINFO_EXTENSION));
    }
}
