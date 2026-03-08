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
 * Fixer for gift card data: rebuild missing giftcard table entries
 * and verify balance consistency
 *
 * The thegiftcard module stores gift cards across 3 layers:
 * - giftcard: links each purchase (id_order_detail) to its voucher (id_cart_rule)
 * - cart_rule + cart_rule_lang: the generated voucher (amount, validity, code)
 * - order_cart_rule: consumption tracking (which voucher used on which order)
 *
 * After migration, the giftcard table may be incomplete while cart_rules exist.
 */
class GiftCardFixer extends AbstractFixer
{
    /**
     * @param int    $giftCardProductId    Product ID for gift cards (default: Kelenaya gift card product)
     * @param string $giftCardCartRuleName Cart rule name used to identify gift card vouchers (default: Kelenaya naming)
     */
    public function __construct(
        Connection $connection,
        string $prefix,
        private int $giftCardProductId = 857,
        private string $giftCardCartRuleName = 'La carte cadeau'
    ) {
        parent::__construct($connection, $prefix);
    }

    public function getSupportedTypes(): array
    {
        return ['giftcard_rebuild'];
    }

    public function preview(string $type): array
    {
        return match ($type) {
            'giftcard_rebuild' => $this->previewRebuild(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    public function apply(string $type): array
    {
        return match ($type) {
            'giftcard_rebuild' => $this->applyRebuild(),
            default => ['error' => 'Unsupported type', 'success' => false],
        };
    }

    /**
     * Identify gift card cart_rules and check which ones are missing from the giftcard table
     *
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        $giftCardRules = $this->getGiftCardCartRules();
        $existingEntries = $this->getExistingGiftCardEntries();
        $existingCartRuleIds = array_column($existingEntries, 'id_cart_rule');

        $missing = [];
        $existing = [];
        $orphaned = [];

        foreach ($giftCardRules as $rule) {
            $cartRuleId = (int) $rule['id_cart_rule'];
            $orderDetail = $this->findOrderDetailForCartRule($rule);
            $consumption = $this->getConsumption($rule['code']);

            $entry = [
                'id_cart_rule' => $cartRuleId,
                'code' => $rule['code'],
                'reduction_amount' => (float) $rule['reduction_amount'],
                'reduction_currency' => (int) $rule['reduction_currency'],
                'description' => $rule['description'],
                'date_from' => $rule['date_from'],
                'date_to' => $rule['date_to'],
                'quantity' => (int) $rule['quantity'],
                'active' => (int) $rule['active'],
                'order_detail' => $orderDetail,
                'consumed' => $consumption['total_consumed'],
                'remaining' => (float) $rule['reduction_amount'] - $consumption['total_consumed'],
                'consumption_orders' => $consumption['orders'],
            ];

            if (in_array($cartRuleId, $existingCartRuleIds)) {
                $existing[] = $entry;
            } else {
                $missing[] = $entry;
            }
        }

        // Check for orphaned giftcard entries (entry exists but cart_rule doesn't match)
        foreach ($existingEntries as $gc) {
            $found = false;
            foreach ($giftCardRules as $rule) {
                if ((int) $rule['id_cart_rule'] === (int) $gc['id_cart_rule']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $orphaned[] = $gc;
            }
        }

        $status = 'ok';
        if (count($missing) > 0) {
            $status = 'error';
        } elseif (count($orphaned) > 0) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'total_cart_rules' => count($giftCardRules),
            'existing_entries' => count($existing),
            'missing_entries' => count($missing),
            'orphaned_entries' => count($orphaned),
            'missing' => $missing,
            'existing' => $existing,
            'orphaned' => $orphaned,
        ];
    }

    private function previewRebuild(): array
    {
        $diagnosis = $this->diagnose();

        $toInsert = [];
        foreach ($diagnosis['missing'] as $entry) {
            $item = [
                'id_cart_rule' => $entry['id_cart_rule'],
                'code' => $entry['code'],
                'amount' => $entry['reduction_amount'],
                'description' => $entry['description'],
                'can_fix' => false,
                'reason' => null,
            ];

            if ($entry['order_detail'] !== null) {
                $item['can_fix'] = true;
                $item['id_order_detail'] = (int) $entry['order_detail']['id_order_detail'];
                $item['id_order'] = (int) $entry['order_detail']['id_order'];
                $item['order_reference'] = $entry['order_detail']['reference'] ?? '';
            } else {
                $item['reason'] = 'Aucun order_detail correspondant trouve pour le produit ' . $this->giftCardProductId;
            }

            $toInsert[] = $item;
        }

        $fixableCount = count(array_filter($toInsert, fn ($i) => $i['can_fix']));

        return [
            'success' => true,
            'type' => 'giftcard_rebuild',
            'description' => 'Reconstruction des entrees manquantes dans la table giftcard',
            'changes' => [
                'total_gift_card_rules' => $diagnosis['total_cart_rules'],
                'already_in_giftcard_table' => $diagnosis['existing_entries'],
                'missing_from_giftcard_table' => $diagnosis['missing_entries'],
                'fixable' => $fixableCount,
                'not_fixable' => $diagnosis['missing_entries'] - $fixableCount,
                'orphaned_entries' => $diagnosis['orphaned_entries'],
            ],
            'entries_to_insert' => $toInsert,
            'existing' => $diagnosis['existing'],
            'balance_summary' => $this->buildBalanceSummary($diagnosis),
        ];
    }

    private function applyRebuild(): array
    {
        try {
            $diagnosis = $this->diagnose();
            $inserted = 0;
            $skipped = 0;
            $details = [];

            foreach ($diagnosis['missing'] as $entry) {
                $orderDetail = $entry['order_detail'];

                if ($orderDetail === null) {
                    ++$skipped;
                    $details[] = [
                        'id_cart_rule' => $entry['id_cart_rule'],
                        'code' => $entry['code'],
                        'action' => 'skipped',
                        'reason' => 'No matching order_detail found',
                    ];
                    continue;
                }

                $idImage = $this->findImageForOrderDetail($orderDetail);

                $this->connection->executeStatement(
                    "INSERT INTO {$this->prefix}giftcard
                    (id_order_detail, id_cart_rule, id_image, id_customization, sent)
                    VALUES (?, ?, ?, ?, ?)",
                    [
                        (int) $orderDetail['id_order_detail'],
                        (int) $entry['id_cart_rule'],
                        $idImage,
                        (int) ($orderDetail['id_customization'] ?? 0),
                        1, // sent = 1 (already delivered, this is historical data)
                    ]
                );

                ++$inserted;
                $details[] = [
                    'id_cart_rule' => $entry['id_cart_rule'],
                    'code' => $entry['code'],
                    'id_order_detail' => (int) $orderDetail['id_order_detail'],
                    'id_order' => (int) $orderDetail['id_order'],
                    'amount' => $entry['reduction_amount'],
                    'action' => 'inserted',
                ];
            }

            return [
                'success' => true,
                'type' => 'giftcard_rebuild',
                'inserted' => $inserted,
                'skipped' => $skipped,
                'details' => $details,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all cart_rules that are gift cards (identified by name "La carte cadeau" in cart_rule_lang)
     *
     * @return array<int, array<string, mixed>>
     */
    private function getGiftCardCartRules(): array
    {
        return $this->connection->fetchAllAssociative("
            SELECT DISTINCT cr.id_cart_rule, cr.code, cr.reduction_amount,
                   cr.reduction_currency, cr.description, cr.date_from, cr.date_to,
                   cr.quantity, cr.active, cr.partial_use, cr.id_customer
            FROM {$this->prefix}cart_rule cr
            INNER JOIN {$this->prefix}cart_rule_lang crl
                ON cr.id_cart_rule = crl.id_cart_rule
            WHERE crl.name = ?
              AND crl.id_lang = 1
              AND cr.reduction_amount > 0
            ORDER BY cr.id_cart_rule
        ", [$this->giftCardCartRuleName]);
    }

    /**
     * Get existing entries from the giftcard table
     *
     * @return array<int, array<string, mixed>>
     */
    private function getExistingGiftCardEntries(): array
    {
        return $this->connection->fetchAllAssociative("
            SELECT gc.id_giftcard, gc.id_order_detail, gc.id_cart_rule,
                   gc.id_image, gc.id_customization, gc.sent
            FROM {$this->prefix}giftcard gc
        ");
    }

    /**
     * Find the order_detail for a gift card cart_rule
     * Uses the description field which contains "Order XXX"
     *
     * @return array<string, mixed>|null
     */
    private function findOrderDetailForCartRule(array $rule): ?array
    {
        // Extract order ID from description (format: "Order XXX")
        $orderId = null;
        if (preg_match('/Order\s+(\d+)/', $rule['description'] ?? '', $matches)) {
            $orderId = (int) $matches[1];
        }

        if ($orderId !== null) {
            $result = $this->connection->fetchAssociative("
                SELECT od.id_order_detail, od.id_order, od.product_attribute_id,
                       o.reference, od.id_customization
                FROM {$this->prefix}order_detail od
                INNER JOIN {$this->prefix}orders o ON od.id_order = o.id_order
                WHERE od.product_id = ?
                  AND o.id_order = ?
                LIMIT 1
            ", [$this->giftCardProductId, $orderId]);

            if ($result) {
                return $result;
            }
        }

        // Fallback: try to match by cart_rule date and customer
        if (!empty($rule['id_customer'])) {
            $result = $this->connection->fetchAssociative("
                SELECT od.id_order_detail, od.id_order, od.product_attribute_id,
                       o.reference, od.id_customization
                FROM {$this->prefix}order_detail od
                INNER JOIN {$this->prefix}orders o ON od.id_order = o.id_order
                WHERE od.product_id = ?
                  AND o.id_customer = ?
                  AND o.date_add >= DATE_SUB(?, INTERVAL 1 DAY)
                  AND o.date_add <= DATE_ADD(?, INTERVAL 1 DAY)
                ORDER BY o.date_add DESC
                LIMIT 1
            ", [
                $this->giftCardProductId,
                (int) $rule['id_customer'],
                $rule['date_from'],
                $rule['date_from'],
            ]);

            if ($result) {
                return $result;
            }
        }

        // Last fallback: match by date proximity only
        $result = $this->connection->fetchAssociative("
            SELECT od.id_order_detail, od.id_order, od.product_attribute_id,
                   o.reference, od.id_customization
            FROM {$this->prefix}order_detail od
            INNER JOIN {$this->prefix}orders o ON od.id_order = o.id_order
            WHERE od.product_id = ?
              AND o.date_add >= DATE_SUB(?, INTERVAL 1 DAY)
              AND o.date_add <= DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY o.date_add DESC
            LIMIT 1
        ", [$this->giftCardProductId, $rule['date_from'], $rule['date_from']]);

        return $result ?: null;
    }

    /**
     * Find the image ID for a gift card order detail
     */
    private function findImageForOrderDetail(array $orderDetail): int
    {
        // Try to get image from product_attribute_image
        if (!empty($orderDetail['product_attribute_id'])) {
            $imageId = $this->connection->fetchOne("
                SELECT pai.id_image
                FROM {$this->prefix}product_attribute_image pai
                WHERE pai.id_product_attribute = ?
                LIMIT 1
            ", [(int) $orderDetail['product_attribute_id']]);

            if ($imageId) {
                return (int) $imageId;
            }
        }

        // Fallback: get the cover image for product 857
        $imageId = $this->connection->fetchOne("
            SELECT i.id_image
            FROM {$this->prefix}image i
            WHERE i.id_product = ?
            ORDER BY i.cover DESC, i.position ASC
            LIMIT 1
        ", [$this->giftCardProductId]);

        return $imageId ? (int) $imageId : 0;
    }

    /**
     * Get consumption data for a gift card code (including partial-use children like CODE-2, CODE-3)
     *
     * @return array{total_consumed: float, orders: array<int, array<string, mixed>>}
     */
    private function getConsumption(string $code): array
    {
        $orders = $this->connection->fetchAllAssociative("
            SELECT ocr.id_order, ocr.value, o.id_currency, o.reference
            FROM {$this->prefix}cart_rule cr
            INNER JOIN {$this->prefix}order_cart_rule ocr ON cr.id_cart_rule = ocr.id_cart_rule
            INNER JOIN {$this->prefix}orders o ON ocr.id_order = o.id_order
            WHERE cr.code LIKE ?
            ORDER BY ocr.id_order_cart_rule
        ", [$code . '%']);

        $totalConsumed = 0.0;
        foreach ($orders as $order) {
            $totalConsumed += (float) $order['value'];
        }

        return [
            'total_consumed' => $totalConsumed,
            'orders' => $orders,
        ];
    }

    /**
     * Build a balance summary for all gift cards
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildBalanceSummary(array $diagnosis): array
    {
        $summary = [];

        $allCards = array_merge($diagnosis['existing'], $diagnosis['missing']);
        foreach ($allCards as $card) {
            $summary[] = [
                'code' => $card['code'],
                'amount' => $card['reduction_amount'],
                'consumed' => $card['consumed'],
                'remaining' => $card['remaining'],
                'status' => $card['remaining'] > 0 ? 'active' : 'used',
                'in_giftcard_table' => in_array($card, $diagnosis['existing'], true),
            ];
        }

        return $summary;
    }
}
