<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Shop;

use OxidEsales\Eshop\Application\Model\Shop;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Bridge\ShopConfigurationDaoBridgeInterface;
use OxidEsales\EshopCommunity\Internal\Framework\Module\Setup\Bridge\ModuleActivationBridgeInterface;
use RuntimeException;
use Throwable;

/**
 * Creates an EE subshop the same way the admin does ("Master Settings > Shops > New shop").
 */
class SubshopCreator
{
    public function __construct(
        private ShopConfigurationDaoBridgeInterface $shopConfigurationDaoBridge,
        private ModuleActivationBridgeInterface $moduleActivationBridge
    ) {
    }

    public static function isEnterprise(): bool
    {
        return class_exists(\OxidEsales\EshopEnterprise\Application\Model\Shop::class);
    }

    /**
     * Returns the id of the subshop with this name (never the base shop), or null.
     */
    public function findByName(string $name): ?int
    {
        $id = DatabaseProvider::getDb()->getOne(
            'SELECT OXID FROM oxshops WHERE OXNAME = ? AND OXID > 1 ORDER BY OXID LIMIT 1',
            [$name]
        );

        return $id ? (int) $id : null;
    }

    public function create(string $name, ?string $url): int
    {
        if (!self::isEnterprise()) {
            throw new RuntimeException('Subshops need OXID eShop Enterprise Edition. Use --mode=replace instead.');
        }

        $config = Registry::getConfig();
        $bridge = new ShopMainBridge();
        $shop = oxNew(Shop::class);
        $shop->setEnableMultilang(false);

        if (!$bridge->prepareNewShop($shop)) {
            throw new RuntimeException($bridge->mandateLimitReached()
                ? 'The shop license does not allow another subshop (mandate limit reached).'
                : 'The shop cannot create a subshop.');
        }
        // canCreateShop() copied every filled field of shop 1, including its id
        $newShopId = $shop->getNewShopId();
        if (!$newShopId) {
            throw new RuntimeException('No free shop id left.');
        }
        if ($newShopId <= 1) {
            throw new RuntimeException('Refusing to write the base shop as a subshop.');
        }
        $shop->setId((string) $newShopId);
        $shop->assign([
            'oxname' => $name,
            'oxparentid' => 1,
            'oxactive' => 1,
            'oxproductive' => 0,
            'oxisinherited' => 0,
            'oxismultishop' => 0,
            'oxissupershop' => 0,
        ]);
        if (!$shop->save()) {
            throw new RuntimeException("Could not save the subshop \"$name\".");
        }
        $shopId = (int) $shop->getId();
        $bridge->finalizeNewShop($config, $shop);

        if ($url) {
            $config->saveShopConfVar('str', 'sMallShopURL', rtrim($url, '/') . '/', $shopId);
            $config->saveShopConfVar('str', 'sMallSSLShopURL', rtrim($url, '/') . '/', $shopId);
        }

        return $shopId;
    }

    /**
     * Activates in $shopId every module that is active in the base shop (except $skipModuleIds).
     *
     * @param list<string> $skipModuleIds
     * @return array{activated: list<string>, failed: array<string, string>}
     */
    public function activateBaseShopModules(int $shopId, array $skipModuleIds = []): array
    {
        $result = ['activated' => [], 'failed' => []];
        foreach ($this->shopConfigurationDaoBridge->get()->getModuleConfigurations() as $moduleConfiguration) {
            $moduleId = $moduleConfiguration->getId();
            if (in_array($moduleId, $skipModuleIds, true)) {
                continue;
            }
            if (!$moduleConfiguration->isActivated() || $this->moduleActivationBridge->isActive($moduleId, $shopId)) {
                continue;
            }
            try {
                $this->moduleActivationBridge->activate($moduleId, $shopId);
                $result['activated'][] = $moduleId;
            } catch (Throwable $exception) {
                $result['failed'][$moduleId] = $exception->getMessage();
            }
        }

        return $result;
    }
}
