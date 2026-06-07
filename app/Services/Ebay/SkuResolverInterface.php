<?php

namespace App\Services\Ebay;

interface SkuResolverInterface
{
    /**
     * Resolve a SKU to one or more eBay Item IDs.
     *
     * @return string[]
     */
    public function resolve(string $sku): array;

    /**
     * Resolve Item IDs by matching the listing Custom label (eBay SKU field).
     *
     * @return string[]
     */
    public function resolveByCustomLabel(string $sku): array;
}
