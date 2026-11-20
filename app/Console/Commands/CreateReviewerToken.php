<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateReviewerToken extends Command
{
    protected $signature = 'reviewer:token
                            {user : User id or email}
                            {--name=ci : Token name}
                            {--show : Print the plain-text token once}';

    protected $description = 'Create a Sanctum personal access token for CI review API';

    public function handle(): int
    {
        $key = (string) $this->argument('user');
        $user = is_numeric($key)
            ? User::query()->find((int) $key)
            : User::query()->where('email', $key)->first();

        if (! $user) {
            $this->error('User not found: '.$key);

            return self::FAILURE;
        }

        $token = $user->createToken((string) $this->option('name'));
        $plain = $token->plainTextToken;

        $this->info('Token created for '.$user->email.' (id '.$user->id.').');
        if ($this->option('show')) {
            $this->line($plain);
        } else {
            $this->line('Pass --show to print the secret. Store it as REVIEWER_TOKEN.');
            // Still print once so CI setup is practical without re-creating.
            $this->warn($plain);
        }

        return self::SUCCESS;
    }
}
