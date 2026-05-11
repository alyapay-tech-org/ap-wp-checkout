<?php
defined('ABSPATH') || exit;

class AlyaPay_API_Exception extends \RuntimeException {
    /** @var int */
    private $status_code;
    /** @var string */
    private $api_code;

    public function __construct(string $message, int $status_code = 0, string $api_code = '') {
        parent::__construct($message);
        $this->status_code = $status_code;
        $this->api_code    = $api_code;
    }

    public function get_status_code(): int  { return $this->status_code; }
    public function get_api_code(): string  { return $this->api_code; }
}

class AlyaPay_API {
    /** @var string */
    private $api_key;
    /** @var string */
    private $base_url;

    public function __construct(string $api_key, string $base_url) {
        $this->api_key  = $api_key;
        $this->base_url = rtrim($base_url, '/');
    }

    /**
     * @return array{payment_intent_id: string, checkout_token: string, checkout_url: string, expires_in: int}
     */
    public function create_session_intent(array $payload): array {
        return $this->post('/api/v1/public/session-intents', $payload);
    }

    /**
     * @return array{status: string, transaction_id: string}
     */
    public function get_transaction_status(string $transaction_id): array {
        return $this->get("/api/v1/public/transactions/{$transaction_id}/status");
    }

    /**
     * @return array{transactionId: string, installments: array}
     */
    public function get_schedules(string $transaction_id): array {
        return $this->get("/api/v1/public/transactions/{$transaction_id}/schedules");
    }

    public function get_partner_config(): array {
        return $this->get('/api/v1/public/partner/config');
    }

    public function update_partner_config(array $payload): array {
        return $this->put('/api/v1/public/partner/config', $payload);
    }

    // -------------------------------------------------------------------------

    private function get(string $path): array {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $body): array {
        return $this->request('POST', $path, $body);
    }

    private function put(string $path, array $body): array {
        return $this->request('PUT', $path, $body);
    }

    private function request(string $method, string $path, array $body = []): array {
        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Api-Key'    => $this->api_key,
            ],
            'timeout' => 30,
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->base_url . $path, $args);

        if (is_wp_error($response)) {
            throw new AlyaPay_API_Exception($response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($status_code >= 400) {
            $message  = $data['message'] ?? $data['error'] ?? "API error {$status_code}";
            $api_code = $data['code'] ?? '';
            // Append raw body to message so callers can log full API response
            $full_message = sprintf('[%d] %s | body: %s', $status_code, $message, $raw_body);
            throw new AlyaPay_API_Exception($full_message, $status_code, (string) $api_code);
        }

        return is_array($data) ? $data : [];
    }
}
