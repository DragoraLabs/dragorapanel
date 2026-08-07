<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class MakeUserCommand extends Command
{
    protected $signature = 'panel:user:create';
    protected $description = 'Create a new panel user';

    public function handle()
    {
        $this->line('----------------------------------');
        $username = $this->ask('USERNAME');
        $this->line('----------------------------------');
        $email = $this->ask('EMAIL');
        $this->line('----------------------------------');
        $password = $this->secret('PASSWORD');
        $this->line('----------------------------------');
        $admin = $this->confirm('ADMIN (y/n)', false);
        $this->line('----------------------------------');

        $role = $admin ? 'admin' : 'member';

        $validator = Validator::make([
            'email' => $email,
            'password' => $password,
            'username' => $username,
        ], [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'username' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return 1;
        }

        $user = User::create([
            'email' => $email,
            'password' => Hash::make($password),
            'first_name' => $username,
            'last_name' => '',
            'role' => $role,
        ]);

        $this->info('User created successfully!');
        $this->line(" ID:       {$user->id}");
        $this->line(" Username: {$username}");
        $this->line(" Email:    {$user->email}");
        $this->line(" Role:     {$role}");

        return 0;
    }
}
