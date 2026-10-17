<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'created_by',
        'category',
        'amount',
        'payment_method',
        'reference',
        'spent_at',
        'receipt_path',
        'status',
        'note',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'spent_at' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
