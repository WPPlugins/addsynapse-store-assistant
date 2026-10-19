<?php

declare(strict_types=1);

namespace Addsynapse\ConnectorCore\Changeset;

// No direct access: loaded only through a connector (WordPress: ABSPATH; others: the core autoloader).
if (!defined('ABSPATH')) {
    defined('ADDSYNAPSE_CONNECTOR_CORE_DIR') || exit;
}

/**
 * Changeset + item statuses and the lifecycle (contract/schemas/changeset-preview.json):
 *
 *   plan ─▶ draft ─[reject]─▶ rejected          draft ─(expires_at)─▶ expired
 *              └─[approve]─▶ applying ─▶ applied | partially_applied | failed
 *                                         └─[revert]─▶ reverting ─▶ reverted
 *
 * There is NO `approved` status: approval is ONE compare-and-swap draft → applying that also records
 * approved_at/approved_by and an `approved` log event.
 */
final class Status
{
    public const DRAFT = 'draft';
    public const APPLYING = 'applying';
    public const APPLIED = 'applied';
    public const PARTIALLY_APPLIED = 'partially_applied';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const REVERTING = 'reverting';
    public const REVERTED = 'reverted';
    public const FAILED = 'failed';

    public const ITEM_PENDING = 'pending';
    public const ITEM_APPLIED = 'applied';
    public const ITEM_CONFLICT = 'conflict';
    public const ITEM_FAILED = 'failed';
    public const ITEM_REVERTED = 'reverted';
    public const ITEM_REVERT_CONFLICT = 'revert_conflict';
    /** Left out by a partial approval: never applied, never reverted (not a problem). */
    public const ITEM_EXCLUDED = 'excluded';

    public const STATUSES = ['draft', 'applying', 'applied', 'partially_applied', 'failed', 'rejected', 'expired', 'reverting', 'reverted'];
    public const ITEM_STATUSES = ['pending', 'applied', 'conflict', 'failed', 'reverted', 'revert_conflict', 'excluded'];

    /** Allowed changeset transitions. */
    public const TRANSITIONS = [
        self::DRAFT => [self::APPLYING, self::REJECTED, self::EXPIRED],
        self::APPLYING => [self::APPLIED, self::PARTIALLY_APPLIED, self::FAILED],
        self::APPLIED => [self::REVERTING],
        self::PARTIALLY_APPLIED => [self::REVERTING],
        self::REVERTING => [self::REVERTED],
    ];

    /** States a revert may start from. */
    public const REVERTIBLE = [self::APPLIED, self::PARTIALLY_APPLIED];

    /** States with work in progress. */
    public const RUNNING = [self::APPLYING, self::REVERTING];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::REJECTED, self::EXPIRED, self::REVERTED, self::FAILED], true);
    }

    /**
     * Final status of an apply run from the item counts: nothing applied → failed; some conflict/failed →
     * partially_applied; else applied. `excluded` items are NOT a problem.
     *
     * @param array<string,int> $counts
     */
    public static function finalApplyStatus(array $counts): string
    {
        $problems = (int) ($counts[self::ITEM_CONFLICT] ?? 0) + (int) ($counts[self::ITEM_FAILED] ?? 0);
        if ((int) ($counts[self::ITEM_APPLIED] ?? 0) === 0) {
            return self::FAILED;
        }

        return $problems > 0 ? self::PARTIALLY_APPLIED : self::APPLIED;
    }

    /**
     * Item counts per status (+ `items` total) of a list of items.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    public static function count(array $items): array
    {
        $counts = array_fill_keys(self::ITEM_STATUSES, 0);
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
        }
        $counts['items'] = count($items);

        return $counts;
    }
}
