# AI Studio 🎬

A Laravel 12 web application for AI-powered creative generation. Users describe what they want in natural language, and the system plans, refines, and executes multi-step **ComfyUI workflows** to produce images, videos, and audio — all orchestrated by a local LLM via Ollama.

---

## Overview

AI Studio is a full-stack agentic pipeline built on Laravel 12. It uses a conversational **OrchestratorAgent** backed by Ollama (default: `mistral:7b`) to interpret user intent, select the right ComfyUI workflows, and build an execution plan. Users can review and refine each step before dispatching jobs to a ComfyUI instance.

The system supports single-workflow and multi-step DAG pipelines (e.g., generate an image → animate it → faceswap), with automatic dependency resolution and parallel execution layering.

---

## Architecture

```
User Request
    │
    ▼
OrchestratorAgent (Ollama LLM)
    │  Outputs signals: READY:<id>, AMBIGUOUS:<id1>,<id2>, CREATE_WORKFLOW:<desc>
    ▼
Plan Builder (pure PHP — no LLM JSON)
    │  Builds DAG with execution layers & keyed depends_on maps
    ▼
Prompt Refinement (WorkflowOptimizerAgent)
    │  LLM refines prompt for each step
    ▼
ExecutePlanJob (Laravel Queue)
    │  Dispatches each step to ComfyUI in dependency order
    ▼
ComfyUI Instance → Generated Assets
```

---

## Key Components

### Agents (`app/Ai/Agents/`)

| Agent | Description |
|---|---|
| `OrchestratorAgent` | Main conversational agent. Interprets user intent, emits structured signals (`READY`, `AMBIGUOUS`, `CREATE_WORKFLOW`), builds multi-step execution plans with DAG dependency resolution. |
| `WorkflowOptimizerAgent` | Refines individual step prompts before execution. |

### Services (`app/Services/`)

| Service | Description |
|---|---|
| `WorkflowBuilderService` | Builds new workflow JSON from user intent, validates nodes against ComfyUI, and saves to the database. |
| `McpService` | Syncs workflows from a remote MCP (Model Context Protocol) source. |

### Skills (`app/Ai/Skills/`)

| Skill | Description |
|---|---|
| `WorkflowBuilderSkill` | LLM-assisted workflow template generation and classification. |
| `WorkflowTemplateLibrary` | Pre-built workflow template patterns. |

### Jobs

| Job | Description |
|---|---|
| `ExecutePlanJob` | Executes a workflow plan step-by-step, respecting execution layers (parallel-safe steps run as a group). |

---

## Routes

### Studio Pipeline

| Method | Path | Description |
|---|---|---|
| `GET` | `/studio` | Main interface — lists available workflows |
| `POST` | `/studio/planner` | Submit user request to OrchestratorAgent |
| `POST` | `/studio/plan/approve` | Approve generated plan |
| `POST` | `/studio/plan/refine-step` | LLM-refine a specific step's prompt |
| `POST` | `/studio/plan/{plan}/confirm` | Confirm a refined step |
| `GET` | `/studio/plan/{plan}/review` | Review plan before dispatch |
| `POST` | `/studio/plan/{plan}/dispatch` | Dispatch plan to ComfyUI |
| `POST` | `/studio/plan/{plan}/queue` | Queue plan for later execution |
| `GET` | `/studio/plan/{plan}/status` | Poll execution status |
| `POST` | `/studio/plan/{plan}/step/{order}/approve` | Approve a completed step |
| `POST` | `/studio/plan/{plan}/step/{order}/cancel` | Cancel a running step |
| `GET` | `/studio/comfy-health` | ComfyUI health check (polled by frontend LED) |
| `GET` | `/studio/generations` | Gallery of completed generations |

### Admin

| Method | Path | Description |
|---|---|---|
| `GET` | `/admin/workflows` | Manage available workflows |
| `POST` | `/admin/workflows/sync` | Sync workflows from MCP |
| `PATCH` | `/admin/workflows/{id}/toggle` | Enable/disable a workflow |
| `POST` | `/admin/workflows/comfy-import` | Import workflow from ComfyUI |
| `POST` | `/admin/workflows/comfy-import-json` | Import workflow from raw JSON |

---

## Orchestrator Agent Signals

The `OrchestratorAgent` separates LLM concerns from plan construction. The LLM outputs only natural conversation plus a single structured signal on the final line:

| Signal | Meaning |
|---|---|
| `READY:<id>` | Single workflow selected — build plan immediately |
| `READY:<id1>,<id2>` | Multi-step chain — build DAG plan |
| `AMBIGUOUS:<id1>,<id2>` | Multiple workflows could handle the request — ask user |
| `CREATE_WORKFLOW:<desc>` | No matching workflow exists — prompt admin to add one |
| `INTENT:<description>` | Per-step intent hint for multi-step plans (precedes READY) |
| `DEPS:<map>` | Explicit dependency graph override (advanced models) |

Degenerate LLM output (token repetition loops, high non-ASCII density) is detected and automatically retried once before returning a safe fallback message.

---

## Execution Layers (DAG)

Multi-step plans are assigned execution layers so independent steps can run concurrently:

```
Step 0: text → image (scene)         Layer 0  — no deps
Step 1: text → image (face portrait) Layer 0  — no deps
Step 2: image → video                Layer 1  — depends on step 0 (image)
Step 3: faceswap                     Layer 2  — depends on step 1 (image) + step 2 (video)
```

The executor processes all Layer 0 steps before Layer 1, guaranteeing upstream outputs exist when downstream steps run.

---

## AI Providers

Configured via [Prism PHP](https://github.com/echolabsdev/prism) (`config/prism.php`):

- **Ollama** (default, local) — `http://172.16.10.11:11435`
- OpenAI, Anthropic, Mistral, Groq, Gemini, DeepSeek, xAI, ElevenLabs, OpenRouter, Perplexity, VoyageAI, Z.ai

Set the default model in `StudioController`:
```php
protected string $model = 'mistral:7b';
```

---

## Database Schema

| Table | Description |
|---|---|
| `workflows` | Available ComfyUI workflow definitions (JSON, input/output types) |
| `workflow_plans` | User generation sessions with status tracking |
| `workflow_plan_steps` | Individual steps within a plan (order, prompt, execution layer, depends_on) |

---

## Setup

### Requirements

- PHP 8.3+
- Laravel 12
- Composer
- Node.js + NPM
- Ollama (with `mistral:7b` pulled)
- ComfyUI instance

### Installation

```bash
# Clone and install PHP dependencies
composer install

# Install frontend dependencies
npm install && npm run build

# Configure environment
cp .env.example .env
php artisan key:generate

# Set your ComfyUI URL in .env
COMFYUI_URL=http://your-comfyui-host:8188

# Set Ollama URL
OLLAMA_URL=http://your-ollama-host:11435

# Run migrations
php artisan migrate

# Start queue worker
php artisan queue:work

# Start server
php artisan serve
```

### Docker (Supervisor)

A `docker/supervisor/supervisord.conf` is included for running the Laravel queue worker alongside the web server in a containerized environment.

---

## Admin Setup

Before using the Studio, seed workflows via the admin panel at `/admin/workflows`. You can:
- Import workflows directly from your ComfyUI instance
- Import raw ComfyUI JSON
- Sync from a remote MCP server
- Toggle workflows active/inactive

Each workflow should have its `input_types` and `output_type` set correctly (e.g., `image`, `video`, `audio`) so the Orchestrator can route requests correctly.

---

## Tech Stack

- **Laravel 12** — PHP framework
- **Prism PHP** — LLM provider abstraction
- **Ollama** — local LLM inference
- **ComfyUI** — image/video/audio generation backend
- **Laravel Queues** — async job execution
- **Blade** — templating

---

## License

This project is unlicensed. All rights reserved to the author.
