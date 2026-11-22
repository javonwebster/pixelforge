<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Registration is closed, so accounts come from here: one admin, one demo
 * user, each starting with $5 of credit. Credentials come from the
 * environment (config/seed.php) and never from the repo.
 *
 * Safe to run again: existing accounts are left alone and don't get a
 * second $5.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public const int STARTING_CREDIT_NANOS = 5_000_000_000;

    public function run(LedgerService $ledger): void
    {
        $this->seedAccount($ledger, 'Admin', 'admin', isAdmin: true);
        $this->seedAccount($ledger, 'Demo', 'demo', isAdmin: false);
    }

    private function seedAccount(LedgerService $ledger, string $name, string $key, bool $isAdmin): void
    {
        $email = config("seed.{$key}.email");
        $password = config("seed.{$key}.password");

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->warn('SEED_'.strtoupper($key)."_EMAIL / _PASSWORD not set; skipping the {$name} account.");

            return;
        }

        DB::transaction(function () use ($ledger, $name, $email, $password, $isAdmin): void {
            $email = strtolower($email);

            if (User::query()->where('email', $email)->exists()) {
                $this->command->info("{$name} account {$email} already exists; left unchanged.");

                return;
            }

            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->is_admin = $isAdmin;
            $user->email_verified_at = now();
            $user->save();

            $ledger->purchase($user, self::STARTING_CREDIT_NANOS);

            $this->command->info("Created {$name} account {$email} with \$5 of credit.");
        });
    }
}
