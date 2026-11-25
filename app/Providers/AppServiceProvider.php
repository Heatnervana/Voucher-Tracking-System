<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Voucher;
use App\Observers\UserActivityObserver;
use App\Observers\VoucherActivityObserver;
use App\RecordAuthenticationActivity;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as BladeView;

class AppServiceProvider extends ServiceProvider
{
    /**
     * TRUST-BOUNDARY: Server-side administrator gates protect management actions.
     * SECURITY: Staff and administrators intentionally share the authenticated PDF library.
     */
    public function boot(): void
    {
        User::observe(UserActivityObserver::class);
        Voucher::observe(VoucherActivityObserver::class);
        Event::listen([
            Login::class,
            Failed::class,
            Logout::class,
        ], [RecordAuthenticationActivity::class, 'handle']);
        Gate::define('manage-vouchers', fn (User $user): bool => $user->is_admin === true);
        Gate::define('manage-accounts', fn (User $user): bool => $user->is_admin === true);
        View::composer('layouts.app', function (BladeView $view): void {
            $user = auth()->user();
            $canManage = $user && $user->can('manage-vouchers') && ! $user->must_change_password;
            $view->with([
                'notificationAmountCount' => $canManage ? ($view->getData()['missingAmountCount'] ?? Voucher::needsAmount()->count()) : 0,
                'notificationCategoryCount' => $canManage ? ($view->getData()['missingCategoryCount'] ?? Voucher::needsUtilityCategory()->count()) : 0,
            ]);
        });
    }
}
