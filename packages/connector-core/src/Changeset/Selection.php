<?php

declare(strict_types=1);

namespace Addsynapse\ConnectorCore\Changeset;

// No direct access: loaded only through a connector (WordPress: ABSPATH; others: the core autoloader).
if (!defined('ABSPATH')) {
    defined('ADDSYNAPSE_CONNECTOR_CORE_DIR') || exit;
}

/**
 * Partial approval: which units an approval applies.
 *
 * A unit is identified by a TYPED key "<object_type>:<object_id>" (e.g. "product:12", "variation:7",
 * "combination:7") — ids of different object types may collide (PrestaShop: id_product vs
 * id_product_attribute), so an id alone never selects a unit (2026-09-27).
 * Backward compatibility: an untyped id (int or digits, from older links/clients) means "product:<id>" ONLY —
 * never "whatever unit has this id".
 *
 *  - nothing requested → null (= all);
 *  - every requested key must belong to the changeset (else `foreign_selection`, nothing happens);
 *  - a malformed key → `foreign_selection`;
 *  - an empty request → `nothing_selected`;
 *  - all units requested → null (a normal approval).
 * Item ids select their whole unit (one product = one unit) — the caller resolves them to unit keys.
 */
final class Selection
{
    public const NOTHING_SELECTED = 'nothing_selected';
    public const FOREIGN = 'foreign_selection';
    /** The type an untyped (legacy) id stands for. */
    public const PRODUCT = 'product';
    /** A typed unit key: lowercase type, ":", positive integer id. */
    public const KEY_PATTERN = '/^([a-z][a-z_]{0,31}):([1-9][0-9]{0,17})$/D';

    public static function key(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    /**
     * A requested unit → its typed key; untyped ids → "product:<id>"; anything else → null (invalid).
     *
     * @param mixed $raw
     */
    public static function normalize($raw): ?string
    {
        if (is_int($raw)) {
            return $raw > 0 ? self::key(self::PRODUCT, $raw) : null;
        }
        if (!is_string($raw)) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $raw)) {
            return self::key(self::PRODUCT, (int) $raw);
        }

        return preg_match(self::KEY_PATTERN, $raw) ? $raw : null;
    }

    /**
     * @return array{type:string,id:int}|null
     */
    public static function parse(string $key): ?array
    {
        $key = self::normalize($key);
        if ($key === null || !preg_match(self::KEY_PATTERN, $key, $m)) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2]];
    }

    /**
     * A requested list → typed keys (unique, request order); null when any entry is invalid.
     *
     * @param array<int,mixed> $raw
     * @return array<int,string>|null
     */
    public static function keys(array $raw): ?array
    {
        $out = [];
        foreach ($raw as $entry) {
            $key = self::normalize($entry);
            if ($key === null) {
                return null;
            }
            $out[$key] = $key;
        }

        return array_values($out);
    }

    /**
     * @param array<int,string|int>      $all       unit keys of the changeset (ints = legacy product ids)
     * @param array<int,string|int>|null $objectIds requested unit keys (ints/digits = legacy product ids)
     * @param array<int,string>|null     $itemUnits unit keys of the requested item ids (null = none requested)
     * @param bool                       $itemsOk   every requested item id belongs to the changeset
     * @return array{keep:array<int,string>|null,error:string}
     */
    public static function resolve(array $all, ?array $objectIds, ?array $itemUnits = null, bool $itemsOk = true): array
    {
        if ($objectIds === null && $itemUnits === null) {
            return ['keep' => null, 'error' => ''];
        }
        $selected = self::keys((array) $objectIds);
        if ($selected === null) {
            return ['keep' => null, 'error' => self::FOREIGN];
        }
        if ($itemUnits !== null) {
            if (!$itemsOk) {
                return ['keep' => null, 'error' => self::FOREIGN];
            }
            $units = self::keys($itemUnits);
            if ($units === null) {
                return ['keep' => null, 'error' => self::FOREIGN];
            }
            $selected = array_values(array_unique(array_merge($selected, $units)));
        }
        if (!$selected) {
            return ['keep' => null, 'error' => self::NOTHING_SELECTED];
        }
        $all = (array) self::keys($all);
        if (array_diff($selected, $all)) {
            return ['keep' => null, 'error' => self::FOREIGN];
        }

        return ['keep' => count($selected) === count(array_unique($all)) ? null : $selected, 'error' => ''];
    }
}
