<?php

error_reporting(E_ALL);

set_time_limit(0);
print_r($argv);
const HOST = 'localhost';
if (isset($argv[1]) && ($argv[1] === "-p" || $argv[1] === "--port")) {
    define("PORT", $argv[2]);
} else {
    define("PORT", 6379);
}

echo "port: " . PORT . "\n";

echo "Server started\n";
echo "Listening on " . HOST . ":" . PORT . "\n";
$sock = createSocket();
bindAndListen($sock);

register_shutdown_function('closeSocket', $sock);

$clients = [];
$keyValueRepository = [];
$keyValueRepository["$sock"] = [
    "role" => "master",
    "connected_clients" => 0
];

while (true) {
    $read = $clients;
    $read[] = $sock;
    $write = $except = null;
    if (socket_select($read, $write, $except, 0) < 1) {
        continue;
    }

    if (in_array($sock, $read)) {
        $clients[] = $newsock = socket_accept($sock);
        echo "New client connected\n";
        $key = array_search($sock, $read);
        unset($read[$key]);
    }

    foreach ($read as $client) {
        $request = socket_read($client, 1024);
        if ($request === false) {
            echo "socket_read() failed: reason: " . socket_strerror(socket_last_error($client)) . "\n";
            continue;
        }
        $input = parseInput($request);

        if (!empty($input[0])) {
            switch ($input[2]) {
                case "exit":
                    disconnectClient($client, $clients);
                    break;
                case "ping":
                    sendResponse($client, "+PONG\r\n");
                    break;
                case "echo":
                    sendResponse($client, formatResponse($input[4]));
                    break;
                case "set":
                    setKeyValue($client, $input, $keyValueRepository);
                    break;
                case "get":
                    getKeyValue($client, $input, $keyValueRepository);
                    break;
                case "info":
                    sendInfo($client, $sock, $keyValueRepository);
            }
        }
    }
}

function createSocket() {
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($sock === false) {
        echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
        exit(1);
    }

    if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
        echo "socket_set_option() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
        exit(1);
    }

    return $sock;
}

function bindAndListen($sock) {
    if (!socket_bind($sock, HOST, PORT)) {
        echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
        exit(1);
    }

    if (!socket_listen($sock, 5)) {
        echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
        exit(1);
    }

    socket_set_nonblock($sock);
}

function parseInput($input) {
    return explode("\r\n", strtolower($input));
}

function disconnectClient($client, &$clients) {
    $key = array_search($client, $clients);
    unset($clients[$key]);
    echo "Client disconnected\n";
}

function sendResponse($client, $response) {
    socket_write($client, $response, strlen($response));
}

function formatResponse($message) {
    return "$" . strlen($message) . "\r\n" . $message . "\r\n";
}

function setKeyValue($client, $input, &$keyValueRepository) {
    if (isset($input[8]) && $input[8] === "px") {
        $keyValueRepository["$client"][$input[4]] = [
            "value" => $input[6],
            "expiry" => microtime(true) * 1000 + (int)$input[10]
        ];
    } else {
        $keyValueRepository["$client"][$input[4]] = $input[6];
    }
    sendResponse($client, "+OK\r\n");
}

function getKeyValue($client, $input, &$keyValueRepository) {
    $value = $keyValueRepository["$client"][$input[4]] ?? null;
    if ($value) {
        if(isset($value["expiry"]) && $value["expiry"] < microtime(true) * 1000) {
            unset($keyValueRepository["$client"][$input[4]]);
            $getResponse = "$-1\r\n";
        } else if(is_array($value)) {
            $getResponse = formatResponse($value["value"]);
        } else if(is_string($value)) {
            $getResponse = formatResponse($value);
        }
    } else {
        $getResponse = "$-1\r\n";
    }
    sendResponse($client, $getResponse);
}

function sendInfo($client, $sock, $keyValueRepository) {
    $role = $keyValueRepository["$sock"]["role"];
    $info = "role:$role\r\n";
    sendResponse($client, formatResponse($info));
}

function closeSocket($sock) {
    socket_close($sock);
    echo "Socket closed\n";
}

socket_close($sock);
