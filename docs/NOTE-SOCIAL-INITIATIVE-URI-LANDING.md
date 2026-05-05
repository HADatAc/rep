# PMSR Landing: Social Initiative URI (REP Settings)

## O que mudou

Foi adicionado um novo campo em REP Settings:

- Social Initiative URI for PMSR landing page

Este campo aceita autocomplete de Projects (rota `social.autocomplete_project`) e guarda apenas o URI limpo.

## Regra de prioridade da landing

Na nova landing page PMSR:

1. Se `rep.settings.social_initiative_uri` estiver preenchido, esse URI e usado para o DESCRIBE.
2. Se estiver vazio, continua o fluxo antigo baseado em `social.oauth.settings.client_id`.
3. Se nao houver projeto resolvido, a landing mostra apenas os botoes atuais.
4. A nova landing pode ficar ativa com `pmsr_new_landing_enabled=1` e URI preenchido, mesmo com `social_conf=0`.

## Como configurar

1. Ir a `admin/config/rep`.
2. No campo Social Initiative URI..., procurar e selecionar um Project no autocomplete.
3. Guardar.

## Compatibilidade

- Nao remove comportamento existente.
- Mantem fallback por consumer.
- Campo editavel apenas por quem ja pode aceder a REP Settings (administer site configuration).
