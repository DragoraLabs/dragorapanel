# DragoraPanel Egg System

Egg system files extracted from the main DragoraPanel project.

## Structure

| File | Description |
|------|-------------|
| `database/migrations/2026_07_22_100000_create_eggs_table.php` | Schema: eggs + egg_variables tables |
| `app/Models/Egg.php` | Egg model (uuid, variables, servers relations) |
| `app/Models/EggVariable.php` | EggVariable model (name, env_variable, rules) |
| `app/Models/Server.php` | Server model patch (adds egg_id + egg() relation) |
| `app/Http/Controllers/EggController.php` | Full CRUD + nested variable management |
| `routes/api.php` | API route entries for egg endpoints |
| `resources/views/panel.blade.php.patch` | Admin UI additions (tab, modals, JS handlers) |
| `public/panel-ext.js` | Frontend JS functions for egg CRUD |
| `node_agent/` | Go agent changes for egg support |

## API Endpoints

| Method | Path | Auth |
|--------|------|------|
| GET | `/api/eggs` | Public (active eggs only) |
| GET | `/api/panel/eggs` | Admin |
| POST | `/api/panel/eggs` | Admin |
| GET/PUT/DELETE | `/api/panel/eggs/{id}` | Admin |
| GET/POST | `/api/panel/eggs/{id}/variables` | Admin |
| PUT/DELETE | `/api/panel/eggs/{id}/variables/{vid}` | Admin |

## Database Changes

- New table: `eggs` (id, uuid, name, description, author, type, docker_image, startup_command, config_files, default_version, java_version, supported_versions, is_active, timestamps)
- New table: `egg_variables` (id, egg_id FK, name, env_variable, default_value, rules, is_required, user_viewable, user_editable, sort_order, timestamps)
- New column: `servers.egg_id` (FK -> eggs.id, nullable)
