<?php
/**
 * SC Giftcard Repair - PrestaShop 8 Module
 *
 * @author    Scriptami
 * @copyright Scriptami
 * @license   Academic Free License version 3.0
 */

declare(strict_types=1);

namespace ScGiftcardRepair\Service;

use Doctrine\DBAL\Connection;

/**
 * Base class for all fixer services
 */
abstract class AbstractFixer
{
    public function __construct(
        protected Connection $connection,
        protected string $prefix
    ) {
    }

    /**
     * @return array<string>
     */
    abstract public function getSupportedTypes(): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function preview(string $type): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function apply(string $type): array;

    /**
     * Build a parameterized exclusion clause for product IDs
     *
     * @return array{0: string, 1: array<int>, 2: array<int>} [SQL clause, params, types]
     */
    protected function buildExclusionClause(string $column): array
    {
        $ids = GiftCardConstants::EXCLUDED_PRODUCTS;

        return [
            "AND {$column} NOT IN (?)",
            $ids,
            [Connection::PARAM_INT_ARRAY],
        ];
    }
}
