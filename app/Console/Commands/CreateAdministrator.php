<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdministrator extends Command
{
    protected $signature = 'unorank:create-admin {username}';

    protected $description = 'Utwórz konto administratora bez publicznej rejestracji';

    public function handle(): int
    {
        $username = $this->argument('username');
        $password = $this->secret('Hasło administratora');
        $confirmation = $this->secret('Powtórz hasło');
        $validator = Validator::make(['username' => $username, 'password' => $password, 'password_confirmation' => $confirmation], [
            'username' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create(['username' => $username, 'password' => $password]);
        $this->info('Konto administratora zostało utworzone.');

        return self::SUCCESS;
    }
}
