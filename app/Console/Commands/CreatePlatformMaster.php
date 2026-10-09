<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreatePlatformMaster extends Command
{
    protected $signature = 'platform:create-master {--name= : Nome da pessoa} {--email= : E-mail de acesso}';

    protected $description = 'Cria uma conta master da plataforma PingoFin (administração de empresas clientes)';

    public function handle(): int
    {
        // The password is only ever read from a hidden prompt, never from arguments or the environment.
        if (! $this->input->isInteractive()) {
            $this->error('Este comando exige um terminal interativo para ler a senha de forma oculta.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Nome')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        $identity = Validator::make(compact('name', 'email'), [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class, 'email')],
        ], ['email.unique' => 'Já existe uma conta com este e-mail. O comando não converte contas existentes.']);
        if ($identity->fails()) {
            return $this->reject($identity->errors()->all());
        }

        $password = (string) $this->secret('Senha ('.PasswordPolicy::DESCRIPTION.')');
        $confirmation = (string) $this->secret('Confirme a senha');
        $credentials = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()], [
            'password.min' => PasswordPolicy::DESCRIPTION,
            'password.letters' => PasswordPolicy::DESCRIPTION,
            'password.numbers' => PasswordPolicy::DESCRIPTION,
        ]);
        if ($credentials->fails()) {
            return $this->reject($credentials->errors()->all());
        }
        if (! hash_equals($password, $confirmation)) {
            return $this->reject(['A confirmação da senha não confere.']);
        }

        try {
            $user = DB::transaction(function () use ($name, $email, $password) {
                $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
                // No organization membership is created: platform authorization is independent of tenants.
                $user->platformAdministrator()->create();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->reject(['Já existe uma conta com este e-mail. O comando não converte contas existentes.']);
        }

        Log::info('platform.master_created', ['user_id' => $user->id]);
        $this->info("Conta master criada (id {$user->id}). Acesse /plataforma/login para entrar.");

        return self::SUCCESS;
    }

    /** @param list<string> $messages */
    private function reject(array $messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
