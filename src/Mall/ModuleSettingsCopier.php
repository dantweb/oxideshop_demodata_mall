<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Mall;

use OxidEsales\EshopCommunity\Internal\Framework\Module\Configuration\Dao\ModuleConfigurationDaoInterface;
use Throwable;

/**
 * Copies the module settings of the base shop (payment keys, checkout options, …) to a subshop, so the
 * subshop's modules behave like the base shop's once they are activated. A new EE shop otherwise gets the
 * defaults from each module's metadata.php — e.g. no payment provider keys.
 *
 * Per-shop secrets are not copied (they must differ between shops).
 */
class ModuleSettingsCopier
{
    /** Settings that are secrets bound to one shop. */
    private const PER_SHOP_SETTINGS = ['sJsonWebTokenSignature'];

    public function __construct(private ModuleConfigurationDaoInterface $moduleConfigurationDao)
    {
    }

    /**
     * @param list<string> $moduleIds
     * @return array{copied: list<string>, failed: array<string, string>}
     */
    public function copy(array $moduleIds, int $fromShopId, int $toShopId): array
    {
        $result = ['copied' => [], 'failed' => []];
        foreach ($moduleIds as $moduleId) {
            try {
                if (!$this->moduleConfigurationDao->exists($moduleId, $toShopId)) {
                    continue;
                }
                $source = $this->moduleConfigurationDao->get($moduleId, $fromShopId);
                $target = $this->moduleConfigurationDao->get($moduleId, $toShopId);
                $settings = [];
                foreach ($source->getModuleSettings() as $setting) {
                    $settings[] = in_array($setting->getName(), self::PER_SHOP_SETTINGS, true)
                        && $target->hasModuleSetting($setting->getName())
                        ? $target->getModuleSetting($setting->getName())
                        : $setting;
                }
                $target->setModuleSettings($settings);
                $this->moduleConfigurationDao->save($target, $toShopId);
                $result['copied'][] = $moduleId;
            } catch (Throwable $exception) {
                $result['failed'][$moduleId] = $exception->getMessage();
            }
        }

        return $result;
    }
}
