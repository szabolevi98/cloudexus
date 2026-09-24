<?php

namespace Cloudexus\Model\Core;

/**
 * Nincs elég készlet egy kiadáshoz. A zárolt tranzakción belül dobja a
 * StockMovementModel::assertAvailable(), így a hívó visszagörget, és a
 * felületen termékenként meg tudja mondani, mennyi van és mennyi kellett.
 */
final class StockShortage extends \DomainException
{
    /** @param array<int, array{available: float, requested: float}> $shortages termék id szerint */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Not enough stock for ' . count($shortages) . ' product(s).');
    }

    /** @return array{available: float, requested: float} az első hiányzó termék */
    public function first(): array
    {
        return $this->shortages[array_key_first($this->shortages)];
    }
}
