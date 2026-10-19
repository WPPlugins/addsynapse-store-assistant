<?php

declare(strict_types=1);

namespace Addsynapse\ConnectorCore\Changeset;

// No direct access: loaded only through a connector (WordPress: ABSPATH; others: the core autoloader).
if (!defined('ABSPATH')) {
    defined('ADDSYNAPSE_CONNECTOR_CORE_DIR') || exit;
}

/**
 * Conflict detection of ONE unit (a product, a variation, a page…): all its items are checked together
 * against the CURRENT values and written with one save, so a unit is never left half-changed.
 *
 * apply:  current == after  → done (already in the approved state — retries are harmless)
 *         current == before → write
 *         else              → conflict (someone changed it since the preview: the WHOLE unit is skipped)
 * revert: current == before → done;  current == after → write;  else → revert conflict.
 */
final class UnitDecision
{
    public const APPLY = 'apply';
    public const REVERT = 'revert';

    /**
     * @param array<int,array<string,mixed>> $items each with id, field, before, after
     * @param callable(string):mixed          $read  field → current value
     * @param callable(string,mixed,mixed):bool $same field, a, b → equal (platform comparison)
     * @return array{write:array<int,array<string,mixed>>,done:array<int,array<string,mixed>>,conflicts:array<int|string,mixed>}
     *         conflicts: item id → current value
     */
    public static function decide(array $items, string $mode, callable $read, callable $same): array
    {
        $target = $mode === self::REVERT ? 'before' : 'after';
        $origin = $mode === self::REVERT ? 'after' : 'before';
        $write = [];
        $done = [];
        $conflicts = [];
        foreach ($items as $item) {
            $field = (string) $item['field'];
            $current = $read($field);
            if ($same($field, $current, $item[$target] ?? null)) {
                $done[] = $item;
            } elseif ($same($field, $current, $item[$origin] ?? null)) {
                $write[] = $item;
            } else {
                $conflicts[$item['id']] = $current;
            }
        }

        return [
            'write' => $write,
            'done' => $done,
            'conflicts' => $conflicts,
        ];
    }
}
