<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.connections.mysql.database') !== 'pingofin_dev') {
            throw new RuntimeException('Fixtures permitidas somente no banco local pingofin_dev.');
        }
        $password = config('development.password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Configure DEV_USER_PASSWORD local com pelo menos 12 caracteres.');
        }
        // One company per client account (ADR-028): each fixture account belongs to exactly one organization.
        DB::transaction(function () use ($password) {
            $first = Organization::firstOrCreate(['name' => 'Organização de teste A']);
            $second = Organization::firstOrCreate(['name' => 'Organização de teste B']);
            $admin = User::updateOrCreate(['email' => 'dev@pingofin.test'], ['name' => 'Pessoa de teste', 'password' => Hash::make($password)]);
            $operator = User::updateOrCreate(['email' => 'operador@pingofin.test'], ['name' => 'Operador de teste', 'password' => Hash::make($password)]);
            // Earlier fixtures linked dev@ to both companies; that synthetic link to B moves to the operator fixture.
            $admin->organizations()->sync([$first->id => ['role' => 'ADMIN']]);
            $operator->organizations()->sync([$second->id => ['role' => 'OPERATOR']]);
        });
    }
}
