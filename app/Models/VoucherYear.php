<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoucherYear extends Model
{
    protected $table = 'years';

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['year'];

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'year', 'year');
    }
}
