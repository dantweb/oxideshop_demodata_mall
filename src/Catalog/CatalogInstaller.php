<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Catalog;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\Eshop\Application\Model\Category;
use OxidEsales\Eshop\Application\Model\Manufacturer;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Model\BaseModel;
use OxidEsales\Eshop\Core\Registry;

/**
 * Writes one vertical into the current shop (the shop of the oe-console --shop-id option).
 * Records get stable OXIDs, so running it again updates them instead of adding copies.
 */
class CatalogInstaller
{
    /** Start page promotions that list the products flagged "featured". */
    private const FEATURED_ACTIONS = ['oxtopstart', 'oxbargain', 'oxtop5', 'oxnewest'];

    /**
     * @return array{manufacturers: int, categories: int, products: int}
     */
    public function install(Catalog $catalog): array
    {
        $shopId = (int) Registry::getConfig()->getShopId();
        $languages = $this->languageSuffixes();

        foreach ($catalog->getManufacturers() as $manufacturer) {
            $this->saveManufacturer($catalog, $manufacturer, $languages, $shopId);
        }
        foreach ($catalog->getCategories() as $position => $category) {
            $this->saveCategory($catalog, $category, $languages, $shopId, $position);
        }
        foreach ($catalog->getProducts() as $position => $product) {
            $this->saveProduct($catalog, $product, $languages, $shopId, $position);
        }

        return [
            'manufacturers' => count($catalog->getManufacturers()),
            'categories' => count($catalog->getCategories()),
            'products' => count($catalog->getProducts()),
        ];
    }

    /**
     * Language abbreviation => field suffix, e.g. ['de' => '', 'en' => '_1'].
     *
     * @return array<string, string>
     */
    private function languageSuffixes(): array
    {
        $suffixes = [];
        foreach (Registry::getLang()->getLanguageIds() as $languageId => $abbreviation) {
            $suffixes[$abbreviation] = $languageId ? '_' . $languageId : '';
        }

        return $suffixes;
    }

    /**
     * @param array<string, string> $texts    ['de' => '...', 'en' => '...']
     * @param array<string, string> $languages
     * @return array<string, string>
     */
    private function translated(string $field, array $texts, array $languages): array
    {
        $values = [];
        $fallback = reset($texts) ?: '';
        foreach ($languages as $abbreviation => $suffix) {
            $values[$field . $suffix] = $texts[$abbreviation] ?? $texts['en'] ?? $fallback;
        }

        return $values;
    }

    private function prepare(BaseModel $model, string $oxid): BaseModel
    {
        $model->setEnableMultilang(false);
        if (!$model->load($oxid)) {
            $model->setId($oxid);
        }

        return $model;
    }

    /**
     * @param array<string, mixed>  $manufacturer
     * @param array<string, string> $languages
     */
    private function saveManufacturer(Catalog $catalog, array $manufacturer, array $languages, int $shopId): void
    {
        $model = $this->prepare(oxNew(Manufacturer::class), $catalog->oxidFor('manufacturer', $manufacturer['key']));
        $title = is_array($manufacturer['title']) ? $manufacturer['title'] : ['en' => $manufacturer['title']];
        $model->assign(
            ['oxshopid' => $shopId, 'oxactive' => 1, 'oxicon' => $catalog->pictureFor('manufacturer', $manufacturer['key'])]
            + $this->translated('oxtitle', $title, $languages)
            + $this->translated('oxshortdesc', $manufacturer['short'] ?? [], $languages)
        );
        $model->save();
    }

    /**
     * @param array<string, mixed>  $category
     * @param array<string, string> $languages
     */
    private function saveCategory(Catalog $catalog, array $category, array $languages, int $shopId, int $position): void
    {
        $model = $this->prepare(oxNew(Category::class), $catalog->oxidFor('category', $category['key']));
        $values = [
            'oxshopid' => $shopId,
            'oxparentid' => 'oxrootid',
            'oxactive' => 1,
            'oxhidden' => 0,
            'oxsort' => ($position + 1) * 10,
            'oxicon' => $catalog->pictureFor('category', $category['key']),
        ];
        foreach ($languages as $suffix) {
            if ($suffix !== '') {
                $values['oxactive' . $suffix] = 1;
            }
        }
        $model->assign(
            $values
            + $this->translated('oxtitle', $category['title'], $languages)
            + $this->translated('oxdesc', $category['description'] ?? [], $languages)
        );
        $model->save();
    }

    /**
     * @param array<string, mixed>  $product
     * @param array<string, string> $languages
     */
    private function saveProduct(Catalog $catalog, array $product, array $languages, int $shopId, int $position): void
    {
        $oxid = $catalog->oxidFor('product', $product['key']);
        $model = $this->prepare(oxNew(Article::class), $oxid);
        $values = [
            'oxshopid' => $shopId,
            'oxparentid' => '',
            'oxactive' => 1,
            'oxissearch' => 1,
            'oxartnum' => $product['artnum'],
            'oxprice' => (float) $product['price'],
            'oxtprice' => (float) ($product['tprice'] ?? 0),
            'oxstock' => (int) ($product['stock'] ?? 10),
            'oxstockflag' => 1,
            'oxsort' => ($position + 1) * 10,
            'oxpic1' => $catalog->pictureFor('product', $product['key']),
            'oxmanufacturerid' => empty($product['manufacturer'])
                ? ''
                : $catalog->oxidFor('manufacturer', $product['manufacturer']),
            'oxinsert' => date('Y-m-d'),
        ];
        foreach ($languages as $suffix) {
            if ($suffix !== '') {
                $values['oxactive' . $suffix] = 1;
            }
        }
        $model->assign(
            $values
            + $this->translated('oxtitle', $product['title'], $languages)
            + $this->translated('oxshortdesc', $product['short'] ?? [], $languages)
        );
        $model->save();

        $this->saveLongDescription($oxid, $product['long'] ?? [], $languages);
        $this->assignCategory($oxid, $catalog->oxidFor('category', $product['category']), $shopId, $position);
        $this->assignActions($oxid, !empty($product['featured']), $shopId, $position);
    }

    /**
     * @param array<string, string> $texts
     * @param array<string, string> $languages
     */
    private function saveLongDescription(string $oxid, array $texts, array $languages): void
    {
        $values = $this->translated('OXLONGDESC', $texts, $languages);
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns) + 1, '?'));
        $updates = implode(', ', array_map(static fn ($column) => "$column = VALUES($column)", $columns));
        DatabaseProvider::getDb()->execute(
            'INSERT INTO oxartextends (OXID, ' . implode(', ', $columns) . ") VALUES ($placeholders)"
            . " ON DUPLICATE KEY UPDATE $updates",
            array_merge([$oxid], array_values($values))
        );
    }

    private function assignCategory(string $productId, string $categoryId, int $shopId, int $position): void
    {
        DatabaseProvider::getDb()->execute(
            'REPLACE INTO oxobject2category (OXID, OXSHOPID, OXOBJECTID, OXCATNID, OXPOS, OXTIME)
             VALUES (?, ?, ?, ?, ?, ?)',
            [md5($productId . $categoryId), $shopId, $productId, $categoryId, $position, time()]
        );
    }

    private function assignActions(string $productId, bool $featured, int $shopId, int $position): void
    {
        $db = DatabaseProvider::getDb();
        $db->execute('DELETE FROM oxactions2article WHERE OXARTID = ? AND OXSHOPID = ?', [$productId, $shopId]);
        if (!$featured) {
            return;
        }
        foreach (self::FEATURED_ACTIONS as $actionId) {
            $exists = $db->getOne('SELECT 1 FROM oxactions WHERE OXID = ?', [$actionId]);
            if (!$exists) {
                continue;
            }
            $db->execute(
                'INSERT INTO oxactions2article (OXID, OXSHOPID, OXACTIONID, OXARTID, OXSORT) VALUES (?, ?, ?, ?, ?)',
                [md5($actionId . $productId . $shopId), $shopId, $actionId, $productId, $position]
            );
        }
    }
}
