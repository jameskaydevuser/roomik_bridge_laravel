<?php

namespace App\Services\Ebay;

class EbayListingTransformer
{
    /**
     * Transform eBay Trading API GetItem response to normalized structure.
     */
    public function transform($ebayItem): array
    {
        if (!$ebayItem) return [];

        $item = (array) $ebayItem->Item;

        // Extract Item Specifics
        $specifics = [];
        if (isset($ebayItem->Item->ItemSpecifics->NameValueList)) {
            foreach ($ebayItem->Item->ItemSpecifics->NameValueList as $nv) {
                $specifics[(string)$nv->Name] = (string)$nv->Value;
            }
        }

        // Extract Compatibility
        $compatibility = [];
        if (isset($ebayItem->Item->ItemCompatibilityList->Compatibility)) {
            foreach ($ebayItem->Item->ItemCompatibilityList->Compatibility as $comp) {
                $compData = [
                    'notes' => (string)$comp->CompatibilityNotes,
                ];
                foreach ($comp->NameValueList as $nv) {
                    $compData[(string)$nv->Name] = (string)$nv->Value;
                }
                $compatibility[] = $compData;
            }
        }

        return [
            'sku' => (string)($item['SKU'] ?? ($specifics['SKU'] ?? '')),
            'item_id' => (string)($item['ItemID'] ?? ''),
            'title' => (string)($item['Title'] ?? ''),
            'category_id' => (string)($item['PrimaryCategory']->CategoryID ?? ''),
            'category_name' => (string)($item['PrimaryCategory']->CategoryName ?? ''),
            'upc' => (string)($item['ProductListingDetails']->UPC ?? ''),
            'epid' => (string)($item['ProductListingDetails']->ProductReferenceID ?? ''),
            'price' => (float)($item['StartPrice'] ?? 0),
            'quantity' => (int)($item['Quantity'] ?? 0),
            'images' => isset($item['PictureDetails']->PictureURL) ? (array)$item['PictureDetails']->PictureURL : [],
            'condition_id' => (string)($item['ConditionID'] ?? ''),
            'description' => (string)($item['Description'] ?? ''),
            'format' => (string)($item['ListingType'] ?? ''),
            'duration' => (string)($item['ListingDuration'] ?? ''),
            'buy_it_now_price' => (float)($item['BuyItNowPrice'] ?? 0),
            'location' => (string)($item['Location'] ?? ''),
            'brand' => $specifics['Brand'] ?? '',
            'manufacturer_part_number' => $specifics['MPN'] ?? $specifics['Manufacturer Part Number'] ?? '',
            'oe_oem_part_number' => $specifics['OE/OEM Part Number'] ?? '',
            'placement_on_vehicle' => $specifics['Placement on Vehicle'] ?? '',
            'warranty' => $specifics['Warranty'] ?? '',
            'type' => $specifics['Type'] ?? '',
            'part_type' => $specifics['Part Type'] ?? '',
            'compatible_brand' => $specifics['Compatible Brand'] ?? '',
            'features' => $specifics['Features'] ?? '',
            'color' => $specifics['Color'] ?? '',
            'country_region_of_manufacture' => $specifics['Country/Region of Manufacture'] ?? '',
            'country_of_origin' => $specifics['Country of Origin'] ?? '',
            'model_variations' => $specifics['Model Variations'] ?? '',
            'superseded_part_number' => $specifics['Superseded Part Number'] ?? '',
            'interchange_part_number' => $specifics['Interchange Part Number'] ?? '',
            'mpn' => $specifics['MPN'] ?? '',
            'item_specifics' => $specifics,
            'compatibility' => $compatibility,
        ];
    }
}
