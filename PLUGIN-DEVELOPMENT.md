# DragoraLabs — Plugin Developer Guide

Two ways to build plugins for the DragoraLabs Minecraft panel:

1. **Manual** — write a `plugin.json` + code, zip it, upload it to the store.
2. **AI Maker** — let the assistant create, edit, run and delete files **directly on your PC** through DragoraBridge, then upload the result.

---

## Part 1 — Making a plugin for the panel (manual)

### 1.1 The `plugin.json` manifest

Every plugin zip must contain a `plugin.json` manifest.

| Field        | Required | Notes                                                        |
|--------------|----------|--------------------------------------------------------------|
| `unique_id`  | ✅        | Unique id of the plugin, up to 128 chars. **Must not already exist on the store.** |
| `name`       | ✅        | Display name.                                                |
| `version`    | ❌        | Defaults to `1.0.0`, max 32 chars.                           |
| `license`    | ❌        | URL (must be a GitHub repo link if the store enforces it).   |
| `category`   | ❌        | Must be one of the store's configured categories.            |
| `hooks`      | ❌        | JSON object consumed by the panel's plugin system.           |

Minimal manifest:

```json
{
  "unique_id": "my-cool-plugin",
  "name": "My Cool Plugin",
  "version": "1.0.0",
  "hooks": {
    "on_load": "main.js"
  }
}
```

> `unique_id` and `name` are the only hard requirements. Everything else falls back to defaults or is taken from the upload form.

### 1.2 Zip layout

The uploader finds `plugin.json` automatically:

- at the **zip root** (`plugin.json`), case-insensitive, or
- inside a **single top-level folder** (`my-plugin/plugin.json`) — including nested deeper paths inside that one folder.

Example that works:

```
my-cool-plugin/
├── plugin.json
└── main.js
```

### 1.3 Uploading

1. Zip your files (any of the layouts above).
2. Go to the store → upload → choose the zip and an optional logo.
3. Fill in name, version, description, license, category.
4. Submit. The server validates:
   - `plugin.json` exists and has `unique_id` + `name`
   - `unique_id` is not already taken (even by a pending/rejected plugin)
   - license is a GitHub URL if the store setting requires it
5. Status becomes `approved` immediately if auto-approve is on, otherwise it waits for review.

Updates must keep the same `unique_id` as the plugin being edited.

---

## Part 2 — Building plugins with the AI Maker (code mode)

The AI Maker page connects your browser to **your PC folder** through the DragoraBridge app. The assistant can switch between two modes by itself:

- **Chat mode** (default) — the AI only talks: explains, suggests code.
- **Code mode** — the AI acts on your PC and then **always returns to chat mode** and summarizes what it did.

### 2.1 What the AI can do on your PC

| Action | Marker | Example |
|--------|--------|---------|
| Create / edit a file | `<<<SAVE:path>>>` followed by a fenced code block | `<<<SAVE:plugin.json>>>` then the JSON in a ``` block |
| Create a folder (with parents) | `<<<MKDIR:path>>>` | `<<<MKDIR:src>>>` |
| Delete a file or folder | `<<<DELETE:path>>>` | `<<<DELETE:tmp/old.js>>>` |
| Run a PowerShell command (single line) | `<<<EXEC:command>>>` | `<<<EXEC:Get-Date>>>` |
| Run a PowerShell script (multi-line) | `<<<EXEC>>>` + fenced block | see below |
| Run a Node script | `<<<RUN:path.js>>>` | `<<<RUN:hello.js>>>` |

Example of a full code-mode reply:

```
Let me build that for you.

<<<MKDIR:src>>>
<<<SAVE:src/greet.js>>>
```js
console.log("hello from ai");
```
<<<RUN:src/greet.js>>>
<<<EXEC:Get-Date>>>
```

The system executes each marker in order, strips the markers from the message, and reports results back in plain text:

```
Saved `src/greet.js` to your PC (25 bytes).
Ran `src/greet.js` (exit 0):
hello from ai
PowerShell (exit 0):
Thursday, August 6, 2026 9:22:46 PM
```

### 2.2 Rules the AI follows (and you can rely on)

- Paths are **relative to the PC folder** shown in the chat context. No absolute paths, no `..`, no backslashes.
- File content **must** be in a fenced code block right after `<<<SAVE:...>>>` — even for `.txt`, `.json`, `.ps1`.
- Editing = saving the full new content over the same path.
- `<<<RUN:...>>>` is for **`.js` files only** (Node). PowerShell scripts use `<<<EXEC>>>`.
- After any action the assistant switches **back to chat mode** and reports what happened.
- Results are reported by the system — the AI never invents outputs.

### 2.3 Example session

> **You:** Make a folder `my-plugin`, create a `plugin.json` for a plugin called "Hello Panel", add a `main.js` that prints "hi", run it, and zip everything into `build.zip`.

> **AI:** creates `my-plugin/`, writes `plugin.json` and `main.js`, runs `main.js`, zips the folder with PowerShell — then returns to chat mode:
>
> ```
> Done! `build.zip` contains plugin.json + main.js. Upload it on the store page.
> ```

---

## Part 3 — Under the hood (for power users)

### 3.1 Bridge command whitelist

The store relays only these commands to DragoraBridge (path access is sandboxed to the workspace folder):

`list`, `read`, `write`, `delete`, `mkdir`, `rename`, `stat`, `ps`, `run`, `exec`

- `ps` is read-only (running processes).
- `run` / `exec` require explicit confirm flags (`confirmRun` / `confirmExec`), a 15–20 s timeout, and output is capped.
- `write`, `delete`, `mkdir` are sandboxed: they can never escape the workspace folder.

### 3.2 Uploading what the AI made

The folder content is synced live with the chat context, so after a session you can zip the created files (PowerShell `Compress-Archive` works well via `<<<EXEC>>>`) and upload them to the store following Part 1.

---

## Quick reference

```
# make a folder + manifest + code, run it
<<<MKDIR:my-plugin>>>
<<<SAVE:my-plugin/plugin.json>>>
{ "unique_id": "x", "name": "X" }
<<<SAVE:my-plugin/main.js>>>
console.log("hi");
<<<RUN:my-plugin/main.js>>>

# zip it
<<<EXEC:Compress-Archive -Path my-plugin -DestinationPath build.zip -Force>>>

# clean up
<<<DELETE:tmp_test.txt>>>
```
