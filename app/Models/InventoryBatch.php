<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'product_id',
        'variant_id',
        'batch_number',
        'manufactured_date',
        'expiry_date',
        'quantity',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_date' => 'date',
            'expiry_date' => 'date',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function saleAllocations(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    public function returnAllocations(): HasMany
    {
        return $this->hasMany(SaleReturnItemBatch::class);
    }
}
