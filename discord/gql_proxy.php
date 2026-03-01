<?php
/**
 * discord/gql_proxy.php - Proxy Twitch GQL requests for Discord Activity
 *
 * Discord's URL mapping proxy may strip headers needed by Twitch's GQL API.
 * This endpoint proxies the request through our server instead.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$ch = curl_init('https://gql.twitch.tv/gql');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Client-ID: kimne78kx3ncx6brgo4mv6wki5h1ko',
        'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode ?: 502);
echo $response ?: json_encode(['error' => 'GQL request failed']);
