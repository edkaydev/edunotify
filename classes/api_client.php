<?php
namespace local_edunotify;

defined('MOODLE_INTERNAL') || die();

class api_client {
    public static function call(string $endpoint, string $method = 'GET', ?array $data = null): ?array {
        try {
            $port = get_config('local_edunotify', 'botport') ?: 5001;
            $apikey = get_config('local_edunotify', 'apikey') ?: '';
            $url = 'http://127.0.0.1:' . $port . $endpoint;

            $ch = curl_init($url);
            if ($ch === false) return null;

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_FAILONERROR => false,
            ]);

            $headers = [];
            if (!empty($apikey)) {
                $headers[] = 'X-API-Key: ' . $apikey;
            }

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    $encoded = json_encode($data);
                    if ($encoded === false) {
                        curl_close($ch);
                        return null;
                    }
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
                    $headers[] = 'Content-Type: application/json';
                }
            }

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false || $response === '') return null;
            if ($httpcode < 200 || $httpcode >= 300) return null;

            $decoded = json_decode($response, true);
            return is_array($decoded) ? $decoded : null;

        } catch (\Throwable $e) {
            return null;
        }
    }

    public function get(string $endpoint): ?array {
        return self::call($endpoint, 'GET');
    }

    public function post(string $endpoint, ?array $data = null): ?array {
        return self::call($endpoint, 'POST', $data);
    }
}