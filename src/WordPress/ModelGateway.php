<?php declare(strict_types=1);

namespace TaxmodSales\WordPress;

use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\WordPress\ModelDoor;
use TaxmodSales\Core\SalesError;

/**
 * The module's side of the modeller's one door into the model (`Taxmod\WordPress\ModelDoor`, D-1186): it only passes the
 * calls on and words a refusal as {@see SalesError}.
 */
final class ModelGateway
{
    private readonly ModelDoor $door;

    public function __construct()
    {
        $this->door = new ModelDoor();
    }

    /** @return array<int, array<int, list<string>>> record id → relation id → values */
    public function records(int $node): array
    {
        return $this->ask(fn (): array => $this->door->records($node));
    }

    /**
     * The modeller's own control for these fields (D-1250) — the material is chosen with the modeller's record choice.
     *
     * @param list<int> $relations
     * @return array<int, string> relation id → control markup, escaped
     */
    public function controls(int $node, array $relations, string $prefix, string $formId, int $record): array
    {
        return $this->door->controls($node, $relations, $prefix, $formId, $record);
    }

    /**
     * @param array<int|string, mixed> $submitted `$_POST[$prefix]`
     * @return array<int, string> relation id → raw value
     */
    public function typed(int $node, array $submitted): array
    {
        return $this->ask(fn (): array => $this->door->typed($node, $submitted));
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer
     */
    public function write(array $changes): array
    {
        return $this->ask(fn (): array => $this->door->write($changes));
    }

    /**
     * @param callable(): array<mixed> $read
     * @return array<mixed>
     */
    private function ask(callable $read): array
    {
        try {
            return $read();
        } catch (ModelDoorRefused $refused) {
            throw SalesError::fromModel($refused->getMessage());
        }
    }
}
