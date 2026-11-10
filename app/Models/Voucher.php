<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use SoftDeletes;

    protected $fillable = ['checkada_no', 'dv_number', 'payee', 'amount', 'amount_status', 'amount_retry_count', 'amount_read_token', 'year', 'type', 'month', 'file_path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'month' => 'integer', 'year' => 'integer', 'amount_retry_count' => 'integer'];
    }

    public function yearFolder(): BelongsTo
    {
        return $this->belongsTo(VoucherYear::class, 'year', 'year');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function scopeNeedsAmount(Builder $query): void
    {
        $query->whereNull('amount')->where('amount_status', 'manual');
    }

    public function scopeWithVoucherNumbers(Builder $query, string $checkNumber, string $dvNumber): void
    {
        $query->whereRaw('UPPER(checkada_no) = ?', [strtoupper($checkNumber)])->where('dv_number', $dvNumber);
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        $query->when($term !== null && $term !== '', function (Builder $query) use ($term): void {
            $query->where(function (Builder $query) use ($term): void {
                $query->where('dv_number', 'like', "%{$term}%")
                    ->orWhere('checkada_no', 'like', "%{$term}%")
                    ->orWhere('payee', 'like', "%{$term}%");
            });
        });
    }
}
