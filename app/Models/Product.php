<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'code', 'description', 'section_id', 'total', 'price'
    ];

//    protected $casts = [
//        'preorder' => 'boolean',
//        'in_stock' => 'boolean',
//    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

}
