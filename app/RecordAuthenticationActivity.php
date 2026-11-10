<?php

namespace App;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordAuthenticationActivity
{
    public function __construct(private ActivityRecorder $recorder) {}

    public function handle(Login|Failed|Logout $event): void
    {
        $account = $event->user instanceof User ? $event->user : null;
        $username = $event instanceof Failed && is_string($event->credentials['username'] ?? null) ? $event->credentials['username'] : ($account?->username ?? 'Unknown account');
        $this->recorder->authentication($account, $username, $event instanceof Login ? 'auth.login' : ($event instanceof Logout ? 'auth.logout' : 'auth.failed'));
    }
}
