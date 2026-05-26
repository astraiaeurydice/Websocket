# Kpop WebSocket Server (Workerman) — Railway Service

This is a standalone WebSocket + internal push server for the Kpop project.

## What it does

- **Public WebSocket**: listens on `PORT` (Railway injects this)  
  Clients connect via `wss://...` and authenticate by sending:

```json
{ "type": "auth", "token": "<JWT>" }
```

- **Internal HTTP push**: listens on `WORKERMAN_INTERNAL_HOST:WORKERMAN_INTERNAL_PORT` (default `0.0.0.0:8091`)  
  Your Symfony API service calls this to push real-time messages to connected users.

## Railway setup

1. Create a new Railway service from this repo.
2. Set variables:
   - `WORKERMAN_INTERNAL_TOKEN` = a random secret (required)
   - (optional) `WORKERMAN_INTERNAL_PORT` = `8091`
3. Deploy.

The service public domain is your **WebSocket URL**:

- `wss://YOUR-WS-SERVICE.up.railway.app`

## Notes about GitHub

This folder is meant to be its **own repository**.

- Commit: `composer.json`, `composer.lock`, `websocket-server.php`, `nixpacks.toml`, `railway.toml`, `README.md`
- Do NOT commit: nothing sensitive here by default (your token goes in Railway Variables)

## API service wiring (Symfony)

On the **API service** (backend), set:

- `WORKERMAN_INTERNAL_URL` = `http://<ws-service-private-host>:8091`
- `WORKERMAN_INTERNAL_TOKEN` = same secret as above

## Local run

```powershell
cd WsServer
composer install
$env:PORT=8080
$env:WORKERMAN_INTERNAL_TOKEN="devtoken"
php websocket-server.php start
```

