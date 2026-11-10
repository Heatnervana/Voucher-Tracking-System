<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'voucher.uploaded' => 'PDF uploaded',
        'voucher.replaced' => 'PDF replaced',
        'voucher.renamed' => 'PDF renamed',
        'voucher.details_changed' => 'PDF details changed',
        'voucher.moved' => 'PDF moved',
        'voucher.amount_changed' => 'Amount changed',
        'voucher.amount_read' => 'Amount read automatically',
        'voucher.deleted' => 'PDF moved to recycle bin',
        'voucher.restored' => 'PDF restored',
        'voucher.purged' => 'PDF permanently deleted',
        'account.created' => 'Account created',
        'account.updated' => 'Account updated',
        'account.role_changed' => 'Account role changed',
        'account.password_reset' => 'Account password reset',
        'account.password_changed' => 'Account password changed',
        'account.deleted' => 'Account deleted',
        'auth.login' => 'Sign-in succeeded',
        'auth.logout' => 'Signed out',
        'auth.failed' => 'Sign-in failed',
    ];

    protected $fillable = ['actor_id', 'actor_label', 'subject_type', 'subject_id', 'subject_label', 'action', 'before', 'after', 'ip_address'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
