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

/**
 * Constants for the gift card repair module
 */
final class GiftCardConstants
{
    /**
     * Product IDs that are gift cards and should be excluded from stock/product operations
     *
     * @var array<int>
     */
    public const EXCLUDED_PRODUCTS = [857];

    /**
     * Whether there are excluded products configured
     */
    public static function hasExcludedProducts(): bool
    {
        return !empty(self::EXCLUDED_PRODUCTS);
    }

    /**
     * Get SQL-safe excluded product IDs string for use in IN() clauses
     */
    public static function getExcludedIds(): string
    {
        return implode(',', self::EXCLUDED_PRODUCTS);
    }
}
