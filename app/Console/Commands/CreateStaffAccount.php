<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateStaffAccount extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:create-staff {username} {--name=Staff}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a staff account that can only search and view voucher PDFs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $validation = Validator::make(['username' => $this->argument('username'), 'name' => $this->option('name')], [
            'username' => ['required', 'string', 'regex:/\A[A-Za-z0-9._-]{3,40}\z/', 'unique:users,username'],
            'name' => ['required', 'string', 'max:100'],
        ]);
        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }
        $password = Str::password(18);
        $staff = new User($validation->validated() + ['password' => $password]);
        $staff->forceFill(['is_admin' => false, 'must_change_password' => true])->save();
        $this->line('Username: '.$staff->username);
        $this->line('Temporary password: '.$password);
        $this->info('Staff must change this password at first sign-in.');

        return self::SUCCESS;
    }
}
