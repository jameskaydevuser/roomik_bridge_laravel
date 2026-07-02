<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbayItemFeedbackFetch extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ebay_item_feedback_fetches';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'ebay_item_id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ebay_item_id',
        'last_fetched_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_fetched_at' => 'datetime',
        ];
    }
}
