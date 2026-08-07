# DragoraPanel

A Minecraft server management panel with a plugin marketplace, desktop bridge and distributed node agents. Manage game servers from a web UI, install plugins from the DragoraLabs store, and let the AI Maker edit files on your PC through DragoraBridge.

## Architecture

| Component | Location | Stack | Port | Role |
|-----------|----------|-------|------|------|
| Panel | `/` (repo root) | Laravel 11, PHP 8.3+, MySQL, Redis | 8050 | Web UI, REST API, admin |
| Store | `store/` | Node.js 20+, Express 4 | 3063 | Plugin marketplace storefront (reuses the `dragorastore` DB) |
| Node agent | `node_agent/` (Node.js) and `node_agent_go/` (Go) | Node 20+ / Go 1.26 | 8055 | Runs on game servers: containers, console, files, backups, scheduler |
| DragoraBridge | `bridge/electron/` | Electron 33 | - | Desktop app linking your PC folder to the store (AI Maker) |
| SDKs | `dragoraapi/` | npm + PyPI | - | Client packages: `dragoraapi` (node), `dragoraapi` (python) |
| Eggs | `eggs/` | PHP | - | Extracted egg system (migrations, models, controller, routes) |

The panel and the standalone marketplace site are **the same Laravel codebase** in two modes:

- Panel mode: `STORE_MODE=false` (default) - landing page, `/auth/login`, panel SPA at `/panel/*`.
- Store mode: `STORE_MODE=true` - plugin marketplace website (e.g. `plugins.dragoralabs.qzz.io`).

## Repository structure

```
.
├── app/            Laravel application code (controllers, models, middleware)
├── routes/         web.php (SPA/landing), api.php (REST + agent-facing routes)
├── resources/      Blade views (landing, auth, panel SPA, marketplace)
├── public/         Web root
├── store/          Node.js plugin storefront (Express + EJS)
├── node_agent/     Node.js node agent
├── node_agent_go/  Go node agent (native, Docker runtime, config.yml)
├── bridge/electron DragoraBridge desktop app (electron-builder NSIS)
├── dragoraapi/     SDK packages for npm and PyPI
├── eggs/           Egg system source (see eggs/README.md)
├── database/       Migrations
└── docs/           store documentation
```

## Requirements

- PHP 8.3+ with extensions: `pdo_mysql mbstring xml curl gd bcmath fileinfo openssl ctype json tokenizer`
- Composer 2.x
- Node.js 20+ with npm (store, agents, bridge)
- MySQL 8.0+ or MariaDB 10.3+
- Redis 6+ (cache, queue, sessions)
- Java 21+ on node agents (game servers)
- Docker (agent runtime engine)

## Installation

### 1. Panel

```bash
git clone <repo-url> dragorapanel
cd dragorapanel
composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate
# edit .env: DB_*, REDIS_*, APP_URL, and set STORE_MODE accordingly

php artisan migrate
php artisan storage:link
```

Start locally:

```bash
php artisan serve --port 8050
```

On first run, call `GET /api/setup` once to seed the default admin account, then sign in at `/auth/login` and change the password.

Panel image files are served from `storage/app/images/` (`logo.png`, `background.png`, `user.png`) via `/panel/logo`, `/panel/background`, `/panel/avatar` and are configurable from the admin UI.

### 2. Store (Node.js storefront)

```bash
cd store
npm install
cp .env .env.local      # create from store/.env; DB_* must point at the dragorastore database
npm start
```

Serve on `http://127.0.0.1:3063`.

### 3. Node agent (Go, recommended)

Build, then register the node from the panel admin UI (Nodes page) to get a `uuid` + token.

```bash
cd node_agent_go
go build -o agent .
cp config.yml.example config.yml
# set node.uuid, node.secret, panel.url
./agent
```

The agent authenticates to the panel with its node token (stored in the `nodes` table), opens a WebSocket on port 8055, and manages game servers (Docker engine, backups, scheduled tasks). The Node.js agent in `node_agent/` is the reference implementation.

### 4. DragoraBridge (desktop)

```bash
cd bridge/electron
npm install
npm start          # dev run
npm run dist       # build signed Windows installer (NSIS)
```

The bridge links a folder on the user's PC to the store so the AI Maker can create, edit and run files there securely. Keep the signing certificate (`*.pfx`) out of the repository.

### 5. SDKs

```bash
# npm
cd dragoraapi/node && npm publish

# PyPI
cd dragoraapi/python && python -m twine upload dist/*
```

## Nginx deployment

Replace `php artisan serve` with Nginx + PHP-FPM pointing at `public/`:

```nginx
server {
    listen 80;
    server_name panel.domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name panel.domain.com;

    root /var/www/panel/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/panel.domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/panel.domain.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 64M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

- Run `certbot --nginx` for SSL.
- For the store mode, either run the Laravel app with `STORE_MODE=true` + its own domain, or proxy to the Node store: `location / { proxy_pass http://127.0.0.1:3063; }`.
- If node agents must reach their WebSocket through Nginx, proxy `location /ws/ { proxy_pass http://127.0.0.1:8055; proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "upgrade"; }`.
- Set `APP_URL` to the public HTTPS URL so Laravel generates correct links behind the proxy.

## Security notes

- `.env` files (panel, store, agents, bridge) hold secrets (DB credentials, API keys, node tokens, code-signing certs) and are gitignored - never commit them.
- `store/public/downloads/`, agent `servers/`, `backups/`, `logs/` and built artifacts (`bridge/electron/dist`, `node-dist`, `node_modules/`) are ignored.
- Rotate all credentials after deployment: DB passwords, `APP_KEY`, store API keys, node tokens, SMTP app passwords.

## Documentation

- `docs/installation.md` - Pterodactyl-style Ubuntu 22.04 install guide (panel + node agent + storefront)
- `PLUGIN-DEVELOPMENT.md` - plugin manifest format and the AI Maker workflow
- `store/docs/` - store API and platform documentation
- `eggs/README.md` - egg system source layout
