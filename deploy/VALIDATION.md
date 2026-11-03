# Validação da preparação do backend — 03/11/2026

Escopo: configurar e testar o deployment Docker do PingoFin para coexistir na VPS Hostinger com EspecializaCondutor, TheStarArt e serviços existentes. Não é evidência de publicação em produção.

## Resultados executados

| Verificação | Resultado |
| --- | --- |
| `composer validate --strict` | Passou. |
| `composer check-platform-reqs` | Passou no PHP local 8.5.8; requisitos de produção também verificados durante build PHP 8.5.12. |
| `composer audit --locked --no-dev --no-interaction` | Sem avisos reportados após a correção restrita do Symfony MIME. Não é varredura das bibliotecas do sistema operacional. |
| `vendor/bin/pint --test` | Passou. |
| `php artisan test --compact` | **162 testes, 2.110 assertions**, todos passaram, no MySQL dedicado `pingofin_test`. |
| Proxies e checagem técnica de fila | Quatro testes novos: cabeçalhos de clientes não confiáveis, TLS/IP do proxy configurado sem confiar em Host encaminhado, bloqueio padrão da checagem em produção e opção explícita sem operação de negócio. |
| Build da imagem final | Passou. Tag local `pingofin-api:deploy-20261103`; ID `sha256:830867759870af8bbee091826a71998cb6c16e70dc0f69f83e70d33d81929321`. A tag é evidência local, não publicação em registry. |
| `docker build --check .` | Passou sem avisos na revisão do Dockerfile. |
| Compose de produção | `config --quiet` passou com ambiente sintético; nenhum ambiente real foi impresso. |
| Inicialização de segredos | Teste em container descartável confirmou geração aleatória, permissão 600 e recusa de sobrescrever o arquivo existente, preservando seu hash. |
| Sintaxe dos scripts Bash | Passou. |
| `git diff --check` | Passou. |

O smoke final executou `bash scripts/smoke-production.sh pingofin-api:deploy-20261103` e passou. Criou banco `pingofin_smoke` em tmpfs, aplicou as migrations apenas nesse ambiente e verificou:

- Imagem sem `.env`, cache de segredos do build, PHPUnit ou testes; PHP com extensões necessárias; usuário não root e raiz/código sem permissão de escrita para o runtime.
- Bloqueio de APP_KEY ausente, debug ativo, cookie inseguro, proxy curinga e retry_after incompatível com o timeout do worker.
- Liveness/readiness 200, sessão ausente 401, cookies Secure/HttpOnly e CORS para a origem permitida. A resposta para outra origem não a autoriza nem devolve curinga.
- Acesso negado/inexistente a `.env`, composer.json, arquivos privados e configuração interna.
- Job técnico processado por worker Redis real, com marcador temporário e sem dados de negócio.
- Redis indisponível → readiness 503 e liveness 200; reconexão → readiness 200 e novo job processado pelo worker reiniciado automaticamente.
- Reinício da API → regeneração de caches em runtime e readiness novamente 200.

Os containers/rede do projeto descartável foram encerrados sem apagar volumes persistentes. A infraestrutura local `pingofin-bootstrap` permaneceu disponível; não foram parados serviços de outros projetos.

## Correção de segurança

A inspeção encontrou o aviso [CVE-2026-45070](https://symfony.com/cve-2026-45070) em `symfony/mime` 8.1.7. Foi atualizado somente esse pacote para **8.1.10**, sem mudanças de requisito ou atualização ampla do framework. A suíte e a auditoria foram repetidas após a alteração.

## VPS: inspeção somente de leitura

O acesso SSH existente funcionou com verificação estrita do host, sem nova chave ou senha no chat. Em `srv906410`, foram observados 1 vCPU, 3,8 GiB RAM (aproximadamente 2,8 GiB disponíveis), 33 GB livres, Docker 29.8.1, Compose 5.5.1, Certbot e Nginx cuja configuração passou no teste. PHP-FPM 8.3/8.4 e n8n continuam separados. Porta 8088 e diretório `/opt/pingofin` estavam livres/ausentes.

Nenhum arquivo, serviço, certificado, configuração Nginx, firewall, DNS ou banco foi alterado na VPS. Os repositórios de referência foram somente consultados. Nenhum commit, push ou deploy remoto foi executado nesta preparação.

## Limites e próximos passos

API aprovada: `api.pingofin.com.br`; aplicativo proposto: `app.pingofin.com.br`, ainda a confirmar. Não foi configurado DNS ou emitido TLS. Falta ativar os serviços próprios na VPS, aplicar migrations no banco novo após confirmar o alvo, configurar o vhost/HTTPS e aceitar os fluxos pelo frontend HTTPS.

O smoke não comprova login completo de navegador entre os domínios públicos, desempenho/carga, restauração de backup, monitoramento/alertas ou operação com clientes reais. Esses itens seguem no procedimento de aceite. Nenhum teste fiscal, integração de IA, alteração de Kanban ou implantação de frontend foi realizado.

Os resultados acima são desta preparação; não foram importados dos relatórios históricos de outubro.

## Acompanhamento do primeiro CI remoto

Após o push da preparação, a [validação da API no GitHub](https://github.com/Improhalker/pingo-back-crm/actions/runs/41699737481) passou, mas o smoke da imagem detectou que o Compose do runner recusava `--wait` para o worker com healthcheck HTTP desabilitado. O script e o guia passaram a aguardar apenas API/MySQL/Redis e iniciar o worker separadamente, confirmando prontidão pelo job técnico real. O smoke local completo foi repetido e passou; a correção é publicada para nova validação remota. Nenhum check foi removido ou convertido em sucesso incondicional.
