<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'category_id',
        'brand',
        'name',
        'short_name',
        'slug',
        'sku',
        'barcode',
        'secondary_barcode',
        'unit',
        'product_type',
        'model_number',
        'description',
        'image_path',
        'weight',
        'length',
        'width',
        'height',
        'warranty_months',
        'purchase_price',
        'wholesale_price',
        'selling_price',
        'min_selling_price',
        'tax_rate',
        'is_sellable',
        'is_purchasable',
        'track_stock',
        'has_expiry',
        'is_serialized',
        'allow_negative_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'min_selling_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'track_stock' => 'boolean',
            'has_expiry' => 'boolean',
            'is_serialized' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function stock(): HasOne
    {
        return $this->hasOne(BranchStock::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventoryBatches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class);
    }
}
