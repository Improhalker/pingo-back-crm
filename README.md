# PingoFin — backend CRM

API Laravel 13/PHP 8.5 com Sanctum por sessão, MySQL, Redis, organização por request, Policies e auditoria transacional. Contém financeiro, dashboard e relatórios; master administra empresas sem acesso aos dados de negócio. Assistente continua local no frontend.

## Desenvolvimento

Na raiz: `composer install`; copiar .env.example para .env; configurar credenciais locais e `php artisan key:generate`. Windows: scripts/setup-tools.ps1 configura o PHP/Composer local, scripts/php.ps1 e scripts/composer.ps1 executam comandos. scripts/configure-local.ps1 cria ambientes ausentes sem exibir segredos.

Infra local isolada: `docker compose --env-file infra/.env -f infra/compose.yaml up -d --wait`. Mantém o nome pingofin-bootstrap e os volumes existentes; não executar down -v. Aplicação: `php artisan serve --host=localhost --port=8000`. Migrations/seeds só com confirmação do ambiente alvo; esta migração de pastas não recria bancos.

## Verificações

`composer validate --strict`, `composer check-platform-reqs`, `composer run format:check` e `php artisan test`. Testes exigem .env.testing e MySQL pingofin_test; TestCase bloqueia banco diferente. Nenhum SQLite substitui o contrato de produção.

## Deploy e responsabilidades

Document root: public/. Instalar dependências pelo composer.lock com --no-dev em produção. Runtime precisa de BCMath, PDO MySQL e extensões do lockfile. Gerenciar .env/APP_KEY no host, APP_ENV=production, APP_DEBUG=false, HTTPS, CORS exato, cookies Secure e domínio coerente com o frontend. Worker Redis e scheduler exigem processos supervisionados. Migrar banco com backup e plano de reversão no deploy.

infra/compose.yaml serve desenvolvimento; PHP-FPM/Nginx ou provider de produção ainda precisam ser definidos. Não há deploy configurado nesta organização. .env/.env.testing e infra/.env são locais e ignorados.

[Mapa e pendências](../pingo-doc/MIGRACAO_REPOSITORIOS.md). [Arquitetura](../pingo-doc/ARCHITECTURE.md). [Regras dos agentes](./AGENTS.md).

## Histórico obrigatório

A fonte única é o JSON privado em `../pingo-front-crm/data/agent-changes.json`. Toda tarefa de agente deve acrescentar entrada imutável com resumo de até 300 caracteres Unicode e detalhes separados para mudanças complexas. Nunca copie o JSON para outro repositório, public/, bundle do navegador, banco ou localStorage.

No frontend, execute `node scripts/check-agent-changes.mjs --worktree` e inclua o JSON no stage. Commit do frontend deve preceder commits relacionados nos demais repositórios. Neles, atualize e inclua `.agent-change.json` com ID novo e SHA completo do commit do frontend que contém o registro. Os hooks leem o registro diretamente desse commit, recusam IDs reutilizados e não mantêm cópias do histórico.

Ative em cada clone: `git config --local core.hooksPath .githooks`. Não contornar a trava. Clone o frontend como irmão ou configure `PINGOFIN_FRONT_ROOT`. Linux usa Node compatível no PATH; Windows pode executar o setup-node.ps1 local.
