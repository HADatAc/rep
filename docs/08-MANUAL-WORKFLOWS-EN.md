# Manual 8 - How to Create, Model, and Use Workflows

**Module:** STD (Study Elements) + CTT (Workflow Editor)  
**PMSR Context:** Workflows define executable processes/tasks used in Scenarios (Studies)  
**Version:** 2.0 | April 2026

> 🌐 **Portuguese version available:** [Open Portuguese version](08-MANUAL-WORKFLOWS.md)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Terminology and Key Concepts](#2-terminology-and-key-concepts)
3. [Prerequisites](#3-prerequisites)
4. [Accessing Workflow Management](#4-accessing-workflow-management)
5. [The Management Page (List)](#5-the-management-page-list)
6. [Creating a New Workflow](#6-creating-a-new-workflow)
7. [Editing a Workflow (metadata)](#7-editing-a-workflow-metadata)
8. [Modeling the Workflow in the Task Editor (CTT)](#8-modeling-the-workflow-in-the-task-editor-ctt)
9. [Using a Workflow in a Scenario (Study)](#9-using-a-workflow-in-a-scenario-study)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Overview

A **Workflow** is the definition of a process composed of a **task tree** (*task model*) that can be:
- **modeled** (create/organize tasks and sub-tasks)
- **executed** in a **Medical Simulation Scenario (Study)**, where answers/values are recorded

In PMSR, a Workflow is typically used to describe the operational “script” of a simulation:
- steps
- decisions
- required inputs/resources
- data collection during execution

> 🔗 To manage Scenarios (Studies) and run Workflows inside Scenarios, see 🔗 [Manual 7 - Scenarios (Studies)](07-MANUAL-STUDIES-SIMULATION-SCENARIOS-EN.md).

---

## 2. Terminology and Key Concepts

### Workflow vs. “Process”

In some parts of the system, “process” may be used as a synonym for **Workflow**. The label shown in the UI can vary with configuration (**Preferred Names**), in particular `preferred_process`.

### Workflow Stem

To create a Workflow you must select a **Workflow Stem**:
- acts as a “type/template” for a Workflow
- is selected through a tree modal with available types

> ℹ️ STD provides a **Workflow Stems** management page. This manual explains what you need to create Workflows, but it is not a full Stem manual.

### Task Model (Task Tree)

Each Workflow has a **Top Task** (root) and:
- tasks can have **sub-tasks** (tree structure)
- tasks have a **type** (selected from ontology/types)
- tasks can (depending on editor capabilities) reference required instruments/simulators

### Modeling vs. Execution

- **Modeling (Edit mode):** design/edit the Workflow structure and tasks.
- **Execution (Execution mode):** follow the Workflow in a Scenario and record answers.

---

## 3. Prerequisites

### 3.1 Account and permissions

- **Authentication:** you must be logged in.
- To open the **CTT editor**, the user needs **access ctt editor** permission.

> If **Model Task Editor** exists but opens “Access denied”, contact an administrator.

### 3.2 Available Workflow Stems

In the Workflow creation form, **Workflow Stem** is **required**.

If you cannot find a suitable stem:
- check **Study Elements → Manage Elements → Manage Workflow Stems**
- create a new Stem or derive from an existing Stem (when available)

### 3.3 Instruments/Simulators (optional, but common)

Many Workflows require **Instruments/Simulators** for data collection during tasks.

- To create/manage Simulators: 🔗 [Manual 1 - Instruments / Simulators](01-MANUAL-INSTRUMENTS-SIMULATORS-EN.md)
- To ensure Components exist: 🔗 [Manual 3 - Components](03-MANUAL-COMPONENTS-EN.md)

---

## 4. Accessing Workflow Management

### Navigation Path

```
Main Menu → Study Elements → Manage Elements → Manage Workflows
```

**Direct URL (list):** `https://www.pmsr.net/std/select/workflow/1/9`

---

## 5. The Management Page (List)

The **Manage Workflows** page lists the Workflows maintained by your user.

### View Modes

| Button | Description |
|--------|-------------|
| **Table View** | Table view with per-row action buttons |
| **Card View** | Card view |

### Actions (Table View)

Per Workflow, you typically have:
- **View**: opens the Workflow description page (URI)
- **Edit**: opens the metadata edit form
- **Model Task Editor**: opens the CTT editor to model the task tree
- **Delete**: deletes the Workflow (with confirmation)

> ⚠️ **Note:** depending on configuration, **Model Task Editor** may open an embedded Drupal editor (CTT) or an externally configured editor.

---

## 6. Creating a New Workflow

### Creation sequence (summary)

📋 **Step 1.** Open **Manage Workflows**.

📋 **Step 2.** Click **Add New Workflow**.

📋 **Step 3.** Select a **Workflow Stem**.

📋 **Step 4.** Define the **Top Task** (name and type).

📋 **Step 5.** (Optional) attach image/document.

📋 **Step 6.** Click **Save**.

**Direct URL (create):** `https://www.pmsr.net/std/manage/addworkflow/active`

### Form fields

| Field | Type | Required | Description |
|------|------|----------|-------------|
| **Workflow Stem** | Text (opens tree modal) | **Yes** | Workflow type/template. Click the field to open the tree and select. |
| **Name** | Text | **Yes** | Workflow name. |
| **Language** | Select | No (recommended) | Workflow language (default `en`). |
| **Version** | Read-only | Auto | Starts at 1. |
| **Description** | Long text | **Yes** | Purpose and context description. |
| **Top Task Name** | Text | **Yes** | Root task name (Top Task). |
| **Top Task Type** | Text (opens tree modal) | **Yes** | Ontology type for the root task (task types tree). |

### Image and Web Document (optional)

- **Image Type:** `URL` or `Upload`
- **Web Document Type:** `URL` or `Upload`

> ⚠️ Upload options and limits follow PMSR conventions (e.g., 2MB; PNG/JPG for images; PDF/DOC/DOCX/TXT/XLS/XLSX for documents).

---

## 7. Editing a Workflow (metadata)

📋 **Step 1.** In the Workflow list, click **Edit**.

📋 **Step 2.** Update fields (Stem, Name, Language, Description, image/document).

📋 **Step 3.** Click **Update**.

### Versioning

Depending on backend status, PMSR may apply versioning behavior:
- if the Workflow is already in “published” states (e.g., *Current*/*Deprecated*), updating may create a **new version**
- if it is a draft, updating may overwrite the record

> ℹ️ Behavior depends on the status returned by the API and environment rules.

### Editing the Task Model

The edit form may include an **Edit Task Model** button (and the list includes **Model Task Editor**). Both open the CTT editor.

---

## 8. Modeling the Workflow in the Task Editor (CTT)

The **CTT Workflow Editor** is where you model the Workflow task tree.

### 8.1 Opening the editor

Common options:

1) In **Manage Workflows**, click **Model Task Editor**.

2) In **Edit Workflow**, click **Edit Task Model**.

The editor usually opens with a URL like:

`/ctt/editor?processUri=...`

> ⚠️ **Permissions:** the editor requires **access ctt editor**.

### 8.2 What you typically do in the editor

Without assuming UI details (which may vary by version), the editor is used to:
- view the task tree (Top Task → sub-tasks)
- add/remove/reorder tasks
- adjust task **types**
- associate required resources (e.g., **Instruments/Simulators**) to tasks when applicable
- prepare the model for execution inside Scenarios

> 🔗 To ensure required simulators/components exist, see manuals 01–03.

### 8.3 Modeling best practices

- Use clear, action-oriented task names (e.g., “Prepare environment”, “Execute measurement”, “Record results”).
- Keep the structure stable: large changes close to execution make data capture harder.
- If the Workflow is reused across Scenarios, avoid Scenario-specific details in the Workflow itself.

---

## 9. Using a Workflow in a Scenario (Study)

The Scenario ↔ Workflow association happens at **execution time**:

📋 **Step 1.** In the Scenario, open **Manage Study Elements**.

📋 **Step 2.** Under **Workflow Executions**, click **Create Execution**.

📋 **Step 3.** If the selection form appears, choose the Workflow and click **Run**.

📋 **Step 4.** The editor opens in execution mode and the user follows the tasks.

> ℹ️ PMSR stores the Scenario → Workflow association locally for future runs.

> 🔗 **Practical mini example (Workflow + Scenario):** see [Manual 7 - Studies/Scenarios](07-MANUAL-STUDIES-SIMULATION-SCENARIOS-EN.md), section **8.5**.

---

## 10. Troubleshooting

### “Task Editor unavailable” / button opens an alert

Common causes:
- embedded editor module is not enabled
- no external editor URL is configured

Fix:
- validate that **CTT** is installed/enabled
- validate configuration `rep.settings.ctt_url` (when applicable)

### No Workflows are available to execute

- Scenario selection is based on Workflows maintained by the current user
- fix: create the Workflow under your user, or request access/transfer

### “Access denied” in the editor

- missing **access ctt editor** permission
- fix: ask an administrator to grant it

---

*Documentation created for the PMSR Working Group - April 2026*
