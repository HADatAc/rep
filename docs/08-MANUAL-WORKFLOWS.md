# Manual 8 - Como Criar, Modelar e Utilizar Workflows

**Módulo:** STD (Study Elements) + CTT (Workflow Editor)  
**Contexto PMSR:** Workflows definem processos/tarefas executáveis em Cenários (Studies)  
**Versão:** 2.0 | Abril 2026

> 🌐 **Versão em inglês disponível:** [Open English version](08-MANUAL-WORKFLOWS-EN.md)

---

## Índice

1. [Visão Geral](#1-visão-geral)
2. [Terminologia e Conceitos-Chave](#2-terminologia-e-conceitos-chave)
3. [Pré-requisitos](#3-pré-requisitos)
4. [Aceder à Gestão de Workflows](#4-aceder-à-gestão-de-workflows)
5. [A Página de Gestão (Lista)](#5-a-página-de-gestão-lista)
6. [Criar um Novo Workflow](#6-criar-um-novo-workflow)
7. [Editar um Workflow (metadados)](#7-editar-um-workflow-metadados)
8. [Modelar o Workflow no Editor de Tarefas (CTT)](#8-modelar-o-workflow-no-editor-de-tarefas-ctt)
9. [Usar um Workflow num Cenário (Study)](#9-usar-um-workflow-num-cenário-study)
10. [Resolução de Problemas](#10-resolução-de-problemas)

---

## 1. Visão Geral

Um **Workflow** é a definição de um processo composto por uma **árvore de tarefas** (*task model*) que pode ser:
- **modelado** (criar/organizar tarefas e sub-tarefas)
- **executado** num **Cenário de Simulação Médica (Study)**, onde se registam respostas/valores

No PMSR, um Workflow é tipicamente utilizado para descrever o “guião” operativo de uma simulação:
- passos
- decisões
- inputs necessários
- recolha de dados ao longo da execução

> 🔗 Para gerir Cenários (Studies) e executar Workflows dentro de Cenários, consulte o 🔗 [Manual 7 - Cenários (Studies)](07-MANUAL-STUDIES-SIMULATION-SCENARIOS.md).

---

## 2. Terminologia e Conceitos-Chave

### Workflow vs. “Process”

Em várias partes do sistema, o termo “processo” pode ser usado como sinónimo de **Workflow**. O nome apresentado pode variar com a configuração (**Preferred Names**), em particular a chave `preferred_process`.

### Workflow Stem

Para criar um Workflow, é necessário selecionar um **Workflow Stem**:
- funciona como “tipo/template” do Workflow
- é escolhido através de uma árvore (modal) com tipos

> ℹ️ Existe uma página de gestão de **Workflow Stems** em STD. Este manual explica o essencial para conseguir criar Workflows, mas não substitui documentação específica sobre Stems.

### Task Model (Árvore de Tarefas)

Cada Workflow tem um **Top Task** (tarefa raiz) e, a partir daí:
- tarefas podem ter **sub-tarefas** (estrutura em árvore)
- tarefas têm um **tipo** (selecionado a partir de tipos/ontologia)
- tarefas podem (dependendo do editor) referenciar instrumentos/simuladores necessários

### Modelagem vs. Execução

- **Modelagem (Edit mode):** desenhar/editar a estrutura do Workflow e suas tarefas.
- **Execução (Execution mode):** seguir o Workflow num Cenário e registar respostas.

---

## 3. Pré-requisitos

### 3.1 Conta e permissões

- **Autenticação:** é necessário estar autenticado.
- Para abrir o **editor CTT**, é necessária a permissão **access ctt editor**.

> Se o botão **Model Task Editor** existir mas abrir “Access denied”, contacte o administrador.

### 3.2 Workflow Stems disponíveis

No formulário de criação de Workflows, o campo **Workflow Stem** é **obrigatório**.

Se não encontrar um Stem adequado:
- verifique **Study Elements → Manage Elements → Manage Workflow Stems**
- crie um novo Stem ou derive a partir de um existente (quando disponível)

### 3.3 Instrumentos/Simuladores (opcional, mas comum)

Muitos Workflows requerem **Instruments/Simulators** para recolha de dados durante tarefas.

- Para criar/gerir Simulators: 🔗 [Manual 1 - Instruments / Simulators](01-MANUAL-INSTRUMENTS-SIMULATORS.md)
- Para garantir Components disponíveis: 🔗 [Manual 3 - Components](03-MANUAL-COMPONENTS.md)

---

## 4. Aceder à Gestão de Workflows

### Caminho de Navegação

```
Menu Principal → Study Elements → Manage Elements → Manage Workflows
```

**URL direto (lista):** `https://www.pmsr.net/std/select/workflow/1/9`

---

## 5. A Página de Gestão (Lista)

A página **Manage Workflows** lista os Workflows mantidos pelo seu utilizador.

### Modos de Visualização

| Botão | Descrição |
|-------|-----------|
| **Table View** | Vista em tabela com botões de ação por linha |
| **Card View** | Vista em cartões |

### Ações (Table View)

Por cada Workflow, tipicamente encontrará:
- **View**: abre a descrição do Workflow (URI)
- **Edit**: abre o formulário de edição de metadados
- **Model Task Editor**: abre o editor (CTT) para modelar a árvore de tarefas
- **Delete**: remove o Workflow (com confirmação)

> ⚠️ **Nota:** dependendo da configuração, o botão **Model Task Editor** pode abrir um editor embebido no Drupal (CTT) ou um editor externo configurado.

---

## 6. Criar um Novo Workflow

### Sequência de criação (resumo)

📋 **Passo 1.** Aceda a **Manage Workflows**.

📋 **Passo 2.** Clique em **Add New Workflow**.

📋 **Passo 3.** Selecione um **Workflow Stem**.

📋 **Passo 4.** Defina o **Top Task** (nome e tipo).

📋 **Passo 5.** (Opcional) associe imagem/documento.

📋 **Passo 6.** Clique em **Save**.

**URL direto (criação):** `https://www.pmsr.net/std/manage/addworkflow/active`

### Campos do Formulário

| Campo | Tipo | Obrigatório | Descrição |
|------|------|-------------|-----------|
| **Workflow Stem** | Texto (abre modal de árvore) | **Sim** | Tipo/template do Workflow. Clique no campo para abrir a árvore e selecionar. |
| **Name** | Texto | **Sim** | Nome do Workflow. |
| **Language** | Select | Não (recomendado) | Idioma do Workflow (por omissão `en`). |
| **Version** | Apenas leitura | Auto | Inicia em 1. |
| **Description** | Texto longo | **Sim** | Descrição do propósito e contexto do Workflow. |
| **Top Task Name** | Texto | **Sim** | Nome da tarefa raiz (Top Task). |
| **Top Task Type** | Texto (abre modal de árvore) | **Sim** | Tipo ontológico da tarefa raiz. Selecionado em árvore (Task types). |

### Imagem e Documento (opcional)

- **Image Type:** `URL` ou `Upload`
- **Web Document Type:** `URL` ou `Upload`

> ⚠️ As opções e limites de upload seguem o padrão PMSR (ex.: 2MB; PNG/JPG para imagens; PDF/DOC/DOCX/TXT/XLS/XLSX para documentos).

---

## 7. Editar um Workflow (metadados)

📋 **Passo 1.** Na lista de Workflows, clique em **Edit**.

📋 **Passo 2.** Atualize os campos (Stem, Name, Language, Description, imagem/documento).

📋 **Passo 3.** Clique em **Update**.

### Versionamento

O PMSR pode aplicar um comportamento de versionamento (dependente do status no backend):
- Se o Workflow estiver em estados “publicados” (ex.: *Current*/*Deprecated*), uma atualização pode resultar na criação de uma **nova versão**
- Se estiver em rascunho, a atualização pode substituir o registo

> ℹ️ Este comportamento depende do estado devolvido pela API e das regras do ambiente.

### Editar o Task Model

No formulário de edição pode existir o botão **Edit Task Model** (ou na lista: **Model Task Editor**). Ambos apontam para o editor CTT.

---

## 8. Modelar o Workflow no Editor de Tarefas (CTT)

O **CTT Workflow Editor** é o editor onde se modela a árvore de tarefas do Workflow.

### 8.1 Abrir o editor

Há duas formas comuns:

1) Na lista **Manage Workflows**, clique em **Model Task Editor**.

2) No formulário **Edit Workflow**, clique em **Edit Task Model**.

O editor abre normalmente com um URL do tipo:

`/ctt/editor?processUri=...`

> ⚠️ **Permissões:** o editor requer permissão **access ctt editor**.

### 8.2 O que tipicamente se faz no editor

Sem assumir detalhes específicos de UI (que podem variar por versão), o editor é usado para:
- visualizar a árvore de tarefas (Top Task → sub-tasks)
- adicionar/remover/reordenar tarefas
- ajustar o **tipo** de tarefas
- associar recursos necessários (ex.: **Instruments/Simulators**) a tarefas quando aplicável
- preparar o modelo para execução em Cenários

> 🔗 Para garantir que os simuladores e componentes necessários existem, consulte os manuais 01–03.

### 8.3 Boas práticas de modelagem

- Use nomes de tarefas claros e orientados à ação (ex.: “Preparar ambiente”, “Executar medição”, “Registar resultados”).
- Mantenha a árvore estável: alterações estruturais grandes perto da execução tornam o registo mais difícil.
- Se o Workflow é reutilizado em múltiplos Cenários, evite detalhes demasiado específicos do Cenário; coloque essas variantes no Cenário (Study) e nos dados.

---

## 9. Usar um Workflow num Cenário (Study)

A associação de um Workflow a um Cenário é feita no **momento de execução**:

📋 **Passo 1.** No Cenário, abra **Manage Study Elements**.

📋 **Passo 2.** Em **Workflow Executions**, clique em **Create Execution**.

📋 **Passo 3.** Se aparecer o formulário de seleção, escolha o Workflow e clique **Run**.

📋 **Passo 4.** O editor abre em modo de execução e o utilizador segue as tarefas.

> ℹ️ O PMSR guarda a associação Cenário → Workflow localmente para execuções futuras.

> 🔗 **Exemplo prático (mini) Workflow + Cenário:** consulte o [Manual 7 - Cenários (Studies)](07-MANUAL-STUDIES-SIMULATION-SCENARIOS.md), secção **8.5**.

---

## 10. Resolução de Problemas

### “Task Editor unavailable” / botão abre alerta

Causas comuns:
- módulo do editor embebido não está ativo
- não existe URL externo configurado para o editor

Solução:
- validar com a equipa técnica se o editor **CTT** está instalado/ativo
- validar configuração `rep.settings.ctt_url` (quando aplicável)

### Não aparece nenhum Workflow para executar

- o Cenário só consegue selecionar Workflows mantidos pelo utilizador atual
- solução: criar Workflow com o seu utilizador, ou pedir permissão/transferência

### “Access denied” no editor

- falta permissão **access ctt editor**
- solução: pedir a um administrador para atribuir a permissão

---

*Documentação criada para o Grupo de Trabalho PMSR - Abril 2026*
