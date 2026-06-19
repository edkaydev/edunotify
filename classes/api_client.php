<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

class api_client {
    public static function call(string $endpoint, string $method = 'GET', ?array $data = null): ?array {
        $port = get_config('local_edunotify', 'botport') ?: 5001;
        $apikey = get_config('local_edunotify', 'apikey') ?: '';
        $url = 'http://127.0.0.1:' . $port . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $headers = [];
        if (!empty($apikey)) {
            $headers[] = 'X-API-Key: ' . $apikey;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode >= 200 && $httpcode < 300 && $response) {
            return json_decode($response, true);
        }

        return null;
    }

    public function get(string $endpoint): ?array {
        return self::call($endpoint, 'GET');
    }

    public function post(string $endpoint, ?array $data = null): ?array {
        return self::call($endpoint, 'POST', $data);
    }
}
