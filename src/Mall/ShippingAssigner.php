<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Mall;

use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Makes a subshop able to ship.
 *
 * In EE shipping methods (oxdeliveryset) and cost rules (oxdelivery) are assigned per shop
 * (oxdeliveryset2shop / oxdelivery2shop). A non-inherited subshop starts with none → "No shipping methods
 * found". And a cost rule restricted to categories only applies to baskets with products of those
 * categories — the base shop's rules know only the base shop's categories.
 *
 * So: assign the base shop's shipping methods and cost rules to the subshop, and link the given cost rules
 * (default: the EE demo data's parcel rules "Standard" and "Express") to every category of the subshop.
 * Rules without category restrictions need no link. Every statement is idempotent.
 */
class ShippingAssigner
{
    public const DEFAULT_RULES = ['Standard', 'Express'];

    /**
     * @param list<string> $ruleTitles
     * @return array{shippingMethods: int, costRules: int, categoryLinks: int, missingRules: list<string>}
     */
    public function assign(int $shopId, int $baseShopId = 1, array $ruleTitles = self::DEFAULT_RULES): array
    {
        $db = DatabaseProvider::getDb();
        $params = [':shop' => $shopId, ':base' => $baseShopId];

        $shippingMethods = (int) $db->execute(
            'INSERT IGNORE INTO oxdeliveryset2shop (OXSHOPID, OXMAPOBJECTID)
             SELECT :shop, OXMAPOBJECTID FROM oxdeliveryset2shop WHERE OXSHOPID = :base',
            $params
        );
        $costRules = (int) $db->execute(
            'INSERT IGNORE INTO oxdelivery2shop (OXSHOPID, OXMAPOBJECTID)
             SELECT :shop, OXMAPOBJECTID FROM oxdelivery2shop WHERE OXSHOPID = :base',
            $params
        );

        $categoryLinks = 0;
        $missing = [];
        foreach ($ruleTitles as $title) {
            $ruleIds = $db->getCol(
                'SELECT OXID FROM oxdelivery WHERE OXTITLE = :title AND OXACTIVE = 1',
                [':title' => $title]
            );
            if (!$ruleIds) {
                $missing[] = $title;
                continue;
            }
            foreach ($ruleIds as $ruleId) {
                $categoryLinks += (int) $db->execute(
                    "INSERT IGNORE INTO oxobject2delivery (OXID, OXDELIVERYID, OXOBJECTID, OXTYPE)
                     SELECT MD5(CONCAT(:rule, c.OXID)), :rule, c.OXID, 'oxcategories'
                     FROM oxcategories c WHERE c.OXSHOPID = :shop",
                    [':rule' => $ruleId, ':shop' => $shopId]
                );
            }
        }

        return [
            'shippingMethods' => $shippingMethods,
            'costRules' => $costRules,
            'categoryLinks' => $categoryLinks,
            'missingRules' => $missing,
        ];
    }
}
