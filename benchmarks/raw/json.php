<?php

// Plain PHP baseline: a JSON endpoint with no framework at all.

header('Content-Type: application/json; charset=utf-8');

echo json_encode(['pong' => true, 'time' => date('Y-m-d H:i:s')]);
