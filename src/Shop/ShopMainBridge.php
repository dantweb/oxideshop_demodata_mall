<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Shop;

use OxidEsales\Eshop\Application\Controller\Admin\ShopMain;
use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\Config;

/**
 * Gives access to the steps the EE admin runs when it creates a shop
 * (copy base shop fields, config, CMS pages, SEO URLs, module configuration, views).
 */
class ShopMainBridge extends ShopMain
{
    public function prepareNewShop(Shop $shop): bool
    {
        return (bool) $this->canCreateShop(self::NEW_SHOP_ID, $shop);
    }

    public function finalizeNewShop(Config $config, Shop $shop): void
    {
        $this->updateShopInformation($config, $shop, self::NEW_SHOP_ID);
    }

    public function mandateLimitReached(): bool
    {
        return !empty($this->_aViewData['sMandateWarning']);
    }
}
