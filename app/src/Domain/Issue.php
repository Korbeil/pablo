<?php

declare(strict_types=1);

namespace Pablo\Domain;

final class Issue
{
    public function __construct(
        public string $provider,
        public string $key,
        public string $url,
        public string $title,
        public ?string $projectKey = null,
        public ?string $status = null,
    ) {
    }

    /**
     * @return array{provider: string, key: string, url: string, title: string, project_key: ?string, status: ?string}
     */
    public function toJson(): array
    {
        return [
            'provider' => $this->provider,
            'key' => $this->key,
            'url' => $this->url,
            'title' => $this->title,
            'project_key' => $this->projectKey,
            'status' => $this->status,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        return new self(
            $data['provider'],
            $data['key'],
            $data['url'],
            $data['title'],
            $data['project_key'] ?? null,
            $data['status'] ?? null,
        );
    }
}
