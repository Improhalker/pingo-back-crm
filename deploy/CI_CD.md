# CI/CD automático do backend PingoFin

Atualização de 03/11/2026. O backend já estava ativo e saudável antes desta configuração, conforme inspeção SSH e consultas HTTPS. Este documento descreve o pipeline; consultar a execução do GitHub para confirmar cada publicação.

## Gatilhos e sequência

`push em main → CI da API → build e smoke Docker → deploy SSH → health HTTPS`

- `.github/workflows/deploy.yml` dispara em todo push na `main`. `workflow_dispatch` permanece como alternativa, sem ser necessário para releases normais.
- O job `ci` chama `validate.yml` do **mesmo commit** usando `workflow_call`. Ele executa a suíte financeira, auditoria de dependências, worker real e smoke da imagem de produção.
- O job `deploy` depende do sucesso do CI e só aceita `refs/heads/main`. Se teste, build ou smoke falhar, ele é pulado e a publicação não acontece.
- `validate.yml` continua atendendo pull requests e acionamento manual de validação. O push em main entra pelo workflow de deploy, evitando duas execuções completas do CI para o mesmo push.
- Produção usa o grupo `pingofin-production` com `cancel-in-progress: false`: uma publicação em andamento não é interrompida por um novo push.
- O ambiente `production` existente não tinha regras de aprovação manual na consulta realizada. Nenhuma regra ou secret foi alterado para habilitar este workflow.

## Acesso existente

Secrets do ambiente `production`: `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY` e `SSH_KNOWN_HOSTS`. Não adicionar valores desses secrets ao Git ou a este documento.

Os testes não recebem herança de secrets. Somente o job de produção usa o ambiente de SSH. A chave é escrita temporariamente no runner com permissão 600 e removida ao terminar. A conexão usa BatchMode, IdentitiesOnly e StrictHostKeyChecking; não utiliza aceite automático de fingerprint.

O comando remoto é:

```sh
sudo -n /usr/local/libexec/pingofin/deploy release SHA_DO_COMMIT
```

O usuário `pingofin-deploy` e o wrapper já estavam configurados na VPS. Não foram recriados. O workflow valida SHA, usuário, host e porta antes de chamar o SSH.

## O que a VPS executa

O wrapper root existente trabalha em `/var/www/pingofin-back`, com ambiente privado, lock de deploy e backup exclusivo. Confirma que o SHA solicitado corresponde à main atual e que o código está limpo antes de trocar a versão. Um SHA ultrapassado por outro push é recusado antes do checkout.

O procedimento atual constrói a imagem **na VPS**, valida o ambiente, cria dump MySQL, aplica migrations, troca a API e o worker e verifica liveness/readiness locais. A migração para publicar imagens prontas em um registry é uma evolução opcional, não uma condição para este deploy automático funcionar.

Na falha de ativação da API, o wrapper tenta restaurar sua imagem anterior. Isso não reverte migrations ou operações financeiras; compatibilidade de schema e restauração de backups exigem procedimentos próprios. O teste de gzip do dump não comprova restore.

Depois do comando SSH, o workflow exige sucesso dos dois endpoints HTTPS públicos:

- `https://api.pingofin.com.br/api/v1/health/live`
- `https://api.pingofin.com.br/api/v1/health`

Esses checks não substituem o teste do frontend autenticado, isolamento por empresa, restauração de backup ou monitoramento operacional.

## Conferência

Os YAMLs e scripts embutidos passaram no actionlint antes da publicação. O primeiro push desta configuração deve ser acompanhado até o job de deploy terminar; conferir o SHA em execução na VPS e a saúde pública. Não tratar somente a criação do workflow como prova de CD funcional.

Não alterar os sites, PHP-FPM, certificados ou serviços de outros projetos para atualizar o PingoFin. As credenciais e a APP_KEY existentes devem permanecer estáveis entre releases.

Referência: [workflows reutilizáveis do GitHub](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows).

Ajuste pontual no wrapper existente em 03/11/2026: o preflight passou a usar `PINGOFIN_IMAGE="$NEW_IMAGE" dc run ...`, selecionando a imagem recém-construída no Compose. A versão anterior foi preservada em backup root; `bash -n` passou. Não houve alteração de usuários, chaves, secrets ou sudoers.

## Permissões dos fontes

O checkout da VPS é feito com umask 077. O Dockerfile normaliza leitura/travessia do código na imagem e remove escrita para grupo/outros; configs fora da raiz da aplicação têm modo explícito. Somente storage e bootstrap/cache pertencem ao runtime. O CI constrói a imagem com fontes 600/diretórios 700 e exige que página, assets, API e worker funcionem. Nenhuma permissão de ambiente, backup ou volume de produção é relaxada.

A conexão SSH tem até três tentativas de estabelecimento; isso não reexecuta o comando remoto depois de uma conexão já estabelecida ou de um resultado desconhecido.
