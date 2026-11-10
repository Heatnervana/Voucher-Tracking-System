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
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
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
            $count = $user && $user->can('manage-vouchers') && ! $user->must_change_password
                ? ($view->getData()['missingAmountCount'] ?? Voucher::needsAmount()->count())
                : 0;
            $view->with('notificationAmountCount', $count);
        });
    }
}
