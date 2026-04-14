# Diagramas adicionais (V2)

Este ficheiro lista os **diagramas PNG** sugeridos para complementar os novos conteúdos V2 (Studies/Cenários e Workflows) nos manuais em Markdown e nos DOCX gerados via Pandoc.

> Nota: os diagramas 01–38 já existem em `images/diagrams/{pt,en}/` e estão mapeados nos READMEs dessas pastas.

---

## Onde colocar

- **PT:** `images/diagrams/pt/`
- **EN:** `images/diagrams/en/`

---

## Novos diagramas sugeridos

| # | Onde aparece | Mermaid (origem) | PNG PT (criar) | PNG EN (criar) |
|---:|---|---|---|---|
| 39 | Manual completo (Introdução) | “Relationship Diagram (including Scenarios and Workflows)” | `images/diagrams/pt/d39-diagrama-de-relacao-incluindo-cenarios-e-workflows.png` | `images/diagrams/en/d39-entity-relationship-diagram-with-scenarios-and-workflows.png` |
| 40 | Manual 07 (Studies/Cenários) | “Diagrama conceptual (Cenário ↔ Workflow)” | `images/diagrams/pt/d40-diagrama-conceptual-cenario-workflow.png` | `images/diagrams/en/d40-scenario-workflow-conceptual-diagram.png` |
| 41 | Manual 07 (Exemplo mini) | “Mini Workflow (Entubamento/Aspiração)” | `images/diagrams/pt/d41-workflow-mini-entubamento-aspiracao.png` | `images/diagrams/en/d41-mini-workflow-intubation-aspiration.png` |

---

## Como integrar estes PNG nos manuais

Atualmente, estes diagramas existem como blocos **Mermaid** nos Markdown. Para que apareçam como **imagens** em exportações (DOCX/PDF), é necessário adicionar uma linha de imagem `![](...)` logo após cada bloco Mermaid (à semelhança dos restantes capítulos).

Se criares os PNG com os nomes acima e os colocares nas pastas certas, eu consigo:

1) inserir as referências às imagens nos Markdown relevantes (PT e EN);
2) regenerar os DOCX V2.

---

## Regenerar os DOCX V2 (quando os PNG existirem)

A partir de `rep/docs/`:

```bash
pandoc MANUAL-PMSR-COMPLETO-PT_V2.md --from=gfm --to=docx --reference-doc=MANUAL-PMSR-COMPLETO-PT_V1.docx --output=MANUAL-PMSR-COMPLETO-PT_V2.docx
pandoc MANUAL-PMSR-COMPLETO-EN_V2.md --from=gfm --to=docx --reference-doc=MANUAL-PMSR-COMPLETO-EN_V1.docx --output=MANUAL-PMSR-COMPLETO-EN_V2.docx
```

---

## Notas rápidas de export

- Formato recomendado: **PNG**
- Largura recomendada (para boa legibilidade em DOCX): **~1600–2200 px**
- Manter texto legível (evitar fonte muito pequena), especialmente em árvores de tarefas.
