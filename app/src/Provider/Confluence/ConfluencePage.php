<?php

declare(strict_types=1);

namespace Pablo\Provider\Confluence;

final class ConfluencePage
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
        public string $body, // storage-format XHTML
    ) {
    }
}
