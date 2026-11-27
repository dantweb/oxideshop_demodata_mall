<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Cms;

use Dantweb\DemodataMall\Catalog\Catalog;
use OxidEsales\Eshop\Application\Model\Category;
use OxidEsales\Eshop\Application\Model\Content;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use RuntimeException;

/**
 * Installs the start page CMS content of a vertical (verticals/<id>/cms.json) into the current shop:
 * intro teasers (oxstartslot1-4), USP bars, start page meta texts and the footer line. Pages that only
 * make sense for the EE demo catalog (car landing pages) are deactivated in this shop.
 *
 * Links in cms.json are "category:<key>" (a category of this vertical), "content:<loadid>" (a CMS
 * page) or a plain path; they are resolved per language at install time, so they are the shop's
 * SEO URLs. Images are names of files in pictures/ddmedia/mall-<vertical>/ (installed with the pictures);
 * a missing image falls back to the picture of the linked category.
 */
class CmsInstaller
{
    public function __construct(private CmsRenderer $renderer = new CmsRenderer())
    {
    }

    public function has(Catalog $catalog): bool
    {
        return is_file($this->file($catalog));
    }

    /**
     * @return array{pages: int, deactivated: int}
     */
    public function install(Catalog $catalog): array
    {
        $cms = json_decode((string) file_get_contents($this->file($catalog)), true);
        if (!is_array($cms)) {
            throw new RuntimeException('Invalid JSON in ' . $this->file($catalog));
        }

        $shopId = (int) Registry::getConfig()->getShopId();
        $pages = 0;
        foreach (Registry::getLang()->getLanguageIds() as $languageId => $abbreviation) {
            $suffix = $languageId ? '_' . $languageId : '';
            foreach ($this->render($catalog, $cms, (int) $languageId, $abbreviation) as $loadId => $html) {
                $this->save($shopId, $loadId, $suffix, $html);
                $pages += $languageId === 0 ? 1 : 0;
            }
        }

        $deactivated = 0;
        foreach ($cms['deactivate'] ?? [] as $loadId) {
            $deactivated += $this->deactivate($shopId, (string) $loadId);
        }

        return ['pages' => $pages, 'deactivated' => $deactivated];
    }

    /**
     * @param array<string, mixed> $cms
     * @return array<string, string> loadid => html
     */
    private function render(Catalog $catalog, array $cms, int $languageId, string $language): array
    {
        $text = fn (array $texts): string => (string) ($texts[$language] ?? $texts['en'] ?? reset($texts));
        $card = fn (array $item): array => [
            'title' => $text($item['title']),
            'text' => isset($item['text']) ? $text($item['text']) : '',
            'image' => $this->imageUrl($catalog, (string) $item['image'], (string) $item['link']),
            'link' => $this->link($catalog, (string) $item['link'], $languageId),
            'cta' => isset($item['cta']) ? $text($item['cta']) : $text($cms['cta']),
        ];
        $usps = array_map(
            fn (array $usp): array => ['icon' => (string) $usp['icon'], 'text' => $text($usp['text'])],
            $cms['usps']
        );

        return [
            'oxstartslot1' => $this->renderer->heroRow(
                $card($cms['hero']['main']),
                array_map($card, $cms['hero']['side']),
                $text($cms['cta'])
            ),
            'oxstartslot2' => $this->renderer->promoRow(array_map($card, $cms['promos']), $text($cms['cta'])),
            'oxstartslot3' => $this->renderer->banner($card($cms['banner'])),
            'oxstartslot4' => $this->renderer->storyAndService($card($cms['story']), $card($cms['service'])),
            'oxheaderusps' => $this->renderer->headerUsps($usps),
            'oxstartusps' => $this->renderer->startUsps($usps),
            'oxstartmetadescription' => $text($cms['meta']['description']),
            'oxstartmetakeywords' => $text($cms['meta']['keywords']),
            'oxstdfooter' => $this->renderer->footer($text($cms['footer'])),
        ];
    }

    private function link(Catalog $catalog, string $link, int $languageId): string
    {
        [$type, $key] = array_pad(explode(':', $link, 2), 2, '');
        $url = match ($type) {
            'category' => $this->categoryLink($catalog->oxidFor('category', $key), $languageId),
            'content' => $this->contentLink($key, $languageId),
            default => $link,
        };

        // relative to the shop root, so the snippet works under every shop URL
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return ($path ?: '/') . ($query ? '?' . $query : '');
    }

    private function categoryLink(string $categoryId, int $languageId): string
    {
        $category = oxNew(Category::class);
        $category->setLanguage($languageId);
        if (!$category->load($categoryId)) {
            throw new RuntimeException("Category $categoryId not found - install the catalog first.");
        }

        return (string) $category->getLink($languageId);
    }

    private function contentLink(string $loadId, int $languageId): string
    {
        $content = oxNew(Content::class);
        $content->setLanguage($languageId);

        return $content->loadByIdent($loadId) ? (string) $content->getLink($languageId) : '/';
    }

    /**
     * The mood image from pictures/ddmedia/mall-<vertical>/ when it was generated (tools/generate_cms_images.py);
     * otherwise the picture of the linked category, so the start page is complete without the mood images.
     */
    private function imageUrl(Catalog $catalog, string $name, string $link): string
    {
        $mood = sprintf('ddmedia/mall-%s/%s', $catalog->getId(), $name);
        if (is_file($catalog->getPicturesDirectory() . '/' . $mood)) {
            return '/out/pictures/' . $mood;
        }

        [$type, $key] = array_pad(explode(':', $link, 2), 2, '');
        if ($type !== 'category') {
            $key = (string) ($catalog->getCategories()[0]['key'] ?? '');
        }

        return '/out/pictures/master/category/icon/' . $catalog->pictureFor('category', $key);
    }

    private function save(int $shopId, string $loadId, string $suffix, string $html): void
    {
        $db = DatabaseProvider::getDb();
        $updated = $db->execute(
            "UPDATE oxcontents SET OXCONTENT$suffix = :html, OXACTIVE$suffix = 1
             WHERE OXLOADID = :loadid AND OXSHOPID = :shopid",
            [':html' => $html, ':loadid' => $loadId, ':shopid' => $shopId]
        );
        if ($updated > 0 || $this->exists($shopId, $loadId)) {
            return;
        }

        $db->execute(
            "INSERT INTO oxcontents (OXID, OXLOADID, OXSHOPID, OXSNIPPET, OXTYPE, OXACTIVE, OXACTIVE_1,
                OXTITLE, OXTITLE_1, OXCONTENT$suffix)
             VALUES (:oxid, :loadid, :shopid, 1, 0, 1, 1, :loadid, :loadid, :html)",
            [
                ':oxid' => Registry::getUtilsObject()->generateUId(),
                ':loadid' => $loadId,
                ':shopid' => $shopId,
                ':html' => $html,
            ]
        );
    }

    private function exists(int $shopId, string $loadId): bool
    {
        return (bool) DatabaseProvider::getDb()->getOne(
            'SELECT 1 FROM oxcontents WHERE OXLOADID = :loadid AND OXSHOPID = :shopid',
            [':loadid' => $loadId, ':shopid' => $shopId]
        );
    }

    private function deactivate(int $shopId, string $loadId): int
    {
        return (int) DatabaseProvider::getDb()->execute(
            'UPDATE oxcontents SET OXACTIVE = 0, OXACTIVE_1 = 0, OXACTIVE_2 = 0, OXACTIVE_3 = 0
             WHERE OXLOADID = :loadid AND OXSHOPID = :shopid',
            [':loadid' => $loadId, ':shopid' => $shopId]
        );
    }

    private function file(Catalog $catalog): string
    {
        return $catalog->getDirectory() . '/cms.json';
    }
}
