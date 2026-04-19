<?php

namespace App\Services\Ebay;

class EbayRowsFormatter
{
    /**
     * Transform normalized eBay listing data into a flat array of rows.
     */
    public function format(array $normalized): array
    {
        if (isset($normalized['error'])) {
            return $normalized;
        }

        $sku = $normalized['sku'] ?? '';
        $rows = [];

        // 1. Main Listing Row
        $rows[] = [
            'Action' => 'Add',
            'Custom label' => $sku,
            'Category ID' => $normalized['category_id'] ?? '',
            'Category name' => $normalized['category_name'] ?? '',
            'Title' => $normalized['title'] ?? '',
            'UPC' => $normalized['upc'] ?? '',
            'EPID' => $normalized['epid'] ?? '',
            'Start price' => $normalized['price'] ?? 0,
            'Quantity' => $normalized['quantity'] ?? 0,
            'Item photo URL' => implode('|', (array)($normalized['images'] ?? [])),
            'Condition ID' => $normalized['condition_id'] ?? '',
            'Description' => $normalized['description'] ?? '',
            'Format' => $normalized['format'] ?? '',
            'Duration' => $normalized['duration'] ?? '',
            'Buy It Now price' => $normalized['buy_it_now_price'] ?? 0,
            'Location' => $normalized['location'] ?? '',
            'Brand' => $normalized['brand'] ?? '',
            'Manufacturer Part Number' => $normalized['manufacturer_part_number'] ?? '',
            'OE/OEM Part Number' => $normalized['oe_oem_part_number'] ?? '',
            'Placement on Vehicle' => $normalized['placement_on_vehicle'] ?? '',
            'Warranty' => $normalized['warranty'] ?? '',
            'Type' => $normalized['type'] ?? '',
            'Part Type' => $normalized['part_type'] ?? '',
            'Compatible Brand' => $normalized['compatible_brand'] ?? '',
            'Features' => $normalized['features'] ?? '',
            'Color' => $normalized['color'] ?? '',
            'Country/Region of Manufacture' => $normalized['country_region_of_manufacture'] ?? '',
            'Country of Origin' => $normalized['country_of_origin'] ?? '',
            'Model Variations' => $normalized['model_variations'] ?? '',
            'Superseded Part Number' => $normalized['superseded_part_number'] ?? '',
            'Interchange Part Number' => $normalized['interchange_part_number'] ?? '',
            'MPN' => $normalized['mpn'] ?? '',
        ];

        // 2. Compatibility Rows
        if (!empty($normalized['compatibility'])) {
            foreach ($normalized['compatibility'] as $comp) {
                $details = [];
                
                // Prioritize common fields in a specific order
                $order = ['Make', 'Model', 'Year', 'Submodel'];
                $addedKeys = [];

                foreach ($order as $key) {
                    if (!empty($comp[$key])) {
                        $details[] = "$key=" . $comp[$key];
                        $addedKeys[] = $key;
                    }
                }
                
                // Add any other fields (excluding notes and already added keys)
                foreach ($comp as $key => $value) {
                    if ($key !== 'notes' && !in_array($key, $addedKeys) && !empty($value)) {
                        $details[] = "$key=$value";
                    }
                }

                $rows[] = [
                    'Action' => 'AddRelation',
                    'Custom label' => $sku,
                    'Relationship' => 'Compatibility',
                    'Relationship details' => implode('|', $details),
                ];
            }
        }

        return [
            'sku' => $sku,
            'rows' => $rows,
        ];
    }
}
