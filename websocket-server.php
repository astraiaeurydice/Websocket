#!/usr/bin/env php
<?php

/**
 * Workerman WebSocket server with an internal HTTP push API.
 *
 * - WebSocket (public): clients connect and authenticate with a JWT.
 *   - Local dev default: ws://127.0.0.1:8080
 *   - Railway: listens on $PORT so it can be exposed publicly as wss://...
 * - Internal HTTP (private): your Symfony API POSTs messages to connected clients.
 *   - Local dev default: http://127.0.0.1:8091
 *   - Railway: bind 0.0.0.0 so the API service can reach it over the private network
 *
 * Env:
 * - PORT: public WebSocket port (Railway)
 * - WORKERMAN_INTERNAL_HOST: default 0.0.0.0
 * - WORKERMAN_INTERNAL_PORT: default 8091
 * - WORKERMAN_INTERNAL_TOKEN: shared secret required for POST pushes
 *
 * Run:
 *   php websocket-server.php start
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$wsPort = (int) (getenv('PORT') ?: 8080);
$internalHost = (string) (getenv('WORKERMAN_INTERNAL_HOST') ?: '0.0.0.0');
$internalPort = (int) (getenv('WORKERMAN_INTERNAL_PORT') ?: 8091);

// ---------------------------------------------------------------------------
// Shared state: authenticated clients keyed by user id
// ---------------------------------------------------------------------------
/** @var array<int|string, TcpConnection> */
$clients = [];

/**
 * Base64url-decode the JWT payload segment (no signature verification).
 */
function decodeJwtPayload(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    $payload = $parts[1];
    $payload = strtr($payload, '-_', '+/');
    $padding = strlen($payload) % 4;
    if ($padding > 0) {
        $payload .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return null;
    }

    $data = json_decode($decoded, true);

    return is_array($data) ? $data : null;
}

function extractUserId(array $payload): int|string|null
{
    if (isset($payload['userId'])) {
        return $payload['userId'];
    }

    // Symfony JwtService stores user id under data.id
    if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['id'])) {
        return $payload['data']['id'];
    }

    return null;
}

function sendJsonResponse(TcpConnection $connection, array $body, int $status = 200): void
{
    $connection->send(new Response(
        $status,
        ['Content-Type' => 'application/json'],
        json_encode($body, JSON_THROW_ON_ERROR)
    ));
}

// ---------------------------------------------------------------------------
// WebSocket worker — listens on PORT for client connections
// ---------------------------------------------------------------------------
$wsWorker = new Worker('websocket://0.0.0.0:' . $wsPort);
$wsWorker->name = 'websocket';

$wsWorker->onConnect = function (TcpConnection $connection): void {
    $connection->authenticated = false;
    $connection->userId = null;
};

$wsWorker->onMessage = function (TcpConnection $connection, string $data) use (&$clients): void {
    if (empty($connection->authenticated)) {
        $message = json_decode($data, true);
        if (!is_array($message) || ($message['type'] ?? '') !== 'auth') {
            $connection->close();
            return;
        }

        $token = $message['token'] ?? '';
        if (!is_string($token) || $token === '') {
            $connection->close();
            return;
        }

        $payload = decodeJwtPayload($token);
        if ($payload === null) {
            $connection->close();
            return;
        }

        $userId = extractUserId($payload);
        if ($userId === null || $userId === '') {
            $connection->close();
            return;
        }

        if (isset($clients[$userId])) {
            $clients[$userId]->close();
        }

        $connection->authenticated = true;
        $connection->userId = $userId;
        $clients[$userId] = $connection;

        $connection->send(json_encode(['type' => 'auth', 'status' => 'ok'], JSON_THROW_ON_ERROR));
        return;
    }
};

$wsWorker->onClose = function (TcpConnection $connection) use (&$clients): void {
    if (!isset($connection->userId)) {
        return;
    }

    $userId = $connection->userId;
    if (isset($clients[$userId]) && $clients[$userId] === $connection) {
        unset($clients[$userId]);
    }
};

// ---------------------------------------------------------------------------
// Internal HTTP worker — private push API (binds on internal host/port)
// ---------------------------------------------------------------------------
$wsWorker->onWorkerStart = function () use (&$clients, $internalHost, $internalPort): void {
    $httpWorker = new Worker('http://' . $internalHost . ':' . $internalPort);
    $httpWorker->name = 'internal-http';

    $httpWorker->onMessage = function (TcpConnection $connection, Request $request) use (&$clients): void {
        if ($request->method() === 'GET') {
            sendJsonResponse($connection, ['status' => 'ok']);
            return;
        }

        if ($request->method() !== 'POST') {
            sendJsonResponse($connection, ['status' => 'method_not_allowed'], 405);
            return;
        }

        $expected = (string) (getenv('WORKERMAN_INTERNAL_TOKEN') ?: '');
        if ($expected !== '') {
            $token = (string) ($request->header('x-internal-token') ?? '');
            if (!hash_equals($expected, $token)) {
                sendJsonResponse($connection, ['status' => 'unauthorized'], 401);
                return;
            }
        } else {
            // In production, require a token
            sendJsonResponse($connection, ['status' => 'token_required'], 401);
            return;
        }

        $body = json_decode($request->rawBody(), true);
        if (!is_array($body)) {
            sendJsonResponse($connection, ['status' => 'invalid_json'], 400);
            return;
        }

        $userId = $body['userId'] ?? null;
        $payload = $body['payload'] ?? null;

        if ($userId === null || !is_array($payload)) {
            sendJsonResponse($connection, ['status' => 'invalid_request'], 400);
            return;
        }

        if (!isset($clients[$userId])) {
            sendJsonResponse($connection, ['status' => 'user_not_connected']);
            return;
        }

        $clients[$userId]->send(json_encode($payload, JSON_THROW_ON_ERROR));
        sendJsonResponse($connection, ['status' => 'sent']);
    };

    $httpWorker->listen();
};

Worker::runAll();

