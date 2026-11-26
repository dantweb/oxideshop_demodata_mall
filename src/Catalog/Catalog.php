<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Catalog;

/**
 * One vertical (fashion, electronics, ...) as read from verticals/<id>/catalog.json.
 */
final class Catalog
{
    private const ID_NAMESPACE = 'dantweb-demodata-mall';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private string $id,
        private array $data,
        private string $directory
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShopName(): string
    {
        return (string) ($this->data['shop']['name'] ?? $this->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getManufacturers(): array
    {
        return $this->data['manufacturers'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCategories(): array
    {
        return $this->data['categories'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getProducts(): array
    {
        return $this->data['products'] ?? [];
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function getPicturesDirectory(): string
    {
        return $this->directory . '/pictures';
    }

    /**
     * Stable OXID for an entry, so a second install updates the same records.
     */
    public function oxidFor(string $type, string $key): string
    {
        return md5(self::ID_NAMESPACE . ':' . $this->id . ':' . $type . ':' . $key);
    }

    public function pictureFor(string $type, string $key): string
    {
        $extension = $type === 'manufacturer' ? 'png' : 'jpg';

        return sprintf('mall-%s-%s.%s', $this->id, $key, $extension);
    }
}
