<?php

namespace App;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ActivityRecorder
{
    private const VOUCHER_FIELDS = ['checkada_no', 'dv_number', 'payee', 'amount', 'amount_status', 'year', 'type', 'month', 'uploaded_by', 'file_name'];

    private const USER_FIELDS = ['name', 'username', 'is_admin', 'must_change_password'];

    /** @return array<string, mixed> */
    public function snapshot(User|Voucher $subject): array
    {
        $values = $subject->attributesToArray();
        if ($subject instanceof Voucher) {
            $values['file_name'] = basename($subject->file_path);
        }

        return Arr::only($values, $subject instanceof Voucher ? self::VOUCHER_FIELDS : self::USER_FIELDS);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(User|Voucher $subject, string $action, array $before = [], array $after = [], bool $system = false): ActivityLog
    {
        $actor = $system ? null : auth()->user();
        $fields = $subject instanceof Voucher ? self::VOUCHER_FIELDS : self::USER_FIELDS;

        return ActivityLog::create([
            'actor_id' => $actor?->id,
            'actor_label' => $actor instanceof User ? $this->userLabel($actor) : 'System',
            'subject_type' => $subject instanceof Voucher ? 'voucher' : 'account',
            'subject_id' => $subject->id,
            'subject_label' => $subject instanceof Voucher ? Str::limit(basename($subject->file_path), 255, '') : $this->userLabel($subject),
            'action' => $action,
            'before' => Arr::only($before, $fields),
            'after' => Arr::only($after, $fields),
            'ip_address' => $system ? null : request()->ip(),
        ]);
    }

    public function authentication(?User $account, string $username, string $action): ActivityLog
    {
        $authenticated = $action !== 'auth.failed';

        return ActivityLog::create([
            'actor_id' => $authenticated ? $account?->id : null,
            'actor_label' => $authenticated && $account !== null ? $this->userLabel($account) : 'Unauthenticated visitor',
            'subject_type' => 'account',
            'subject_id' => $account?->id,
            'subject_label' => $account !== null ? $this->userLabel($account) : Str::limit($username, 255, ''),
            'action' => $action,
            'before' => [],
            'after' => [],
            'ip_address' => request()->ip(),
        ]);
    }

    private function userLabel(User $user): string
    {
        return Str::limit($user->name.' ('.$user->username.')', 255, '');
    }
}
