<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-admin {name} {email} {--super : Create a super administrator}')]
#[Description('Create an administrator account')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $data = [
            'name' => $this->argument('name'),
            'email' => $this->argument('email'),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::create($validator->validated());
        $user->role = $this->option('super') ? 'super_admin' : 'admin';
        $user->save();

        $this->info($this->option('super') ? 'Super admin account created.' : 'Admin account created.');

        return self::SUCCESS;
    }
}
