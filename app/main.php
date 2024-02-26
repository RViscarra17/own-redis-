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

while(true) {
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
            $key = array_search($client, $clients);
            unset($clients[$key]);
            echo "Client disconnected\n";
            continue;
        }

        echo "Request: " . $request . "\n";
        if ($request === "*1\r\n$4\r\nping\r\n") {
            $pongResponse = "+PONG\r\n";
            socket_write($client, $pongResponse, strlen($pongResponse));
        }
    }
}

socket_close($sock);
?>
