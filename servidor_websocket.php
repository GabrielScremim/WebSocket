<?php
// servidor_websocket_silencioso.php
// Versão que só alerta quando servidor cai, silencioso quando tudo está ok

error_reporting(E_ALL);
set_time_limit(0);

class SilentServerMonitor {
    private $clients = [];
    private $servers_to_monitor = [
        'DietSync' => 'http://152.67.45.167',
        // Adicione seus servidores aqui
    ];
    private $server_status = [];
    private $socket;
    private $last_check = 0;

    public function __construct($host = '0.0.0.0', $port = 8080) {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, $host, $port);
        socket_listen($this->socket, 5);
        
        // Inicializar status dos servidores
        foreach ($this->servers_to_monitor as $name => $url) {
            $this->server_status[$name] = 'unknown';
        }
        
        echo "🚀 Monitor silencioso iniciado em $host:$port\n";
        echo "🔍 Monitorando " . count($this->servers_to_monitor) . " servidores...\n";
        echo "⚠️  Só serão exibidos alertas quando servidores caírem\n";
        echo "📡 Dashboard: abra monitor.html no navegador\n\n";
        
        // Primeira verificação para estabelecer baseline
        $this->checkAllServersQuiet();
    }

    public function run() {
        while (true) {
            $read = array_merge([$this->socket], $this->clients);
            $write = null;
            $except = null;
            
            if (socket_select($read, $write, $except, 0, 100000) > 0) {
                // Nova conexão
                if (in_array($this->socket, $read)) {
                    $this->handleNewConnection();
                    $key = array_search($this->socket, $read);
                    unset($read[$key]);
                }
                
                // Mensagens dos clientes
                foreach ($read as $client) {
                    $this->handleClientMessage($client);
                }
            }
            
            // Verificar servidores a cada 30 segundos
            if (time() - $this->last_check >= 30) {
                $this->checkAllServers();
                $this->last_check = time();
            }
            
            usleep(100000); // 100ms
        }
    }

    private function handleNewConnection() {
        $client = socket_accept($this->socket);
        
        if ($client === false) {
            return;
        }
        
        // Handshake WebSocket
        $request = socket_read($client, 2048);
        
        if (preg_match('/Sec-WebSocket-Key: (.*)\\r\\n/', $request, $matches)) {
            $key = trim($matches[1]);
            $acceptKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            
            $response = "HTTP/1.1 101 Switching Protocols\r\n";
            $response .= "Upgrade: websocket\r\n";
            $response .= "Connection: Upgrade\r\n";
            $response .= "Sec-WebSocket-Accept: $acceptKey\r\n\r\n";
            
            socket_write($client, $response);
            
            $this->clients[] = $client;
            echo "[" . date('H:i:s') . "] 📱 Cliente conectado (" . count($this->clients) . " ativo(s))\n";
            
            // Enviar status inicial
            $this->sendToClient($client, [
                'type' => 'initial_status',
                'servers' => $this->server_status
            ]);
        }
    }

    private function handleClientMessage($client) {
        $data = socket_read($client, 2048);
        
        if ($data === false || empty($data)) {
            $this->removeClient($client);
            return;
        }
        
        $message = $this->decodeFrame($data);
        
        if ($message === false) {
            return;
        }
        
        $decoded = json_decode($message, true);
        
        if (isset($decoded['action']) && $decoded['action'] === 'force_check') {
            echo "[" . date('H:i:s') . "] 🔄 Verificação manual solicitada\n";
            $this->checkAllServersForced();
        }
    }

    private function removeClient($client) {
        $key = array_search($client, $this->clients);
        if ($key !== false) {
            unset($this->clients[$key]);
            socket_close($client);
            echo "[" . date('H:i:s') . "] 📱 Cliente desconectado (" . count($this->clients) . " ativo(s))\n";
        }
    }

    // Verificação silenciosa inicial para estabelecer baseline
    private function checkAllServersQuiet() {
        foreach ($this->servers_to_monitor as $name => $url) {
            $this->checkServerQuiet($name, $url);
        }
    }

    // Verificação normal (só alerta problemas)
    private function checkAllServers() {
        foreach ($this->servers_to_monitor as $name => $url) {
            $this->checkServer($name, $url);
        }
    }

    // Verificação forçada (mostra tudo)
    private function checkAllServersForced() {
        foreach ($this->servers_to_monitor as $name => $url) {
            $this->checkServerForced($name, $url);
        }
    }

    // Verificação silenciosa (sem logs)
    private function checkServerQuiet($name, $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Silent Server Monitor',
            CURLOPT_NOBODY => true
        ]);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $current_status = ($result !== false && $http_code >= 200 && $http_code < 400) ? 'up' : 'down';
        $this->server_status[$name] = $current_status;
    }

    // Verificação normal - SÓ MOSTRA QUANDO CAI
    private function checkServer($name, $url) {
        $previous_status = $this->server_status[$name] ?? 'unknown';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Silent Server Monitor',
            CURLOPT_NOBODY => true
        ]);
        
        $start_time = microtime(true);
        $result = curl_exec($ch);
        $response_time = round((microtime(true) - $start_time) * 1000, 2);
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $current_status = ($result !== false && $http_code >= 200 && $http_code < 400) ? 'up' : 'down';
        
        // Atualizar status
        $this->server_status[$name] = $current_status;
        
        $server_data = [
            'name' => $name,
            'url' => $url,
            'status' => $current_status,
            'response_time' => $current_status === 'up' ? $response_time : null,
            'http_code' => $http_code,
            'error' => $curl_error ?: null,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_changed' => $previous_status !== $current_status
        ];
        
        // Enviar sempre para o dashboard (silencioso)
        $this->broadcast([
            'type' => 'server_update',
            'server' => $server_data
        ]);
        
        // LOGS E ALERTAS SÓ QUANDO HOUVER PROBLEMAS:
        
        // 1. Servidor caiu (UP -> DOWN) - ALERTA CRÍTICO
        if ($current_status === 'down' && $previous_status === 'up') {
            echo "[" . date('H:i:s') . "] 🚨 CRÍTICO: {$name} CAIU! ({$url})\n";
            echo "                    HTTP: {$http_code} | Erro: " . ($curl_error ?: 'Timeout/Conexão') . "\n";
            
            $this->broadcast([
                'type' => 'server_alert',
                'message' => "🚨 CRÍTICO: Servidor '{$name}' CAIU!",
                'server' => $server_data
            ]);
        }
        
        // 2. Servidor voltou (DOWN -> UP) - RECUPERAÇÃO
        elseif ($current_status === 'up' && $previous_status === 'down') {
            echo "[" . date('H:i:s') . "] ✅ RECUPERADO: {$name} voltou ao ar ({$response_time}ms)\n";
            
            $this->broadcast([
                'type' => 'server_recovery',
                'message' => "✅ RECUPERADO: Servidor '{$name}' voltou!",
                'server' => $server_data
            ]);
        }
        
        // 3. Continua down - log silencioso a cada 10 verificações (5 minutos)
        elseif ($current_status === 'down' && $previous_status === 'down') {
            static $down_counters = [];
            $down_counters[$name] = ($down_counters[$name] ?? 0) + 1;
            
            if ($down_counters[$name] % 10 === 0) { // A cada 10 verificações (5 min)
                $minutes = ($down_counters[$name] * 0.5); // 30s * count / 60
                echo "[" . date('H:i:s') . "] ⚠️  {$name} continua fora do ar há {$minutes} min\n";
            }
        }
        
        // 4. Tudo OK (UP -> UP) - SILENCIOSO TOTAL
        // Nada é exibido no console quando está tudo funcionando
    }

    // Verificação forçada - mostra status de todos
    private function checkServerForced($name, $url) {
        $previous_status = $this->server_status[$name] ?? 'unknown';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Silent Server Monitor (Manual)',
            CURLOPT_NOBODY => true
        ]);
        
        $start_time = microtime(true);
        $result = curl_exec($ch);
        $response_time = round((microtime(true) - $start_time) * 1000, 2);
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $current_status = ($result !== false && $http_code >= 200 && $http_code < 400) ? 'up' : 'down';
        $this->server_status[$name] = $current_status;
        
        $server_data = [
            'name' => $name,
            'url' => $url,
            'status' => $current_status,
            'response_time' => $current_status === 'up' ? $response_time : null,
            'http_code' => $http_code,
            'error' => $curl_error ?: null,
            'timestamp' => date('Y-m-d H:i:s'),
            'status_changed' => $previous_status !== $current_status
        ];
        
        // Log forçado - mostra status atual
        $status_symbol = $current_status === 'up' ? '✅' : '❌';
        echo "[" . date('H:i:s') . "] {$status_symbol} {$name}: {$current_status}";
        if ($current_status === 'up') {
            echo " ({$response_time}ms)";
        } else {
            echo " (HTTP: {$http_code})";
        }
        echo "\n";
        
        $this->broadcast([
            'type' => 'server_update',
            'server' => $server_data
        ]);
    }

    private function broadcast($data) {
        $message = json_encode($data);
        foreach ($this->clients as $client) {
            $this->sendToClient($client, $data);
        }
    }

    private function sendToClient($client, $data) {
        if (!is_resource($client)) {
            return false;
        }
        
        $message = json_encode($data);
        $frame = $this->encodeFrame($message);
        
        $result = @socket_write($client, $frame, strlen($frame));
        
        if ($result === false) {
            $this->removeClient($client);
            return false;
        }
        
        return true;
    }

    private function encodeFrame($message) {
        $length = strlen($message);
        $frame = chr(129);
        
        if ($length <= 125) {
            $frame .= chr($length);
        } elseif ($length <= 65535) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('N', 0) . pack('N', $length);
        }
        
        return $frame . $message;
    }

    private function decodeFrame($data) {
        if (strlen($data) < 2) {
            return false;
        }
        
        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);
        
        $masked = ($byte2 & 128) === 128;
        $payload_length = $byte2 & 127;
        
        $offset = 2;
        
        if ($payload_length === 126) {
            if (strlen($data) < $offset + 2) return false;
            $payload_length = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
        } elseif ($payload_length === 127) {
            if (strlen($data) < $offset + 8) return false;
            $payload_length = unpack('N2', substr($data, $offset, 8));
            $payload_length = $payload_length[2];
            $offset += 8;
        }
        
        if ($masked) {
            if (strlen($data) < $offset + 4) return false;
            $mask = substr($data, $offset, 4);
            $offset += 4;
        }
        
        if (strlen($data) < $offset + $payload_length) {
            return false;
        }
        
        $payload = substr($data, $offset, $payload_length);
        
        if ($masked) {
            for ($i = 0; $i < $payload_length; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
        }
        
        return $payload;
    }

    public function __destruct() {
        foreach ($this->clients as $client) {
            socket_close($client);
        }
        socket_close($this->socket);
    }
}

// Iniciar servidor silencioso
try {
    $server = new SilentServerMonitor('0.0.0.0', 8080);
    $server->run();
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}