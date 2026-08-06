<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbayItem extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ebay_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ebay_item_id',
        'sku',
    ];

    /**
     * Save or update a SKU for an eBay Item ID, avoiding overwriting existing SKUs with empty values.
     */
    public static function saveOrUpdateSku(string $itemId, ?string $sku): void
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return;
        }

        $attributes = ['ebay_item_id' => $itemId];
        $values = [];
        
        $sku = $sku !== null ? trim($sku) : '';
        if ($sku !== '') {
            $values['sku'] = $sku;
        }

        self::updateOrCreate($attributes, $values);
    }
}
