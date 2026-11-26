<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Catalog;

use RuntimeException;

/**
 * Finds and validates the verticals shipped in verticals/<id>/catalog.json.
 */
class CatalogRepository
{
    private string $verticalsDirectory;

    public function __construct(?string $verticalsDirectory = null)
    {
        $this->verticalsDirectory = $verticalsDirectory ?? dirname(__DIR__, 2) . '/verticals';
    }

    /**
     * @return list<string>
     */
    public function getIds(): array
    {
        $ids = [];
        foreach (glob($this->verticalsDirectory . '/*/catalog.json') ?: [] as $file) {
            $ids[] = basename(dirname($file));
        }
        sort($ids);

        return $ids;
    }

    public function get(string $id): Catalog
    {
        $directory = $this->verticalsDirectory . '/' . $id;
        $file = $directory . '/catalog.json';
        if (!preg_match('/^[a-z0-9-]+$/', $id) || !is_file($file)) {
            throw new RuntimeException(sprintf(
                'Unknown vertical "%s". Available: %s',
                $id,
                implode(', ', $this->getIds())
            ));
        }

        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $errors = $this->validate($data);
        if ($errors) {
            throw new RuntimeException("Invalid $file:\n  " . implode("\n  ", $errors));
        }

        return new Catalog($id, $data, $directory);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        foreach (['manufacturers', 'categories', 'products'] as $section) {
            if (!isset($data[$section]) || !is_array($data[$section])) {
                $errors[] = "missing list \"$section\"";
                continue;
            }
            $keys = array_column($data[$section], 'key');
            foreach (array_unique(array_diff_assoc($keys, array_unique($keys))) as $duplicate) {
                $errors[] = "$section: duplicate key \"$duplicate\"";
            }
        }
        if ($errors) {
            return $errors;
        }

        $categories = array_column($data['categories'], 'key');
        $manufacturers = array_column($data['manufacturers'], 'key');
        foreach ($data['products'] as $product) {
            $key = $product['key'] ?? '?';
            foreach (['key', 'artnum', 'category', 'price', 'title'] as $field) {
                if (!isset($product[$field])) {
                    $errors[] = "product $key: missing \"$field\"";
                }
            }
            if (isset($product['category']) && !in_array($product['category'], $categories, true)) {
                $errors[] = "product $key: unknown category \"{$product['category']}\"";
            }
            if (!empty($product['manufacturer']) && !in_array($product['manufacturer'], $manufacturers, true)) {
                $errors[] = "product $key: unknown manufacturer \"{$product['manufacturer']}\"";
            }
        }

        return $errors;
    }
}
