<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Catalog;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Category;
use OxidEsales\Eshop\Application\Model\Manufacturer;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Removes the catalog (products, categories, manufacturers) that belongs to the current shop.
 * Uses the shop models, so SEO URLs, shop mappings, variants and relations are removed as well.
 */
class CatalogCleaner
{
    /**
     * @return array{products: int, categories: int, manufacturers: int}
     */
    public function clean(): array
    {
        $shopId = (int) Registry::getConfig()->getShopId();

        return [
            'products' => $this->deleteProducts($shopId),
            'categories' => $this->deleteCategories($shopId),
            'manufacturers' => $this->deleteManufacturers($shopId),
        ];
    }

    private function deleteProducts(int $shopId): int
    {
        $deleted = 0;
        // parents first (deleting a parent also deletes its variants), then variants that are left
        foreach (['OXPARENTID = \'\'', 'OXPARENTID <> \'\''] as $condition) {
            foreach ($this->ids("SELECT OXID FROM oxarticles WHERE OXSHOPID = ? AND $condition", $shopId) as $oxid) {
                $article = oxNew(Article::class);
                if ($article->load($oxid) && $article->delete($oxid)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function deleteCategories(int $shopId): int
    {
        $deleted = 0;
        // Category::delete() removes leaf categories only: delete the deepest ones first
        $sql = 'SELECT OXID FROM oxcategories WHERE OXSHOPID = ? ORDER BY OXROOTID, OXLEFT DESC';
        foreach ($this->ids($sql, $shopId) as $oxid) {
            $category = oxNew(Category::class);
            if ($category->load($oxid) && $category->delete($oxid)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function deleteManufacturers(int $shopId): int
    {
        $deleted = 0;
        foreach ($this->ids('SELECT OXID FROM oxmanufacturers WHERE OXSHOPID = ?', $shopId) as $oxid) {
            $manufacturer = oxNew(Manufacturer::class);
            if ($manufacturer->load($oxid) && $manufacturer->delete($oxid)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private function ids(string $sql, int $shopId): array
    {
        return array_column(DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC)->getAll($sql, [$shopId]), 'OXID');
    }
}
