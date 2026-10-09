<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lists and explicitly resolves legacy accounts linked to more than one company (ADR-028).
 * Without options it only reports. Resolution keeps the chosen membership and removes the other links
 * of that account; companies, accounts and business data are never deleted, and nothing is chosen
 * automatically.
 */
class LegacyMemberships extends Command
{
    protected $signature = 'platform:legacy-memberships
        {--email= : Conta a ajustar}
        {--keep= : ID da organização que a conta deve manter}';

    protected $description = 'Lista contas vinculadas a mais de uma empresa e ajusta uma delas mantendo a empresa escolhida';

    public function handle(): int
    {
        $email = $this->option('email');
        $keep = $this->option('keep');
        if ($email === null && $keep === null) {
            return $this->report();
        }
        if (! is_string($email) || ! is_string($keep) || ! ctype_digit($keep)) {
            $this->error('Informe --email e o ID numérico em --keep.');

            return self::FAILURE;
        }

        return $this->resolve(mb_strtolower(trim($email)), (int) $keep);
    }

    private function report(): int
    {
        $users = User::query()->has('organizations', '>', 1)->with('organizations')->orderBy('id')->get();
        if ($users->isEmpty()) {
            $this->info('Nenhuma conta está vinculada a mais de uma empresa.');

            return self::SUCCESS;
        }
        $this->warn('Contas bloqueadas por vínculo com mais de uma empresa:');
        $this->table(['Conta', 'Empresas (ID · nome · papel)'], $users->map(fn (User $user) => [
            $user->email,
            $user->organizations->map(fn ($org) => "{$org->id} · {$org->name} · {$org->pivot->role}")->implode("\n"),
        ])->all());
        $this->line('Ajuste uma conta com: php artisan platform:legacy-memberships --email=<e-mail> --keep=<ID da empresa>');

        return self::SUCCESS;
    }

    private function resolve(string $email, int $keep): int
    {
        $user = User::query()->where('email', $email)->first();
        $organizations = $user?->organizations()->orderBy('organizations.id')->get();
        if (! $user || $organizations->count() < 2) {
            $this->error('Esta conta não tem vínculo com mais de uma empresa.');

            return self::FAILURE;
        }
        $kept = $organizations->firstWhere('id', $keep);
        if (! $kept) {
            $this->error('A conta não está vinculada à organização informada em --keep.');

            return self::FAILURE;
        }
        $removed = $organizations->reject(fn ($org) => $org->id === $kept->id);
        $this->line("Manter: {$kept->name} ({$kept->pivot->role}).");
        $this->line('Remover o acesso a: '.$removed->map(fn ($org) => "{$org->name} ({$org->pivot->role})")->implode(', ').'.');
        $this->line('Nenhuma empresa, conta ou dado de negócio é apagado; somente esses vínculos.');
        if (! $this->confirm('Confirmar o ajuste?')) {
            $this->warn('Nada foi alterado.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $removed) {
            DB::table('organization_user')->where('user_id', $user->id)->whereIn('organization_id', $removed->pluck('id'))->delete();
        });
        foreach ($removed as $org) {
            if (DB::table('organization_user')->where('organization_id', $org->id)->where('role', 'ADMIN')->doesntExist()) {
                $this->warn("{$org->name} ficou sem pessoa administradora. Inclua uma pela administração da plataforma.");
            }
        }
        Log::info('platform.legacy_membership_resolved', [
            'user_id' => $user->id,
            'kept_organization_id' => $kept->id,
            'removed_organization_ids' => $removed->pluck('id')->values()->all(),
        ]);
        $this->info('Vínculo ajustado. A conta passa a entrar diretamente nesta empresa.');

        return self::SUCCESS;
    }
}
