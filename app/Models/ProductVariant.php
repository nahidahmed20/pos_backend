<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'product_id', 'name', 'attributes', 'sku', 'barcode', 'purchase_price',
        'wholesale_price', 'selling_price', 'min_selling_price', 'image_path', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'purchase_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_selling_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(BranchStock::class, 'variant_id');
    }

    public function inventoryBatches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class, 'variant_id');
    }
}
