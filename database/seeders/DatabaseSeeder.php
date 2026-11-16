<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     * SECURITY-FIX: 2026-11-12. Bootstrap only an empty installation with a unique temporary password.
     * TRUST-BOUNDARY: Re-seeding must never change existing accounts or promote a username collision.
     */
    public function run(): void
    {
        if (User::withTrashed()->exists()) {
            $this->command?->warn('Bootstrap skipped: accounts already exist. Use an existing administrator to manage accounts or follow the account recovery procedure.');

            return;
        }

        $password = Str::password(18);
        $administrator = new User([
            'username' => 'admin',
            'name' => 'Administrator',
            'password' => $password,
        ]);
        $administrator->forceFill(['is_admin' => true, 'must_change_password' => true])->save();
        $this->command?->line('Username: '.$administrator->username);
        $this->command?->line('Temporary password: '.OutputFormatter::escape($password));
        $this->command?->warn('Change this temporary password at first sign-in. It will not be displayed again.');
    }
}
