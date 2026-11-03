# Deploy do backend PingoFin em VPS compartilhada

Preparado em 03/11/2026 para uma VPS Hostinger que também hospeda EspecializaCondutor e TheStarArt. Este documento não comprova publicação. Recursos, SSH, domínio e configuração real do Nginx precisam ser conferidos antes de executar os passos remotos.

Domínio informado pelo usuário: `pingofin.com.br`; API aprovada em `api.pingofin.com.br`. O exemplo considera a landing no domínio principal/Vercel e propõe `app.pingofin.com.br` para o aplicativo autenticado. Este último ainda precisa de confirmação; nenhum DNS foi alterado. Exemplos do procedimento abaixo devem ser ajustados a essa topologia antes da ativação.

Inspeção SSH somente de leitura em 03/11/2026: servidor `srv906410`, 1 vCPU, 3,8 GiB de RAM (aproximadamente 2,8 GiB disponíveis naquele momento), 33 GB livres na partição principal, Docker 29.8.1, Compose 5.5.1, Nginx com configuração válida e Certbot instalado. Outros serviços incluem PHP-FPM 8.3/8.4 e n8n em container. Porta 8088 não estava em uso. Nenhuma alteração remota foi aplicada. Revalidar antes de ativar; a folga observada não substitui teste de carga ou monitoramento. Apache começa limitado a quatro workers para o piloto.

## Desenho e isolamento

Internet → Nginx existente com HTTPS → `127.0.0.1:8088` → Apache/PHP 8.5 do PingoFin na porta 8080 → MySQL/Redis próprios, na rede do Compose.

- `Dockerfile`: imagem multi-stage, PHP 8.5.12, dependências de produção pelo lockfile, extensões necessárias e usuário `www-data`. Código pertence a root; somente `storage` e `bootstrap/cache` são graváveis. Não usa `artisan serve`, Node, Composer ou PHPUnit no runtime final.
- `compose.production.yaml`: projeto `pingofin-production`; serviços `api`, `worker`, `mysql`, `redis` e `scheduler` opcional. Não usar `container_name`, rede de outro projeto ou as portas públicas de MySQL/Redis. Não reaproveitar o Compose de desenvolvimento.
- Volumes próprios `pingofin-production_mysql_data`, `pingofin-production_redis_data` e `pingofin-production_private_files`. Não publicar `storage`, criar `storage:link` ou montar a raiz do código como volume.
- Apache atende exclusivamente `public/`; bloqueia arquivos ocultos e PHP diferente de `index.php`. Segredos entram em runtime; build não usa `.env`, gera APP_KEY ou acessa banco. Caches de configuração/rotas são gerados por container, após validar seu ambiente.
- Worker com timeout de 60s, `retry_after` de 90s, tentativas limitadas e reciclagem a cada hora; `init`, SIGTERM e reinício pelo Compose. O scheduler não é iniciado por padrão: ainda não existem tarefas de negócio agendadas.
- Logs limitados e enviados a stderr/stdout; access log do Apache omite query strings. Documentos privados não são expostos. Ambiente, Docker e backups continuam acessíveis aos administradores do servidor, que devem ter acesso mínimo.

As referências inspecionadas usam deploy por SSH, com PHP 8.4/PostgreSQL e PHP 8.3. Não foram copiadas porque não representam o runtime PHP 8.5/MySQL do PingoFin. Nenhum arquivo desses projetos deve ser alterado.

## 1. Conferir a VPS antes de instalar ou iniciar serviços

Confirmar host SSH, usuário, porta e fingerprint por canal confiável. Usar chave e `StrictHostKeyChecking=yes`; nunca enviar chaves ou `.env` pelo chat. Ler capacidade e configuração antes de agir:

```sh
free -h
df -h
docker version
docker compose version
docker ps --format '{{.Names}} {{.Status}}'
ss -ltn
sudo nginx -t
```

O conjunto padrão limita API a 512 MiB, worker a 256 MiB, MySQL a 1 GiB e Redis a 256 MiB (mais 256 MiB se ativar scheduler). São limites, não consumo garantido ou reserva. Verificar a folga real somada aos outros sites, disco, CPU e picos de build. Não presumir que a VPS comporta o conjunto; ajustar após medição ou usar serviços gerenciados mediante decisão própria.

Se Docker não estiver instalado, inspecionar firewall/iptables e serviços existentes antes de instalar. Não substituir o PHP do host, parar serviços compartilhados, reiniciar a VPS ou executar instalador genérico sem revisar seu impacto. Conferir que 8088 está livre; se necessário, escolher outra porta em `.env.production` e no vhost exclusivo.

## 2. Preparar release e segredos

Manter a aplicação em diretório próprio, por exemplo `/var/www/pingofin-back`. Clonar somente `pingo-back-crm` com credencial de leitura apropriada. O repositório de documentação e o JSON privado dos agentes não são dependências de runtime.

Depois de validar o commit e ter a mudança publicada no GitHub:

```sh
cd /var/www/pingofin-back
git status --short
git rev-parse HEAD
docker build --pull -t pingofin-api:SEU_SHA_VALIDADO .
bash scripts/init-production-env.sh
```

O script cria `.env.production` com permissão 600 e segredos aleatórios, sem imprimi-los; recusa sobrescrever ambiente existente. Não usar `set -x`, mostrar o arquivo, executar `docker compose config` sem `--quiet` ou salvar a saída do inspect completo em relatórios.

Preencher o ambiente localmente no servidor:

| Campo | Regra |
| --- | --- |
| `PINGOFIN_IMAGE` | A mesma tag imutável validada no build; nunca usar `latest` no procedimento de release. |
| `APP_URL` | Origem HTTPS final da API, sem caminho. Exemplo ilustrativo: `https://api.seudominio.com.br`. |
| `FRONTEND_URL` | Uma origem HTTPS exata do aplicativo, sem barra final. Não é a landing nem uma lista com curingas. |
| `SESSION_DOMAIN` | Domínio compartilhado pela API e pelo aplicativo, por exemplo `.seudominio.com.br`. |
| `SANCTUM_STATEFUL_DOMAINS` | Hosts de API/aplicativo, incluindo porta somente quando realmente necessária, sem protocolo/caminho. |
| `TRUSTED_PROXIES` | IP/CIDR real do Nginx visto pelo container. Não confiar em `*`. |
| Banco/Redis | Manter nomes de serviço `mysql`/`redis`, portas internas e senhas recém-geradas. |

Este primeiro desenho exige API e aplicativo sob o mesmo domínio base, usando sessão Sanctum e SameSite=Lax. Subdomínios de projetos diferentes (`vercel.app`, IP da VPS etc.) não substituem essa decisão; revisar arquitetura de sessão antes de usar domínios independentes. Compartilhar domínio de cookies também exige que seus subdomínios sejam confiáveis e permaneçam sob seu controle.

APP_KEY permanece estável entre versões e réplicas. Sua perda compromete acesso a dados/cookies criptografados; guardar cópia segura com o backup. Não rodar `key:generate` em cada deploy. Alterar senhas no `.env` não altera automaticamente credenciais de um volume MySQL já inicializado: uma rotação exige procedimento coordenado.

## 3. Subir dependências próprias e aplicar schema conscientemente

```sh
docker compose --env-file .env.production -f compose.production.yaml config --quiet
docker compose --env-file .env.production -f compose.production.yaml up -d --wait mysql redis
docker network inspect pingofin-production_default --format '{{(index .IPAM.Config 0).Gateway}}'
```

Na topologia proposta, o Nginx do host chega pelo gateway dessa rede. Confirmar essa origem na VPS e preencher `TRUSTED_PROXIES` com o IP correto antes de subir a API. A rede pode mudar depois de recriada; revisar essa configuração. Laravel aceita os headers de protocolo/porta/IP apenas para proxies configurados; `X-Forwarded-Host` não é confiado.

```sh
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps api php /usr/local/lib/pingofin-preflight.php
```

No primeiro deploy, confirmar explicitamente que o destino é o banco **novo e exclusivo** `pingofin`. Nos seguintes, gerar backup verificável antes de migrations. Inspecionar as migrations e o estado, então executar uma vez por release:

```sh
docker compose --env-file .env.production -f compose.production.yaml run --rm --no-deps api php artisan migrate --force --no-interaction
docker compose --env-file .env.production -f compose.production.yaml up -d --wait --wait-timeout 180 api
docker compose --env-file .env.production -f compose.production.yaml up -d --no-deps worker
curl --fail --max-time 15 http://127.0.0.1:8088/api/v1/health/live
curl --fail --max-time 15 http://127.0.0.1:8088/api/v1/health
```

Não executar `db:seed`, fixtures de desenvolvimento, `migrate:fresh`, `migrate:reset` ou `down -v`. A inicialização dos containers não roda migrations automaticamente. A prontidão atual verifica conexões MySQL/Redis, não completude do schema: conferir `migrate:status` e os testes funcionais antes de liberar usuários.

Provisionar a conta master com `platform:create-master` pelo procedimento existente, de forma interativa e privada. Não colocar senha em argumento, workflow ou documento. O cadastro das empresas e seu primeiro ADMIN segue o fluxo de plataforma atual.

## 4. Domínio e HTTPS exclusivos do PingoFin

Confirmar domínio/subdomínio e DNS antes de alterar a zona. Preservar MX, TXT, verificações e registros dos outros serviços; conferir A e AAAA. Criar um **novo** arquivo de site a partir de `deploy/nginx-http.conf.example`, substituindo somente domínio e porta do PingoFin. Não sobrescrever `nginx.conf`, o site default ou os vhosts do EspecializaCondutor/TheStarArt.

Validar a configuração com `nginx -t` antes de um reload. Obter TLS para o domínio aprovado usando o método já adotado nessa VPS (por exemplo, Certbot/Nginx). O template HTTP é bootstrap; o deploy oficial só está concluído quando HTTPS e redirecionamento forem validados. Conferir que o vhost TLS mantém Host e sobrescreve `X-Forwarded-For`, `X-Forwarded-Proto` e `X-Forwarded-Port`, descartando `X-Forwarded-Host` do cliente.

Não aceitar tráfego diretamente na porta do container. Não instalar outro proxy global, alterar certificados ou reiniciar serviços dos outros projetos. Confirmar os sites existentes antes/depois do reload.

## 5. Aceitar o deploy

- HTTPS válido, debug desligado e nenhuma exposição de `.env`, código, storage privado ou credenciais.
- `/api/v1/health/live` 200; `/api/v1/health` 200 com MySQL e Redis saudáveis; `/api/v1/auth/me` sem sessão retorna 401.
- `/sanctum/csrf-cookie` responde para a origem exata do aplicativo; cookies Secure, HttpOnly na sessão e domínio correto; origem arbitrária não recebe autorização CORS.
- Worker Redis recebe o job técnico via `infra:queue-smoke --production`. A flag explícita é obrigatória em produção; o padrão continua restrito a local/teste. A checagem grava somente um marcador Redis aleatório com TTL, sem tocar clientes ou financeiro. Não confundir isso com um fluxo fiscal ou teste de operações de negócio.
- Pelo frontend HTTPS aprovado: login/CSRF, empresa única, permissões e isolamento, cliente, receitas/despesas, parcelas, baixa/correção e relatórios. Backend publicado sozinho ainda não comprova esse fluxo navegador → API.
- Logs, alertas de indisponibilidade, reinícios/OOM, espaço em disco, falhas de jobs e recuperação de backups acompanhados. Definir responsável pelo suporte e procedimento de recuperação de acesso antes de clientes reais.
- Landing e aplicativo permanecem deployments separados; habilitar seus links somente quando os destinos existirem.

## Releases seguintes, rollback e backups

Buildar/testar nova tag imutável, revisar migrations, guardar a tag anterior e fazer backup consistente. Atualizar `PINGOFIN_IMAGE`, aguardar a API com `up -d --wait api` e iniciar/recriar worker com `up -d --no-deps worker`. Confirmar consumo da fila antes de aceitar a release. Os processos antigos devem terminar dentro do período de 90s; não confiar somente em `queue:restart` para trocar a imagem.

Antes de downgrade, verificar compatibilidade entre código e schema. Rollback de imagem não reverte o banco, baixas ou operações realizadas. Não executar rollback de migrations automaticamente. Para interrupção, parar somente os serviços do PingoFin que precisarem de manutenção, com janela comunicada.

Fazer dump MySQL consistente com usuário autorizado, guardar arquivos privados e APP_KEY/segredos de forma criptografada e fora da VPS, com retenção e restauração testada. Volume persistente não é backup. Evitar copiar arquivos brutos de MySQL/Redis em uso como se fossem backup consistente. Redis usa AOF `everysec` e `noeviction`; monitorar persistência/memória e suportar reprocessamento das filas. O produto atual não depende de filas para confirmar baixas financeiras.

Não expor senha na linha do host. Um exemplo de dump, a executar na VPS e redirecionar para **diretório privado já preparado**, é:

```sh
umask 077
docker compose --env-file .env.production -f compose.production.yaml exec -T mysql sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump --single-transaction --skip-lock-tables --no-tablespaces -u"$MYSQL_USER" "$MYSQL_DATABASE"' \
  | gzip > /CAMINHO_PRIVADO/pingofin-AAAA-MM-DD-HHMM.sql.gz
```

Para uso em script, ativar `set -o pipefail` no Bash e verificar o arquivo/restore; falha no dump não deve produzir um backup falsamente considerado válido.

## Validação local e CI

```sh
composer validate --strict
composer audit --locked --no-dev
vendor/bin/pint --test
php artisan test
docker build --pull -t pingofin-api:validacao .
bash scripts/smoke-production.sh pingofin-api:validacao
```

A suíte PHP exige MySQL `pingofin_test` exclusivo e ambiente de testes. O smoke de produção cria somente o projeto `pingofin-deploy-smoke`, dados sintéticos em tmpfs e portas locais dinâmicas; remove seus containers/rede ao terminar, sem apagar volumes persistentes. Não executar duas instâncias dele simultaneamente. Verifica imagem sem segredos/dependências dev, usuário não root, rejeição de ambiente inseguro, schema descartável, HTTP/CORS/cookies, worker, falha/recuperação Redis e reinício da API. Não é teste fiscal nem evidência de acesso à VPS.

O [pipeline automático](./CI_CD.md) agora recebe cada push na main, reutiliza o CI e publica por SSH somente após os testes da API e da imagem passarem. O ambiente production e o wrapper restrito da VPS são reutilizados; os testes não recebem seus secrets. Acionamento manual é opcional.

Fontes oficiais consultadas em 03/11/2026: [deploy Laravel](https://laravel.com/docs/13.x/deployment), [proxies confiáveis](https://laravel.com/docs/13.x/requests#configuring-trusted-proxies), [imagem oficial PHP](https://github.com/docker-library/php/blob/master/8.5/bookworm/apache/Dockerfile), [boas práticas Docker](https://docs.docker.com/build/building/best-practices/). Revalidar digests, advisories e necessidades do servidor em cada atualização.

[Resultados desta preparação](./VALIDATION.md): testes executados, inspeção da VPS e limites reais.

## CI/CD em operação

A configuração atual de publicação é descrita em [CI_CD.md](./CI_CD.md). A API já estava ativa na VPS quando o pipeline foi preparado; as evidências históricas de bootstrap acima não devem ser usadas como status de uma nova execução.
