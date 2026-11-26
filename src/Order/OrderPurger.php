<?php

declare(strict_types=1);

namespace Dantweb\DemodataMall\Order;

use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\Registry;

/**
 * Deletes all orders of the current shop and every row that points to them.
 *
 * Rows are found in every table of the shop database that has an OXORDERID or ORDERID column
 * (core order articles and files, payment module contracts and transactions, ...).
 * Vouchers are not deleted: they are released (no order, no user, not used).
 */
class OrderPurger
{
    private const ORDER_COLUMNS = ['oxorderid', 'orderid'];
    private const CHUNK = 500;

    /**
     * @return array<string, int> affected rows per table
     */
    public function purge(): array
    {
        $db = DatabaseProvider::getDb();
        $shopId = (int) Registry::getConfig()->getShopId();
        $orderIds = $db->getCol('SELECT OXID FROM oxorder WHERE OXSHOPID = ?', [$shopId]);
        $result = ['oxorder' => count($orderIds)];
        if (!$orderIds) {
            return $result;
        }

        foreach ($this->linkedColumns() as [$table, $column]) {
            $result[$table] = ($result[$table] ?? 0) + $this->clearLinks($table, $column, $orderIds);
        }
        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $db->execute('DELETE FROM oxorder WHERE OXID IN (' . $this->placeholders($chunk) . ')', $chunk);
        }

        return $result;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function linkedColumns(): array
    {
        $rows = DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_NUM)->getAll(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
               FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t
                 ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
              WHERE c.TABLE_SCHEMA = DATABASE()
                AND LOWER(c.COLUMN_NAME) IN ('" . implode("', '", self::ORDER_COLUMNS) . "')
                AND LOWER(c.TABLE_NAME) <> 'oxorder'
              ORDER BY c.TABLE_NAME"
        );

        return array_map(static fn (array $row) => [(string) $row[0], (string) $row[1]], $rows);
    }

    /**
     * @param list<string> $orderIds
     */
    private function clearLinks(string $table, string $column, array $orderIds): int
    {
        $db = DatabaseProvider::getDb();
        $affected = 0;
        foreach (array_chunk($orderIds, self::CHUNK) as $chunk) {
            $in = $this->placeholders($chunk);
            if (strtolower($table) === 'oxvouchers') {
                $affected += (int) $db->execute(
                    "UPDATE oxvouchers SET OXORDERID = '', OXUSERID = '', OXDATEUSED = NULL, OXRESERVED = 0
                      WHERE OXORDERID IN ($in)",
                    $chunk
                );
                continue;
            }
            $affected += (int) $db->execute("DELETE FROM `$table` WHERE `$column` IN ($in)", $chunk);
        }

        return $affected;
    }

    /**
     * @param list<string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
