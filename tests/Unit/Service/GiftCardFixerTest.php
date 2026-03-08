<?php

declare(strict_types=1);

namespace ScGiftcardRepair\Tests\Unit\Service;

use ScGiftcardRepair\Service\GiftCardFixer;

/**
 * Unit tests for GiftCardFixer
 *
 * DB call order inside diagnose():
 *   [fetchAll #1] getGiftCardCartRules()
 *   [fetchAll #2] getExistingGiftCardEntries()
 *   For each cart_rule:
 *     [fetchAssoc #N] findOrderDetailForCartRule() – primary lookup by Order-ID in description
 *     [fetchAll  #N] getConsumption()
 *
 * preview() and apply() both call diagnose() first.
 * apply() additionally calls findImageForOrderDetail() (fetchOne) and executeStatement() per entry.
 */
class GiftCardFixerTest extends AbstractServiceTestCase
{
    private GiftCardFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new GiftCardFixer($this->connection, $this->prefix);
    }

    // -----------------------------------------------------------------------
    // getSupportedTypes
    // -----------------------------------------------------------------------

    public function testGetSupportedTypesReturnsGiftcardRebuild(): void
    {
        $this->assertSame(['giftcard_rebuild'], $this->fixer->getSupportedTypes());
    }

    // -----------------------------------------------------------------------
    // diagnose – helpers
    // -----------------------------------------------------------------------

    /**
     * Build a minimal cart_rule row as returned by getGiftCardCartRules()
     */
    private function makeRule(int $id, string $code, float $amount = 50.0, string $description = 'Order 100'): array
    {
        return [
            'id_cart_rule'       => $id,
            'code'               => $code,
            'reduction_amount'   => $amount,
            'reduction_currency' => 1,
            'description'        => $description,
            'date_from'          => '2024-01-01 00:00:00',
            'date_to'            => '2025-01-01 00:00:00',
            'quantity'           => 1,
            'active'             => 1,
            'partial_use'        => 0,
            'id_customer'        => 42,
        ];
    }

    /**
     * Build a minimal order_detail row as returned by findOrderDetailForCartRule()
     */
    private function makeOrderDetail(int $idOrderDetail, int $idOrder, string $reference = 'REF001', int $idCustomization = 0, int $idAttribut = 0): array
    {
        return [
            'id_order_detail'       => $idOrderDetail,
            'id_order'              => $idOrder,
            'product_attribute_id'  => $idAttribut,
            'reference'             => $reference,
            'id_customization'      => $idCustomization,
        ];
    }

    /**
     * Build an existing giftcard row
     */
    private function makeGiftCardEntry(int $idGiftcard, int $idOrderDetail, int $idCartRule): array
    {
        return [
            'id_giftcard'       => $idGiftcard,
            'id_order_detail'   => $idOrderDetail,
            'id_cart_rule'      => $idCartRule,
            'id_image'          => 0,
            'id_customization'  => 0,
            'sent'              => 1,
        ];
    }

    // -----------------------------------------------------------------------
    // diagnose – status = ok (all cart_rules already in giftcard table)
    // -----------------------------------------------------------------------

    public function testDiagnoseReturnsOkWhenAllEntriesExist(): void
    {
        $rule = $this->makeRule(10, 'GC-AAA');

        // fetchAll #1 – cart_rules
        // fetchAll #2 – giftcard entries
        // fetchAssoc #1 – findOrderDetailForCartRule (primary lookup, succeeds)
        // fetchAll #3 – getConsumption (0 consumed)
        $this->mockFetchAllSequence([
            [$rule],
            [$this->makeGiftCardEntry(1, 99, 10)],
            // consumption for GC-AAA
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(99, 100),
        ]);

        $result = $this->fixer->diagnose();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, $result['total_cart_rules']);
        $this->assertSame(1, $result['existing_entries']);
        $this->assertSame(0, $result['missing_entries']);
        $this->assertSame(0, $result['orphaned_entries']);
        $this->assertEmpty($result['missing']);
        $this->assertCount(1, $result['existing']);
    }

    // -----------------------------------------------------------------------
    // diagnose – status = error (missing entries)
    // -----------------------------------------------------------------------

    public function testDiagnoseReturnsErrorWhenEntriesMissing(): void
    {
        $rule = $this->makeRule(10, 'GC-BBB');

        // fetchAll #1 – cart_rules (1 rule)
        // fetchAll #2 – giftcard entries (empty)
        // fetchAssoc #1 – findOrderDetailForCartRule (found)
        // fetchAll #3 – consumption (empty)
        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(55, 200, 'REF-X'),
        ]);

        $result = $this->fixer->diagnose();

        $this->assertSame('error', $result['status']);
        $this->assertSame(1, $result['missing_entries']);
        $this->assertCount(1, $result['missing']);
        $this->assertSame(10, $result['missing'][0]['id_cart_rule']);
        $this->assertSame('GC-BBB', $result['missing'][0]['code']);
    }

    // -----------------------------------------------------------------------
    // diagnose – status = warning (orphaned entries)
    // -----------------------------------------------------------------------

    public function testDiagnoseReturnsWarningWhenOrphanedEntryExists(): void
    {
        // No cart_rules at all, but giftcard table has an entry → orphaned
        $orphanedEntry = $this->makeGiftCardEntry(5, 77, 999);

        // fetchAll #1 – cart_rules (empty)
        // fetchAll #2 – giftcard entries (one orphan)
        $this->mockFetchAllSequence([
            [],
            [$orphanedEntry],
        ]);

        $result = $this->fixer->diagnose();

        $this->assertSame('warning', $result['status']);
        $this->assertSame(0, $result['total_cart_rules']);
        $this->assertSame(1, $result['orphaned_entries']);
        $this->assertCount(1, $result['orphaned']);
    }

    // -----------------------------------------------------------------------
    // diagnose – consumption is reflected in remaining balance
    // -----------------------------------------------------------------------

    public function testDiagnoseCalculatesConsumedAndRemaining(): void
    {
        $rule = $this->makeRule(20, 'GC-CCC', 100.0, 'Order 300');

        $consumptionOrders = [
            ['id_order' => 300, 'value' => 30.0, 'id_currency' => 1, 'reference' => 'REF300'],
            ['id_order' => 301, 'value' => 25.0, 'id_currency' => 1, 'reference' => 'REF301'],
        ];

        $this->mockFetchAllSequence([
            [$rule],
            [$this->makeGiftCardEntry(2, 100, 20)],
            $consumptionOrders,
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(100, 300),
        ]);

        $result = $this->fixer->diagnose();

        $existing = $result['existing'][0];
        $this->assertEqualsWithDelta(55.0, $existing['consumed'], 0.001);
        $this->assertEqualsWithDelta(45.0, $existing['remaining'], 0.001);
    }

    // -----------------------------------------------------------------------
    // diagnose – order_detail not found (null)
    // -----------------------------------------------------------------------

    public function testDiagnoseHandlesMissingOrderDetail(): void
    {
        $rule = $this->makeRule(30, 'GC-DDD', 75.0, 'Order 999');

        // fetchAll #1 – cart_rules
        // fetchAll #2 – giftcard entries (empty → missing)
        // fetchAssoc #1 – primary order lookup → not found
        // fetchAssoc #2 – fallback by customer → not found
        // fetchAssoc #3 – fallback by date → not found
        // fetchAll #3 – consumption (empty)
        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            false, // primary lookup fails
            false, // customer fallback fails
            false, // date fallback fails
        ]);

        $result = $this->fixer->diagnose();

        $this->assertSame('error', $result['status']);
        $this->assertNull($result['missing'][0]['order_detail']);
    }

    // -----------------------------------------------------------------------
    // preview – giftcard_rebuild
    // -----------------------------------------------------------------------

    public function testPreviewGiftcardRebuildReturnsFixableEntries(): void
    {
        $rule = $this->makeRule(10, 'GC-EEE', 50.0, 'Order 400');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(77, 400, 'REF400'),
        ]);

        $result = $this->fixer->preview('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame('giftcard_rebuild', $result['type']);
        $this->assertArrayHasKey('changes', $result);
        $this->assertArrayHasKey('entries_to_insert', $result);
        $this->assertArrayHasKey('balance_summary', $result);

        $changes = $result['changes'];
        $this->assertSame(1, $changes['total_gift_card_rules']);
        $this->assertSame(0, $changes['already_in_giftcard_table']);
        $this->assertSame(1, $changes['missing_from_giftcard_table']);
        $this->assertSame(1, $changes['fixable']);
        $this->assertSame(0, $changes['not_fixable']);

        $this->assertCount(1, $result['entries_to_insert']);
        $entry = $result['entries_to_insert'][0];
        $this->assertTrue($entry['can_fix']);
        $this->assertSame(10, $entry['id_cart_rule']);
        $this->assertSame('GC-EEE', $entry['code']);
        $this->assertSame(50.0, $entry['amount']);
        $this->assertSame(77, $entry['id_order_detail']);
        $this->assertSame(400, $entry['id_order']);
        $this->assertSame('REF400', $entry['order_reference']);
    }

    public function testPreviewMarksEntryAsNotFixableWhenNoOrderDetail(): void
    {
        $rule = $this->makeRule(11, 'GC-FFF', 30.0, 'no-order-info');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            false,
            false,
            false,
        ]);

        $result = $this->fixer->preview('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $entry = $result['entries_to_insert'][0];
        $this->assertFalse($entry['can_fix']);
        $this->assertNotNull($entry['reason']);
        $this->assertSame(0, $result['changes']['fixable']);
        $this->assertSame(1, $result['changes']['not_fixable']);
    }

    public function testPreviewUnsupportedTypeReturnsError(): void
    {
        $result = $this->fixer->preview('unknown_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testPreviewShowsNothingToFixWhenTableAlreadyComplete(): void
    {
        $rule = $this->makeRule(10, 'GC-GGG');

        $this->mockFetchAllSequence([
            [$rule],
            [$this->makeGiftCardEntry(1, 88, 10)],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(88, 100),
        ]);

        $result = $this->fixer->preview('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['changes']['missing_from_giftcard_table']);
        $this->assertSame(0, $result['changes']['fixable']);
        $this->assertEmpty($result['entries_to_insert']);
    }

    // -----------------------------------------------------------------------
    // apply – giftcard_rebuild
    // -----------------------------------------------------------------------

    public function testApplyInsertsFixableEntries(): void
    {
        $rule = $this->makeRule(10, 'GC-HHH', 50.0, 'Order 500');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(99, 500, 'REF500', 0, 0),
        ]);

        // findImageForOrderDetail → no product_attribute_id (0) → cover image fallback
        $this->mockFetchOneSequence([42]);

        $this->connection->expects($this->once())
            ->method('executeStatement');

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame('giftcard_rebuild', $result['type']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertCount(1, $result['details']);
        $this->assertSame('inserted', $result['details'][0]['action']);
        $this->assertSame(10, $result['details'][0]['id_cart_rule']);
        $this->assertSame('GC-HHH', $result['details'][0]['code']);
        $this->assertSame(50.0, $result['details'][0]['amount']);
    }

    public function testApplySkipsEntriesWithNoOrderDetail(): void
    {
        $rule = $this->makeRule(20, 'GC-III', 40.0, 'no-match');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            false,
            false,
            false,
        ]);

        $this->connection->expects($this->never())
            ->method('executeStatement');

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('skipped', $result['details'][0]['action']);
    }

    public function testApplyDoesNothingWhenTableAlreadyComplete(): void
    {
        $rule = $this->makeRule(10, 'GC-JJJ');

        $this->mockFetchAllSequence([
            [$rule],
            [$this->makeGiftCardEntry(1, 88, 10)],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(88, 100),
        ]);

        $this->connection->expects($this->never())
            ->method('executeStatement');

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
    }

    public function testApplyHandlesMultipleRulesMixedFixableAndSkipped(): void
    {
        $rule1 = $this->makeRule(1, 'GC-KKK1', 50.0, 'Order 600');
        $rule2 = $this->makeRule(2, 'GC-KKK2', 30.0, 'no-match');

        // fetchAll #1 – cart_rules (2 rules)
        // fetchAll #2 – giftcard entries (empty)
        // fetchAssoc #1 – rule1 order lookup → found
        // fetchAll #3 – rule1 consumption
        // fetchAssoc #2 – rule2 primary → not found
        // fetchAssoc #3 – rule2 customer fallback → not found
        // fetchAssoc #4 – rule2 date fallback → not found
        // fetchAll #4 – rule2 consumption
        $this->mockFetchAllSequence([
            [$rule1, $rule2],
            [],
            [], // rule1 consumption
            [], // rule2 consumption
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(101, 600, 'REF600'), // rule1: found
            false,                                       // rule2: primary
            false,                                       // rule2: customer
            false,                                       // rule2: date
        ]);

        // Only rule1 triggers findImageForOrderDetail (fetchOne for cover image)
        $this->mockFetchOneSequence([0]);

        $this->connection->expects($this->once())
            ->method('executeStatement');

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(2, $result['details']);
    }

    public function testApplyUnsupportedTypeReturnsError(): void
    {
        $result = $this->fixer->apply('unknown_type');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testApplyReturnsErrorOnDatabaseException(): void
    {
        $rule = $this->makeRule(10, 'GC-LLL', 50.0, 'Order 700');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(110, 700, 'REF700'),
        ]);

        // findImageForOrderDetail
        $this->mockFetchOneSequence([0]);

        $this->connection->method('executeStatement')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('DB connection lost', $result['error']);
    }

    // -----------------------------------------------------------------------
    // findImageForOrderDetail – via apply (image from product_attribute_image)
    // -----------------------------------------------------------------------

    public function testApplyUsesProductAttributeImageWhenAvailable(): void
    {
        // rule with product_attribute_id set → fetchOne #1 = image from product_attribute_image
        $rule = $this->makeRule(10, 'GC-MMM', 50.0, 'Order 800');

        $this->mockFetchAllSequence([
            [$rule],
            [],
            [],
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(120, 800, 'REF800', 0, 5), // product_attribute_id = 5
        ]);

        // fetchOne #1 – image from product_attribute_image → returns 77
        $this->mockFetchOneSequence([77]);

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO'),
                $this->callback(function (array $params) {
                    // params: [id_order_detail, id_cart_rule, id_image, id_customization, sent]
                    return $params[2] === 77; // id_image = 77
                })
            );

        $result = $this->fixer->apply('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['inserted']);
    }

    // -----------------------------------------------------------------------
    // balance_summary in preview
    // -----------------------------------------------------------------------

    public function testPreviewBalanceSummaryContainsAllCards(): void
    {
        $rule1 = $this->makeRule(1, 'GC-N1', 100.0, 'Order 900');
        $rule2 = $this->makeRule(2, 'GC-N2', 50.0, 'Order 901');

        // rule1 already in giftcard table, rule2 missing
        $this->mockFetchAllSequence([
            [$rule1, $rule2],
            [$this->makeGiftCardEntry(1, 10, 1)], // rule1 exists
            [], // rule1 consumption
            [], // rule2 consumption
        ]);
        $this->mockFetchAssociativeSequence([
            $this->makeOrderDetail(10, 900),   // rule1 order_detail
            $this->makeOrderDetail(20, 901),   // rule2 order_detail
        ]);

        $result = $this->fixer->preview('giftcard_rebuild');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['balance_summary']);

        $codes = array_column($result['balance_summary'], 'code');
        $this->assertContains('GC-N1', $codes);
        $this->assertContains('GC-N2', $codes);
    }
}
