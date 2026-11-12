<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DatabaseSeederSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installations_receive_distinct_temporary_administrator_credentials(): void
    {
        $previousPassword = null;
        foreach (range(1, 2) as $installation) {
            $output = $this->runSeeder();
            $account = User::where('username', 'admin')->sole();

            $this->assertSame(1, preg_match('/Temporary password: (.+)/', $output, $matches));
            $password = trim($matches[1]);
            $this->assertSame(18, strlen($password));
            $this->assertNotSame('change-me-now', $password);
            $this->assertNotSame($previousPassword, $password);
            $this->assertTrue(Hash::check($password, $account->password));
            $this->assertFalse(Hash::check('change-me-now', $account->password));
            $this->assertTrue($account->is_admin);
            $this->assertTrue($account->must_change_password);
            $this->assertSame(1, substr_count($output, 'Temporary password:'));

            $previousPassword = $password;
            $account->forceDelete();
        }
    }

    #[TestWith(['admin', true, false, false])]
    #[TestWith(['admin', false, false, false])]
    #[TestWith(['existing.employee', false, true, false])]
    #[TestWith(['former.employee', false, false, true])]
    public function test_existing_accounts_are_preserved_without_new_credentials(string $username, bool $isAdmin, bool $temporary, bool $deleted): void
    {
        $account = User::factory()->create([
            'username' => $username,
            'is_admin' => $isAdmin,
            'must_change_password' => $temporary,
        ]);
        if ($deleted) {
            $account->delete();
        }
        $before = $account->fresh()->getAttributes();

        $output = $this->runSeeder();

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertSame(1, User::withTrashed()->count());
        $this->assertStringContainsString('Bootstrap skipped: accounts already exist', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
        $this->assertStringNotContainsString($account->password, $output);
    }

    public function test_repeated_seeding_does_not_reprint_or_replace_the_initial_credential(): void
    {
        $firstOutput = $this->runSeeder();
        $account = User::where('username', 'admin')->sole();
        $before = $account->getAttributes();

        $output = $this->runSeeder();

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertStringNotContainsString('Temporary password:', $output);
        $this->assertStringContainsString('Bootstrap skipped: accounts already exist', $output);
        $this->assertNotSame($firstOutput, $output);
    }

    private function runSeeder(): string
    {
        $this->assertSame(0, Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--no-interaction' => true]));

        return Artisan::output();
    }
}
