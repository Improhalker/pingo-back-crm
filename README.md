# PingoFin — backend CRM

API Laravel 13/PHP 8.5 com Sanctum por sessão, MySQL, Redis, organização por request, Policies e auditoria transacional. Contém financeiro, dashboard e relatórios; master administra empresas sem acesso aos dados de negócio. Assistente continua local no frontend.

## Desenvolvimento

Na raiz: `composer install`; copiar .env.example para .env; configurar credenciais locais e `php artisan key:generate`. Windows: scripts/setup-tools.ps1 configura o PHP/Composer local, scripts/php.ps1 e scripts/composer.ps1 executam comandos. scripts/configure-local.ps1 cria ambientes ausentes sem exibir segredos.

Infra local isolada: `docker compose --env-file infra/.env -f infra/compose.yaml up -d --wait`. Mantém o nome pingofin-bootstrap e os volumes existentes; não executar down -v. Aplicação: `php artisan serve --host=localhost --port=8000`. Migrations/seeds só com confirmação do ambiente alvo; esta migração de pastas não recria bancos.

## Verificações

`composer validate --strict`, `composer check-platform-reqs`, `composer run format:check` e `php artisan test`. Testes exigem .env.testing e MySQL pingofin_test; TestCase bloqueia banco diferente. Nenhum SQLite substitui o contrato de produção.

## Deploy e responsabilidades

Document root: public/. Instalar dependências pelo composer.lock com --no-dev em produção. Runtime precisa de BCMath, PDO MySQL e extensões do lockfile. Gerenciar .env/APP_KEY no host, APP_ENV=production, APP_DEBUG=false, HTTPS, CORS exato, cookies Secure e domínio coerente com o frontend. Worker Redis e scheduler exigem processos supervisionados. Migrar banco com backup e plano de reversão no deploy.

infra/compose.yaml serve desenvolvimento. A configuração de produção em Docker está em [deploy/README.md](./deploy/README.md): API PHP 8.5, worker e MySQL/Redis próprios em compose.production.yaml, com porta local para o Nginx da VPS. Sua existência não comprova publicação; alvo, recursos, SSH e domínios ainda precisam ser confirmados. .env/.env.testing, .env.production e infra/.env são locais e ignorados.

[Mapa e pendências](../pingo-doc/MIGRACAO_REPOSITORIOS.md). [Arquitetura](../pingo-doc/ARCHITECTURE.md). [Regras dos agentes](./AGENTS.md).

## Histórico obrigatório

A fonte única é o JSON privado em `../pingo-front-crm/data/agent-changes.json`. Toda tarefa de agente deve acrescentar entrada imutável com resumo de até 300 caracteres Unicode e detalhes separados para mudanças complexas. Nunca copie o JSON para outro repositório, public/, bundle do navegador, banco ou localStorage.

No frontend, execute `node scripts/check-agent-changes.mjs --worktree` e inclua o JSON no stage. Commit do frontend deve preceder commits relacionados nos demais repositórios. Neles, atualize e inclua `.agent-change.json` com ID novo e SHA completo do commit do frontend que contém o registro. Os hooks leem o registro diretamente desse commit, recusam IDs reutilizados e não mantêm cópias do histórico.

Ative em cada clone: `git config --local core.hooksPath .githooks`. Não contornar a trava. Clone o frontend como irmão ou configure `PINGOFIN_FRONT_ROOT`. Linux usa Node compatível no PATH; Windows pode executar o setup-node.ps1 local.

## Validação no GitHub

O workflow `.github/workflows/deploy.yml` dispara automaticamente em push para main e também pode ser acionado manualmente. Ele chama `.github/workflows/validate.yml` e libera o SSH de produção somente após a API e a imagem Docker passarem. Pull requests mantêm validação sem deploy. Os secrets existentes do ambiente production ficam somente no job de publicação. Veja [CI/CD e operação](./deploy/CI_CD.md).

## Boas-vindas públicas

GET / responde HTML com o Pingo, sem redirecionar para o JSON técnico. Somente essa rota dispensa o grupo web: não cria cookies/sessão nem consulta banco. Não há JavaScript, dados de negócio, versões ou configuração exposta. Assets de marca ficam em public/brand; Pingo e favicon vêm do frontend e Manrope mantém sua licença local.

Os endpoints /api/v1/health/live e /api/v1/health permanecem separados. A página informa presença do serviço, não comprova prontidão de todas as operações. A autenticação e Policies das rotas financeiras não são alteradas.

## Vencimentos e séries mensais

O dashboard mantém movimentações de caixa por data de baixa. `due_in_month` soma apenas o que resta das parcelas com vencimento no mês selecionado, tanto a receber como a pagar. O total em aberto e o total em atraso consideram todos os meses. O detalhamento usa os mesmos filtros de vencimento do relatório `/api/v1/reports/obligations`.

Crie uma série pelo modo **Recorrente mensal** dos formulários de receita e despesa. A série usa categoria ativa e, para receita, um cliente existente como fonte pagadora. A baixa de cada ocorrência é manual, inclusive para receitas avulsas em que o valor muda. No menor mês, o vencimento cai no último dia e a série volta ao dia escolhido no mês seguinte. O lembrete interno é opcional e usa de zero a trinta dias de antecedência.

A geração mantém o mês atual e o próximo no fuso da empresa. Se o scheduler parar, a série ativa retoma as competências ainda não geradas. Pausar ou encerrar preserva todas as ocorrências já criadas. Ao retomar uma série pausada, a geração recomeça no mês atual, preservando o intervalo passado da pausa. Uma ocorrência excluída individualmente mantém seu vínculo e não volta a ser gerada.

O reajuste mantém a série e começa em uma competência futura escolhida. Atualiza as ocorrências futuras já geradas se não tiverem baixa ou ajuste pontual de valor. As baixas, os valores de competências anteriores e os registros de auditoria são preservados. Para uma exceção, edite o lançamento individual. Categoria ou fonte pagadora arquivada bloqueia novas gerações desta série até regularizar o cadastro.

Na implantação, publique e migre o backend antes do frontend. O comando `php artisan finance:generate-recurrences` é idempotente e está cadastrado para execução de hora em hora no scheduler Laravel. Rode o scheduler do PingoFin junto à API, usando a mesma imagem, após validar a migration. Na VPS, o serviço `scheduler` do Compose é opcional e precisa ser ativado e atualizado no rollout. Confira `schedule:list` e acompanhe erros de geração nas primeiras execuções.
