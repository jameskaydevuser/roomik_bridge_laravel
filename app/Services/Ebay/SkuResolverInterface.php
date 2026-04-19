<?php

namespace App\Services\Ebay;

interface SkuResolverInterface
{
    /**
     * Resolve a SKU to one or more eBay Item IDs.
     * 
     * @param string $sku
     * @return array
     */
    public function resolve(string $sku): array;
}
