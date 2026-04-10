# AI Studio System Overview

## 1. Architecture Overview

### 1.1 Technology Stack

- **Backend**: Laravel 11 (PHP 8.4)
- **Database**: MySQL 8.0 + Redis
- **AI Runtime**: Ollama (local LLM) - mistral:7b model
- **Generation Engine**: ComfyUI (remote)
- **MCP Integration**: Python FastMCP sidecar server (joenorton/comfyui-mcp-server)
- **Frontend**: Vanilla JavaScript with SSE streaming
- **Container**: Laravel Sail (Docker)

### 1.2 Service Topology

```
┌─────────────────────────────────────────────────────────────────┐
│                        Laravel Application                       │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │ Studio       │  │ Admin        │  │ Queue               │  │
│  │ Controller   │  │ Controller   │  │ (Redis + Supervisor) │  │
│  └──────────────┘  └──────────────┘  └──────────────────────┘  │
│          │                  │                    │                │
│  ┌───────┴──────────────────┴────────────────────┴────────────┐  │
│  │              McpService (HTTP Client Layer)                │  │
│  └────────────────────────┬───────────────────────────────────┘  │
└────────────────────────────┼──────────────────────────────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
┌───────────────┐    ┌───────────────┐    ┌───────────────┐
│   ComfyUI     │    │     Ollama    │    │   MCP Server  │
│  (Generation) │    │  (LLM Agent)  │    │ (Sidecar API) │
│ 172.16.10.13  │    │ 172.16.10.11  │    │  :9000        │
└───────────────┘    └───────────────┘    └───────────────┘
```

### 1.3 Network Configuration

| Service      | Internal URL                    | External (Host)  |
|--------------|----------------------------------|------------------|
| Laravel      | sail (port 80)                  | localhost:8080   |
| MySQL        | mysql:3306                      | localhost:3307   |
| Redis        | redis:6379                      | localhost:6380   |
| ComfyUI      | http://172.16.10.13:8188       | (remote host)    |
| Ollama       | http://172.16.10.11:11435       | (remote host)    |
| MCP Server   | http://comfyui-mcp:9000/mcp     | localhost:9000   |

---

## 2. Data Models

### 2.1 Workflow

Represents a ComfyUI workflow template stored in the database.

| Column              | Type      | Description                                                    |
|--------------------|-----------|----------------------------------------------------------------|
| id                 | int       | Primary key                                                    |
| type               | string    | Workflow category (comfyui, image, video, audio, etc.)         |
| name               | string    | Human-readable name                                           |
| description        | string    | User-facing description                                        |
| workflow_json      | text      | **API-format JSON** (node graph) for injection               |
| is_active          | boolean   | Whether visible to OrchestratorAgent                          |
| input_types        | json      | Required inputs: `["image"]`, `["video","image"]`, etc.       |
| output_type        | string    | Produces: "image", "video", "audio"                           |
| inject_keys        | json      | Placeholder mapping: `{"image": "{{INPUT_IMAGE}}"}`           |
| comfy_workflow_name| string   | Filename on ComfyUI server (ComfyUI-direct mode)              |
| discovered_at      | datetime  | When workflow was imported                                     |
| default_for_type   | boolean   | Default selection for this output_type                        |
| mcp_workflow_id    | string    | **MCP-sidecar ID** (live-fetch mode, e.g., "generate_image") |

**Execution Modes:**

1. **Stored-JSON**: `workflow_json` stored in DB, read at execution time
2. **ComfyUI-Direct**: `comfy_workflow_name` set, fetch from ComfyUI on-demand
3. **MCP Live-Fetch**: `mcp_workflow_id` set, fetch from MCP sidecar at execution time

### 2.2 WorkflowPlan

Represents a multi-step generation job.

| Column         | Type    | Description                                                   |
|----------------|---------|---------------------------------------------------------------|
| id             | int     | Primary key                                                   |
| session_id     | string  | Laravel session ID (user isolation)                          |
| user_intent    | string  | Original user request                                        |
| plan_steps     | json    | Plan structure (steps array)                                 |
| status         | string  | pending → queued → running → completed/failed                |
| queue_position | int     | Position in backlog queue                                    |
| mood_board     | json    | User-selected reference images                               |
| input_files    | json    | Uploaded files: `[{"media_type": "image", "storage_path": "..."}]` |

### 2.3 WorkflowPlanStep

Represents a single generation step within a plan.

| Column           | Type      | Description                                                    |
|------------------|-----------|----------------------------------------------------------------|
| id               | int       | Primary key                                                    |
| plan_id          | int       | FK → WorkflowPlan                                             |
| workflow_id      | int       | FK → Workflow                                                 |
| step_order       | int       | Execution sequence                                            |
| workflow_type    | string    | Output type of this step                                       |
| purpose          | string    | User-facing description of this step                          |
| refined_prompt   | string    | LLM-refined prompt (approved by user)                         |
| depends_on       | json      | Array of step_order values this step waits on                 |
| input_file_path  | string    | Legacy single-file input path                                  |
| input_files      | json      | Multi-input map: `{"image": "path", "audio": "path"}`        |
| comfy_job_id     | string    | ComfyUI prompt_id for this execution                          |
| mcp_asset_id     | string    | MCP server asset_id for provenance                            |
| status           | string    | pending → running → awaiting_approval → completed/failed        |
| output_path      | string    | Storage-relative path to generated file                      |
| approved_at      | datetime  | When user approved this step's output                         |
| error_message    | string    | Error details if failed                                       |

---

## 3. Generation Pipeline

### 3.1 Pipeline Flow

```
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│                            USER INTERFACE                                              │
│  ┌──────────────┐   ┌──────────────┐   ┌──────────────┐   ┌──────────────────────┐   │
│  │  Studio      │   │   Planning   │   │  Refinement  │   │    Execution         │   │
│  │  Index       │ ─▶│   Phase      │ ─▶│  Phase       │ ─▶│    Phase             │   │
│  │              │   │              │   │              │   │                      │   │
│  │  - Chat UI   │   │  - LLM Chat │   │  - Prompt    │   │  - ExecutePlanJob   │   │
│  │  - Workflows │   │  - Signals  │   │    Optimizer │   │  - Polling          │   │
│  │              │   │  - Plan     │   │  - APPROVED  │   │  - Approval         │   │
│  └──────────────┘   └──────────────┘   └──────────────┘   └──────────────────────┘   │
└──────────────────────────────────────────────────────────────────────────────────────────┘
                                       │
                                       ▼
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│                              ORCHESTRATION LAYER                                        │
│                                                                                          │
│   ┌──────────────────────────────────────────────────────────────────────────────────┐    │
│   │                    OrchestratorAgent                                        │    │
│   │  ┌───────────────────────────────────────────────────────────────────────┐  │    │
│   │  │  Decision Tree:                                                      │  │    │
│   │  │                                                                    │  │    │
│   │  │  STEP 0 — Check user-provided INPUT:media_type:filename           │  │    │
│   │  │  STEP 1 — Single workflow generation (READY:<id>)                  │  │    │
│   │  │  STEP 2 — Multi-step orchestration (READY:<id1>,<id2>,...)         │  │    │
│   │  │  STEP 3 — Build new workflow (CREATE_WORKFLOW:<description>)       │  │    │
│   │  │  STEP 4 — Nothing fits                                             │  │    │
│   │  │                                                                    │  │    │
│   │  │  Signal System: READY:, AMBIGUOUS:, CREATE_WORKFLOW:             │  │    │
│   │  │  (All signals stripped from display, parsed by PHP)               │  │    │
│   │  └───────────────────────────────────────────────────────────────────────┘  │    │
│   └──────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
│   ┌──────────────────────────────────────────────────────────────────────────────────┐    │
│   │                  WorkflowOptimizerAgent                                         │    │
│   │  ┌───────────────────────────────────────────────────────────────────────┐  │    │
│   │  │  Role: Refine user prompt through conversation                       │  │    │
│   │  │  Max 3 turns, outputs APPROVED:<prompt> signal                        │  │    │
│   │  │  Guidance varies by output_type (image/video/audio)                 │  │    │
│   │  └───────────────────────────────────────────────────────────────────────┘  │    │
│   └──────────────────────────────────────────────────────────────────────────────────┘    │
│                                                                                          │
│   ┌──────────────────────────────────────────────────────────────────────────────────┐    │
│   │                  WorkflowBuilderSkill                                           │    │
│   │  ┌───────────────────────────────────────────────────────────────────────┐  │    │
│   │  │  Template-first approach:                                            │  │    │
│   │  │  1. LLM classifies intent → type (image/video/audio/etc.)            │  │    │
│   │  │  2. Select pre-validated template from WorkflowTemplateLibrary       │  │    │
│   │  │  3. Generate human-friendly name                                     │  │    │
│   │  │  4. Save to DB as active                                             │  │    │
│   │  │                                                                    │  │    │
│   │  │  Avoids LLM hallucination of node names/connections                │  │    │
│   │  └───────────────────────────────────────────────────────────────────────┘  │    │
│   └──────────────────────────────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│                              EXECUTION LAYER                                            │
│                                                                                          │
│  ┌──────────────────────────────────────────────────────────────────────────────────┐   │
│  │                         ExecutePlanJob                                           │   │
│  │  ┌──────────────────────────────────────────────────────────────────────────┐ │   │
│  │  │  Main Loop:                                                             │ │   │
│  │  │    1. Find next ready step (pending + isReady())                        │ │   │
│  │  │    2. Collect dependency files                                         │ │   │
│  │  │    3. Upload to ComfyUI                                                │ │   │
│  │  │    4. Inject prompt + files into workflow JSON                        │ │   │
│  │  │    5. Submit job (direct or via MCP)                                  │ │   │
│  │  │    6. Poll for completion                                             │ │   │
│  │  │    7. Free VRAM                                                        │ │   │
│  │  │    8. Wait for user approval/rejection                                │ │   │
│  │  │    9. Loop or complete plan                                           │ │   │
│  │  └──────────────────────────────────────────────────────────────────────────┘ │   │
│  └──────────────────────────────────────────────────────────────────────────────────┘   │
│                                                                                          │
│  Execution Path Selection (ExecutePlanJob:executeStep):                                 │
│                                                                                          │
│  ┌─────────────────────────────────────────────────────────────────────────────────┐  │
│  │  if ($workflow->isComfyuiDirect()) {                                             │  │
│  │      // Fetch from ComfyUI server via MCP sidecar                                │  │
│  │      $graph = $mcp->mcpFetchWorkflowFromComfyUI($workflowName);                 │  │
│  │  } elseif ($workflow->isMcpLiveFetch()) {                                        │  │
│  │      // Fetch from MCP sidecar at runtime                                        │  │
│  │      $graph = $mcp->mcpFetchWorkflowGraph($workflowId);                         │  │
│  │  } else {                                                                        │  │
│  │      // Use stored JSON from database                                           │  │
│  │      $injectedJson = $workflow->injectPrompt($prompt, $inputFiles);           │  │
│  │  }                                                                               │  │
│  └─────────────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌──────────────────────────────────────────────────────────────────────────────────────────┐
│                           COMFYUI EXECUTION                                             │
│                                                                                          │
│  ┌─────────────────┐      ┌─────────────────┐      ┌─────────────────────────────┐    │
│  │   McpService    │ ───▶ │  MCP Server      │ ───▶ │  ComfyUI                   │    │
│  │                 │      │  (Python)        │      │  (Generation Engine)       │    │
│  │                 │      │                  │      │                            │    │
│  │  mcpSubmitJob() │      │  submit_raw_     │      │  /prompt (POST)            │    │
│  │  checkJobStatus │      │  workflow()      │      │  /history/{id} (GET)       │    │
│  │  getJobResult   │      │                  │      │  /queue (GET)              │    │
│  │  freeVram()     │      │                  │      │  /free (POST)              │    │
│  └─────────────────┘      └─────────────────┘      └─────────────────────────────┘    │
│                                                                                          │
│  MCP Protocol (JSON-RPC 2.0 over HTTP):                                                 │
│                                                                                          │
│  POST /mcp                                                                           │
│  {                                                                                    │
│    "jsonrpc": "2.0",                                                                 │
│    "method": "tools/call",                                                           │
│    "params": {                                                                        │
│      "name": "submit_raw_workflow",                                                  │
│      "arguments": { "workflow_json": {...} }                                         │
│    }                                                                                 │
│  }                                                                                    │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 Events & State Transitions

#### Phase 1: Planning

1. User sends message to `/studio/planner` (SSE endpoint)
2. **OrchestratorAgent** receives messages + system prompt with capability list
3. LLM processes through decision tree
4. Signal emitted (READY:, AMBIGUOUS:, CREATE_WORKFLOW:)
5. `StudioController::planner()` parses signal → emits plan or workflow list

#### Phase 2: Plan Approval

1. User reviews plan → POST `/studio/plan/approve`
2. `WorkflowPlan` + `WorkflowPlanStep` records created
3. User uploads input files via `/studio/upload`

#### Phase 3: Prompt Refinement

1. User clicks "Refine" on a step → POST `/studio/plan/refine-step` (SSE)
2. **WorkflowOptimizerAgent** streams prompts
3. User confirms → POST `/studio/plan/{plan}/step/{order}/confirm`
4. `refined_prompt` saved to DB

#### Phase 4: Execution

1. User clicks "Generate" → POST `/studio/plan/{plan}/dispatch`
2. **ExecutePlanJob** dispatched to queue
3. Job runs plan loop, executes each step
4. Polls ComfyUI, waits for approval
5. User approves/rejects → loop continues or plan completes

---

## 4. MCP Integration

### 4.1 MCP Server Architecture

The MCP sidecar (`comfyui-mcp`) is a Python FastMCP server providing:

| Tool                    | Description                                                     |
|-------------------------|-----------------------------------------------------------------|
| `list_workflows`       | Return all workflow descriptors from `/app/workflows/` dir    |
| `get_workflow_json`     | Return raw JSON for a workflow                                  |
| `submit_raw_workflow`  | Submit JSON to ComfyUI, return prompt_id + asset_id            |
| `get_workflow_nodes`   | Return node map (id, class_type, inputs) for admin inspection  |
| `patch_and_submit_workflow` | Load + patch + submit in one call                      |
| `get_workflow_from_comfyui` | Fetch directly from ComfyUI server via MCP            |

**Configuration:**

```yaml
# docker-compose.yml
comfyui-mcp:
  environment:
    COMFYUI_URL: 'http://172.16.10.13:8188'
  volumes:
    - './comfyui-mcp-server/workflows:/app/workflows'
  ports:
    - '9000:9000'
```

### 4.2 McpService Methods

**Direct ComfyUI (always available):**

- `healthCheck()` → GPU VRAM status
- `listWorkflows()` → /object_info node types
- `submitJob()` → /prompt
- `checkJobStatus()` → /history + /queue
- `getJobResult()` → download output files
- `uploadInputFile()` → /upload/image
- `cancelJob()` → /queue DELETE
- `getQueueStatus()` → /queue
- `freeVram()` → /free

**MCP Sidecar (COMFYUI_MCP_ENABLED=true):**

- `mcpListWorkflows()` → list_workflows
- `mcpGetWorkflowJson()` → get_workflow_json
- `mcpSubmitJob()` → submit_raw_workflow (or fallback to direct)
- `mcpFetchWorkflowGraph()` → get_workflow_json (decoded array)
- `mcpGetWorkflowNodes()` → get_workflow_nodes
- `mcpPatchAndSubmit()` → patch_and_submit_workflow
- `mcpFetchWorkflowFromComfyUI()` → get_workflow_from_comfyui

---

## 5. Admin Functionalities

### 5.1 Admin Panel Routes

| Route                           | Controller Method        | Description                              |
|----------------------------------|--------------------------|------------------------------------------|
| GET /admin/workflows             | workflows()              | List all workflows                       |
| PATCH /admin/workflows/{id}/toggle | toggleWorkflow()     | Toggle is_active                         |
| PATCH /admin/workflows/{id}/set-default | setDefault()     | Set as default for output_type           |
| PATCH /admin/workflows/{id}     | updateWorkflow()         | Update metadata (name, description, etc.)|
| DELETE /admin/workflows/{id}    | deleteWorkflow()        | Delete workflow                          |
| GET /admin/workflows/{id}/preview-live | previewLiveWorkflow() | Fetch node map from MCP                  |
| GET /admin/workflows/comfy-list | listComfyWorkflows()    | List workflows from ComfyUI server       |
| POST /admin/workflows/comfy-import | importComfyWorkflow() | Import workflow from ComfyUI             |
| POST /admin/workflows/comfy-import-json | importJsonDirect() | Import via pasted JSON                  |

### 5.2 Live Workflow Preview

The admin panel includes a **preview-live** feature that:

1. Takes a workflow with `mcp_workflow_id` set
2. Calls `McpService::mcpGetWorkflowNodes()`
3. Returns node map: `{node_id: {class_type, inputs}}`
4. Displays editable inputs for admin to understand what can be patched

---

## 6. Artisan Commands

### 6.1 workflows:sync

Discovers workflows from MCP server and imports to database.

```bash
# Dry run
php artisan workflows:sync

# Import with stored JSON
php artisan workflows:sync --import

# Import with live-fetch mode (no JSON stored)
php artisan workflows:sync --import --no-json

# Force overwrite existing
php artisan workflows:sync --import --force
```

**PARAM_MAP translation:**

MCP workflows use `PARAM_*` placeholders. The sync command translates:

- `PARAM_PROMPT` → `{{POSITIVE_PROMPT}}`
- `PARAM_NEGATIVE` → `{{NEGATIVE_PROMPT}}`
- `PARAM_INPUT_IMAGE` → `{{INPUT_IMAGE}}`
- etc.

### 6.2 studio:monitor-orphaned-jobs

Checks steps marked as orphaned (timed out but still running in ComfyUI).

```bash
php artisan studio:monitor-orphaned-jobs
```

---

## 7. Frontend Architecture

### 7.1 JavaScript Modules

| File                    | Responsibility                                         |
|-------------------------|--------------------------------------------------------|
| studio.js               | Core UI init, event binding                           |
| studio.planning.js      | Phase 1: OrchestratorAgent chat UI                    |
| studio.refinement.js    | Phase 2: Prompt optimization UI                       |
| studio.execution.js     | Phase 3: Job polling, approval/rejection               |
| studio.panels.js        | Floating panels (jobs, queue status)                  |

### 7.2 SSE Endpoints

- `/studio/planner` → OrchestratorAgent streaming
- `/studio/plan/refine-step` → WorkflowOptimizerAgent streaming

### 7.3 Polling Endpoints

- `/studio/plan/{plan}/status` → Plan + step status (every 4s)
- `/studio/queue-status` → ComfyUI queue depth (every 10s)
- `/studio/comfy-health` → ComfyUI reachability (LED indicator)

---

## 8. Placeholder Injection System

### 8.1 Standard Placeholders

Workflow JSON supports these placeholders:

| Placeholder           | Description              | Default    |
|-----------------------|-------------------------|------------|
| `{{PROMPT}}`          | Positive prompt         | (user)     |
| `{{POSITIVE_PROMPT}}` | Positive prompt         | (user)     |
| `{{NEGATIVE_PROMPT}}  | Negative prompt         | hardcoded quality negative |
| `{{SEED}}`            | Random seed             | auto       |
| `{{STEPS}}`           | Sampler steps           | 20         |
| `{{CFG}}`             | CFG scale               | 7.0        |
| `{{WIDTH}}`           | Output width            | 512        |
| `{{HEIGHT}}`          | Output height           | 512        |
| `{{FRAME_COUNT}}`     | Video frames            | 16         |
| `{{FPS}}`             | Video FPS               | 8          |
| `{{MOTION_STRENGTH}}` | AnimateDiff strength   | 127        |
| `{{DURATION}}`        | Audio duration (sec)    | 10         |
| `{{SAMPLE_RATE}}`     | Audio sample rate       | 44100      |
| `{{DENOISE}}`         | Denoise strength        | 1.0        |

### 8.2 File Injection

For workflows requiring input files:

1. `inject_keys` column maps media_type → placeholder:
   ```json
   {"image": "{{INPUT_IMAGE}}", "video": "{{INPUT_VIDEO}}"}
   ```

2. At execution, `$inputFiles` map provides ComfyUI-assigned filenames:
   ```json
   {"image": "upload_abc.png"}
   ```

3. `Workflow::performInjection()` replaces both placeholder forms:
   - `{{INPUT_IMAGE}}` → filename
   - `"{{INPUT_IMAGE}}"` → filename (quoted form)

### 8.3 Auto-Seed Pass

After placeholder substitution, the injection system walks every node and replaces any `noise_seed` or `seed` field with a random integer. This ensures every execution produces unique output.

---

## 9. Database Migrations

| Migration                                    | Description                                                |
|----------------------------------------------|------------------------------------------------------------|
| 2025_01_01_000001_create_workflows_table     | Core workflow schema                                       |
| 2025_01_01_000002_create_workflow_plans_table| Plan + multi-step job tracking                             |
| 2025_01_01_000003_create_workflow_plan_steps_table | Individual step records + status machine         |
| 2025_01_01_000004_add_queued_status_to_workflow_plans | Queue backlog support                           |
| 2025_01_01_000004_add_mcp_fields_to_workfloplansteps | comfy_job_id + mcp_asset_id                      |
| 2025_01_01_000005_add_input_files_to_workflow_plans | Plan-level file uploads                         |
| 2025_01_01_000005_add_input_files_to_workflow_plan_steps | Multi-input file support                  |
| 2025_01_01_000006_add_mcp_workflow_id_to_workflows_table | Live-fetch mode via MCP server              |

---

## 10. Configuration

### 10.1 Environment Variables

| Variable                  | Description                              | Default                    |
|---------------------------|------------------------------------------|----------------------------|
| `COMFYUI_BASE_URL`        | ComfyUI server URL                       | http://172.16.10.13:8188 |
| `COMFYUI_MCP_ENABLED`    | Enable MCP sidecar for job submission    | false                      |
| `COMFYUI_MCP_URL`         | MCP server URL                           | http://comfyui-mcp:9000/mcp |
| `OLLAMA_URL`              | Ollama server URL                        | http://172.16.10.11:11435 |
| `APP_PORT`                | Laravel host port                        | 8080                       |
| `COMFYUI_MCP_PORT`        | MCP server port                          | 9000                       |

### 10.2 Services Config

```php
// config/services.php
'ollama' => [
    'url' => env('OLLAMA_URL', 'http://172.16.10.11:11435'),
],
'comfyui_mcp' => [
    'enabled' => env('COMFYUI_MCP_ENABLED', false),
    'url'     => env('COMFYUI_MCP_URL', 'http://comfyui-mcp:9000/mcp'),
],
```

---

## 11. Summary & Root Problem Analysis

### 11.1 Architecture Recap

The AI Studio system consists of:

1. **Laravel Application** - Orchestration layer managing user sessions, workflow metadata, plan execution, and AI agent interactions
2. **OrchestratorAgent** - LLM-based intent classification and multi-step planning with signal-based communication
3. **WorkflowOptimizerAgent** - LLM-based prompt refinement with APPROVED: signal
4. **WorkflowTemplateLibrary** - Pre-validated ComfyUI workflow templates for zero-hallucination workflow creation
5. **ExecutePlanJob** - Queue-driven execution engine with step-by-step generation and user approval gates
6. **McpService** - HTTP client layer with dual paths: direct ComfyUI API and MCP sidecar protocol
7. **MCP Sidecar Server** - Python FastMCP server providing workflow discovery, submission, and asset management

### 11.2 The Core Problem: MCP Workflow Access Gap

**There is a fundamental mismatch between how the ComfyUI server exposes workflows to the MCP container.** This mismatch creates a critical access gap:

1. **ComfyUI stores workflows** in its own `workflows/` directory on the remote ComfyUI server
2. **MCP server expects workflows** in its own local `/app/workflows/` directory
3. **No workflow files exist in the MCP container's workflows directory** - the bind mount is empty
4. **The MCP server cannot reach the remote ComfyUI server's workflow files** - there's no API endpoint that provides file-based workflow listing or retrieval

**This eliminates the filename, Laravel, and MCP logic issues, and clearly identifies the root problem as an access/deployment gap:**

- The ComfyUI server exposes workflows via `/api/userdata?dir=workflows` (list) and `/api/userdata?dir=workflows&file=...` (fetch)
- The MCP server is configured to read from its local filesystem (`/app/workflows/`)
- There is **no connection** between these two - the MCP server has no way to access the ComfyUI server's workflow files
- Neither the HTTP API path nor any file transfer mechanism bridges this gap
- The MCP container effectively has an empty workflows directory, making `list_workflows` return nothing or stale results

**Resolution Options:**

1. **Sync workflows to MCP**: Periodically copy workflow JSON files from ComfyUI server to MCP container's `/app/workflows/` directory
2. **MCP server direct ComfyUI access**: Modify MCP server to fetch from ComfyUI's `/api/userdata` endpoint rather than local filesystem
3. **Bypass MCP entirely**: Use `McpService::fetchComfyuiWorkflow()` to fetch directly from ComfyUI via HTTP API at execution time (already implemented but may fail for remote ComfyUI)
4. **Deploy ComfyUI and MCP together**: Run ComfyUI and MCP in the same Docker network with shared workflow directory

The system is well-architected with clear separation of concerns, but the deployment gap between ComfyUI's remote workflow storage and MCP's local filesystem expectation prevents the live-fetch execution path from working.
