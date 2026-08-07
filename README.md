# DragoraPanel

A Minecraft server management panel with a plugin marketplace, desktop bridge and distributed node agents. Manage game servers from a web UI, install plugins from the DragoraLabs store, and let the AI Maker edit files on your PC through DragoraBridge.

## Architecture

| Component | Location | Stack | Port | Role |
|-----------|----------|-------|------|------|
| Panel | `/` (repo root) | Laravel 11, PHP 8.3+, MySQL, Redis | 8050 | Web UI, REST API, admin |


- Panel mode: `STORE_MODE=false` (default) - landing page, `/auth/login`, panel SPA at `/panel/*`.


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


Panel image files are served from `storage/app/images/` (`logo.png`, `background.png`, `user.png`) via `/panel/logo`, `/panel/background`, `/panel/avatar` and are configurable from the admin UI.



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




## Documentation

- `docs/installation.md` - Pterodactyl-style Ubuntu 22.04 install guide (panel + node agent + storefront)
- `PLUGIN-DEVELOPMENT.md` - plugin manifest format and the AI Maker workflow
- `store/docs/` - store API and platform documentation
- `eggs/README.md` - egg system source layout
