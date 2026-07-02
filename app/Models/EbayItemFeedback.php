<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EbayItemFeedback extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ebay_item_feedbacks';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ebay_item_id',
        'feedback_id',
        'commenting_user',
        'commenting_user_score',
        'comment_text',
        'comment_time',
        'comment_type',
        'role',
        'transaction_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'comment_time' => 'datetime',
            'commenting_user_score' => 'integer',
        ];
    }
}
