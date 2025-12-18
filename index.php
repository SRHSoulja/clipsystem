<?php
// Launch bot if not already running
@include_once __DIR__ . '/bot_launcher.php';

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
echo json_encode(["status" => "ok", "service" => "clipsystem"]);
