# PingoFin — Backend CRM

API Laravel, banco, autenticação, Policies, regras financeiras, auditoria, testes e infraestrutura local. Não fornecer nem armazenar logs de agentes no banco.

Leia README.md, ../pingo-doc/AGENTS.md, ../pingo-doc/MIGRACAO_REPOSITORIOS.md e, antes de alterar código do produto, ../pingo-doc/DEFINICAO_PRODUTO.md, ARCHITECTURE.md, DESIGN_SYSTEM.md e docs/referencias-visuais/README.md. O estado implementado está no README histórico e nas validações, não apenas no planejamento inicial.



## Histórico obrigatório

A fonte única é o JSON privado em `../pingo-front-crm/data/agent-changes.json`. Toda tarefa de agente deve acrescentar entrada imutável com resumo de até 300 caracteres Unicode e detalhes separados para mudanças complexas. Nunca copie o JSON para outro repositório, public/, bundle do navegador, banco ou localStorage.

No frontend, execute `node scripts/check-agent-changes.mjs --worktree` e inclua o JSON no stage. Commit do frontend deve preceder commits relacionados nos demais repositórios. Neles, atualize e inclua `.agent-change.json` com ID novo e SHA completo do commit do frontend que contém o registro. Os hooks leem o registro diretamente desse commit, recusam IDs reutilizados e não mantêm cópias do histórico.

Ative em cada clone: `git config --local core.hooksPath .githooks`. Não contornar a trava. Clone o frontend como irmão ou configure `PINGOFIN_FRONT_ROOT`. Linux usa Node compatível no PATH; Windows pode executar o setup-node.ps1 local.
