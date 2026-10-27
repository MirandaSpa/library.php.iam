<?php

// Router of the PHP built-in server used by CurlTransportTest (loopback only).
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/echo') {
    header('Content-Type: application/json');
    header('X-Test: yes');
    http_response_code(201);
    echo json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'body' => file_get_contents('php://input'),
        'custom' => $_SERVER['HTTP_X_CUSTOM'] ?? null,
        'query' => $_SERVER['QUERY_STRING'] ?? '',
    ]);

    return true;
}
if ($path === '/redirect') {
    header('Location: /echo', true, 302);

    return true;
}
if ($path === '/slow') {
    usleep(1500000);
    echo 'late';

    return true;
}
if ($path === '/huge') {
    echo str_repeat('x', 2 * 1048576);

    return true;
}
http_response_code(404);
