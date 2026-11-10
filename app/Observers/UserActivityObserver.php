<?php

namespace App\Observers;

use App\ActivityRecorder;
use App\Models\User;

class UserActivityObserver
{
    public function __construct(private ActivityRecorder $recorder) {}

    public function created(User $user): void
    {
        $this->recorder->record($user, 'account.created', after: $this->recorder->snapshot($user));
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('is_admin')) {
            $this->recorder->record($user, 'account.role_changed', ['is_admin' => $user->getOriginal('is_admin')], ['is_admin' => $user->is_admin]);
        }
        if ($user->wasChanged('password')) {
            $this->recorder->record($user, request()->routeIs('accounts.password') ? 'account.password_reset' : 'account.password_changed');
        }
        $fields = array_intersect(['name', 'username'], array_keys($user->getChanges()));
        if ($fields !== []) {
            $this->recorder->record($user, 'account.updated', array_intersect_key($user->getOriginal(), array_flip($fields)), array_intersect_key($this->recorder->snapshot($user), array_flip($fields)));
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        $this->recorder->record($user, 'account.deleted', before: $this->recorder->snapshot($user));
    }
}
