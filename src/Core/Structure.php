<?php declare(strict_types=1);

namespace TaxmodSales\Core;

/**
 * Where the sales records live in the model: node and field ids, read from one option.
 *
 * The module never names a node by its label (code standard: no special-casing by name); every id it reads or writes comes
 * from here. `structure-live.json` holds the ids of fambach.net.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/verkauf/konzept.md §5
 */
final class Structure
{
    /** D-934: a sale and its sold items; D-1257: the material node a sold item points to. */
    public const NODES = ['sale', 'sold_item', 'material'];

    public const FIELDS = ['label', 'sale_number', 'sale_platform', 'sale_buyer', 'sale_date', 'sale_items', 'item_count',
        // D-1260: what a sold item is and the lot it left — both new on «Verkaufte Artikel».
        'item_material', 'item_lot'];

    /**
     * @param array<string, int> $nodes
     * @param array<string, int> $fields
     */
    private function __construct(private readonly array $nodes, private readonly array $fields)
    {
    }

    /** @param array<string, mixed> $raw the decoded JSON or the stored option */
    public static function fromArray(array $raw): self
    {
        return new self(self::ids($raw, 'nodes', self::NODES), self::ids($raw, 'fields', self::FIELDS));
    }

    /**
     * The stored ids, completed by the bundled ones where both agree on every id they share — so a release that adds ids
     * needs no new paste, and a structure of another site stays as it is.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $bundled
     * @return array<string, mixed>
     */
    public static function completed(array $stored, array $bundled): array
    {
        foreach (['nodes', 'fields'] as $group) {
            foreach (is_array($stored[$group] ?? null) ? $stored[$group] : [] as $key => $id) {
                if (isset($bundled[$group][$key]) && (int) $bundled[$group][$key] !== (int) $id) {
                    return $stored;
                }
            }
        }

        foreach (['nodes', 'fields'] as $group) {
            $stored[$group] = (is_array($stored[$group] ?? null) ? $stored[$group] : []) + (is_array($bundled[$group] ?? null) ? $bundled[$group] : []);
        }

        return $stored;
    }

    /** @return list<string> the keys still missing, e.g. «fields.item_material» */
    public function missing(): array
    {
        $out = [];

        foreach (self::NODES as $key) {
            isset($this->nodes[$key]) || $out[] = 'nodes.' . $key;
        }

        foreach (self::FIELDS as $key) {
            isset($this->fields[$key]) || $out[] = 'fields.' . $key;
        }

        return $out;
    }

    public function node(string $key): int
    {
        return $this->nodes[$key] ?? throw SalesError::notConfigured('nodes.' . $key);
    }

    public function field(string $key): int
    {
        return $this->fields[$key] ?? throw SalesError::notConfigured('fields.' . $key);
    }

    /** @return array{nodes: array<string, int>, fields: array<string, int>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'fields' => $this->fields];
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $keys
     * @return array<string, int>
     */
    private static function ids(array $raw, string $group, array $keys): array
    {
        $given = is_array($raw[$group] ?? null) ? $raw[$group] : [];
        $out   = [];

        foreach ($keys as $key) {
            $id = (int) ($given[$key] ?? 0);

            if ($id > 0) {
                $out[$key] = $id;
            }
        }

        return $out;
    }
}
