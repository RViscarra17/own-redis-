<?php

error_reporting(E_ALL);

// You can use print statements as follows for debugging, they'll be visible when running tests.
echo "Logs from your program will appear here";

set_time_limit(0);
const HOST = 'localhost';
const PORT = 6379;
ob_implicit_flush();
$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($sock === false) {
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
    exit(1);
}

if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
    echo "socket_set_option() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    exit(1);
}

if (!socket_bind($sock, HOST, PORT)) {
    echo "socket_bind() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    exit(1);
}

if (!socket_listen($sock, 5)) {
    echo "socket_listen() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
    exit(1);
}

socket_set_nonblock($sock);


echo "Server started\n";
echo "Linstening on " . HOST . ":" . PORT . "\n";

$clients = [];
$keyValueRepository = [];

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
        $input = parseInput($request);

        if (!empty($input[0])) {
            if ($input[2] === "exit") {
                $key = array_search($client, $clients);
                unset($clients[$key]);
                echo "Client disconnected\n";
                continue;
            }

            if ($input[2] === "ping") {
                $pongResponse = "+PONG\r\n";
                socket_write($client, $pongResponse, strlen($pongResponse));
                echo "Pong sent\n";
            }

            if ($input[2] === "echo") {
                $echoResponse = "$" . strlen($input[4]) . "\r\n" . $input[4] . "\r\n";
                socket_write($client, $echoResponse, strlen($echoResponse));
                echo "Echo sent\n";
            }

            if ($input[2] === "set") {
                if (isset($input[8]) && $input[8] === "px") {
                    $keyValueRepository["$client"][$input[4]] = [
                        "value" => $input[6],
                        "expiry" => microtime(true) * 1000 + (int)$input[10]
                    ];
                } else {
                    $keyValueRepository["$client"][$input[4]] = $input[6];
                }
                $setResponse = "+OK\r\n";
                socket_write($client, $setResponse, strlen($setResponse));
                echo "Set response sent\n";
            }

            if ($input[2] === "get") {
                $value = $keyValueRepository["$client"][$input[4]] ?? null;
                if ($value) {
                    if(isset($value["expiry"]) && $value["expiry"] < microtime(true) * 1000) {
                        unset($keyValueRepository["$client"][$input[4]]);
                        $getResponse = "$-1\r\n";
                    } else if(is_array($value)) {
                        $getResponse = "$" . strlen($value["value"]) . "\r\n" . $value["value"] . "\r\n";
                    } else if(is_string($value)) {
                        $getResponse = "$" . strlen($value) . "\r\n" . $value . "\r\n";
                    }
                } else {
                    $getResponse = "$-1\r\n";
                }
                socket_write($client, $getResponse, strlen($getResponse));
                echo "Get response sent\n";
            }
        }
    }
}

function parseInput($input)
{
    return explode("\r\n", strtolower($input));
}


socket_close($sock);
