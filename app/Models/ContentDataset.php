<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentDataset extends Model
{
    protected $fillable = [
        'dataset_key',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
