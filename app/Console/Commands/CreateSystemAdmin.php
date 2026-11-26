<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSystemAdmin extends Command
{
    protected $signature = 'aims:create-admin {--employee-number= : Unique employee number used for login}';

    protected $description = 'Create the first AIMS system administrator';

    public function handle(): int
    {
        // Prevent creating additional administrators
        // through this bootstrap command.
        $role = Role::where('code', 'system_admin')->first();

        if (! $role) {
            $this->error('Run AimsCoreSeeder first.');

            return self::FAILURE;
        }

        if (DB::table('department_user_roles')
            ->where('role_id', $role->id)
            ->exists()) {
            $this->error('A system administrator already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $employeeNumber = trim((string) ($this->option('employee-number') ?: $this->ask('Administrator employee number')));
        $email = strtolower(trim(
            (string) $this->ask('Administrator email')
        ));

        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        $data = compact('name', 'email', 'password');
        $data['employee_number'] = $employeeNumber;
        $data['password_confirmation'] = $confirmation;

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'employee_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/D', 'unique:users,employee_number', 'unique:employees,employee_number'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'employee_number' => $data['employee_number'],
                'password' => $data['password'],
                'is_active' => true,
            ]);

            // Institution-wide administrator role.
            DB::table('department_user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'department_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info('System administrator created successfully.');

        return self::SUCCESS;
    }
}
