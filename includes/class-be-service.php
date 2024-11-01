<?php

namespace Smart_Send;

if (! defined("ABSPATH")) {
    exit;
}

class BE_Service
{
    const HEADER_AUTHORIZATION = "Authorization";

    /**
     * @var string
     */
    protected $secret_id;

    /**
     * @var string
     */
    protected $secret_key;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string|bool
     */
    protected $access_token;

    /**
     * @var string
     */
    protected $dashboard_url;

    /**
     * @var string
     */
    protected $api_url;

    /**
     * @var string
     */
    protected $bff_url;

    /**
     * @var string
     */
    protected $client_id;
    /**
     * @var string
     */
    protected $prefix;

    public function __construct()
    {
        $this->prefix        = get_prefix();
        $this->config        = get_config();
        $this->dashboard_url = $this->config["DASHBOARD_URL"];
        $this->api_url       = $this->config["API_URL"];
        $this->bff_url       = $this->config["BFF_URL"];
        $this->client_id     = $this->config["CLIENT_ID"];
        $this->secret_id     = $this->config["SECRET_ID"];
        $this->secret_key    = $this->config["SECRET_KEY"];
        $this->get_access_token();
    }

    /**
     * @return bool|string
     */
    public function get_access_token()
    {

        try {
            if (!$this->secret_id || !$this->secret_key) {
                throw new \Exception("No SECRET_ID or SECRET_KEY provided.");
            }
            $access_token = get_transient($this->prefix . "_access_token");
            $expired      = true;

            if ($access_token) {
                try {
                    $payload  = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $access_token)[1]))));
                    $exp_date = $payload->exp - 5 * 60;
                    $expired  = $exp_date <= time();
                } catch (\Throwable $e) {
                    wp_log("ERROR", 'The access token exists, but an error occurred during decoding: ' . $e->getMessage());
                }
            }

            if (! $access_token || $expired) {
                $path     = $this->api_url . "/public/integrations/keys/access";
                $response = $this->post_api($path, [
                    "id"     => $this->secret_id,
                    "secret" => $this->secret_key
                ], [
                    self::HEADER_AUTHORIZATION => false
                ], true);

                if (! $response) {
                    throw new \Exception("Something went wrong while retrieving access token from the BE");
                }
                $access_token = $response["token"];
                set_transient($this->prefix . "_access_token", $access_token, HOUR_IN_SECONDS);
            }
            $this->access_token = $access_token;
        } catch (\Throwable $e) {
            wp_log("ERROR", $e->getMessage());
            $this->access_token = false;
        }

        return $this->access_token;
    }


    /**
     * @param string $url
     * @param array|null $body
     * @param array $headers
     * @param bool $is_retry
     *
     * @return false|string
     */
    protected function post_api(string $url, ?array $body = array(), array $headers = array(), bool $is_retry = false)
    {
        return $this->request($url, "POST", $body, $headers, $is_retry);
    }

    /**
     * @param string $url
     * @param array|null $body
     * @param array $headers
     *
     * @return false|string
     */
    protected function put_api(string $url, ?array $body = array(), array $headers = array())
    {
        return $this->request($url, "PUT", $body, $headers);
    }

    /**
     * @param string $url
     * @param array|null $body
     * @param array $headers
     *
     * @return false|string
     */
    protected function get_api(string $url, ?array $body = array(), array $headers = array())
    {
        return $this->request($url, "GET", $body, $headers);
    }

    /**
     * @param array $headers
     *
     * @return array
     */
    private function get_headers(array $headers = array()): array
    {
        $headers = array_merge([
            self::HEADER_AUTHORIZATION => $this->access_token,
            'instanceId' => $this->get_tenant_id(),
            'Content-Type' => 'application/json'
        ], $headers);

        if ($headers[ self::HEADER_AUTHORIZATION ] === false) {
            unset($headers[ self::HEADER_AUTHORIZATION ]);
        }

        return $headers;
    }

    /**
     * @param string $url
     * @param string $method
     * @param array|null $body
     * @param array $headers
     * @param bool $is_retry
     *
     * @return false|string
     */
    protected function request(
        string $url,
        string $method = "GET",
        ?array $body = null,
        array $headers = [],
        bool $is_retry = false
    ) {
        wp_log("INFO", "[{$method}] API URL:", $url);

        $data = array(
            "method"  => $method,
            "headers" => $this->get_headers($headers),
            "timeout" => 45
        );

        if ($body) {
            if ($method === 'GET') {
                $url = add_query_arg($body, $url);
            } else {
                $data["body"] = json_encode($body);
            }
        }
        wp_log("INFO", "[$method] wp_remote_post ", $data);
        $response = wp_remote_post($url, $data);

        if (is_wp_error($response)) {
            $errorResponse = $response->get_error_message();
            wp_log("ERROR", $method, $errorResponse);

            return false;
        }

        $responseData = wp_remote_retrieve_body($response);
        $status_code  = wp_remote_retrieve_response_code($response);
        if ($status_code === 401 && ! $is_retry) {
            wp_log("INFO", "BEService: Retry with new token");
            $this->get_access_token();

            return $this->request($url, $method, $body, $headers, true);
        } elseif ($status_code != 200) {
            wp_log("ERROR", "[$method] Something went wrong while calling the BE");
            wp_log("ERROR", "[$method] Status code: " . $status_code, $responseData);

            return false;
        }

        if (
            isset($data["headers"]["Content-Type"]) &&
            strpos($data["headers"]["Content-Type"], "application/json") === 0
        ) {
            $responseData = json_decode($responseData, true);
        }

        wp_log("INFO", "[$method] API success. Response: ", $responseData);

        return $responseData;
    }

    /**
     *
     * @return string
     */
    public function get_dashboard_url(): string
    {
        return $this->dashboard_url . "/{$this->client_id}/dashboard?instance=" . $this->get_access_token();
    }

    /**
     * @return mixed
     */
    public function get_tenant_id()
    {
        return get_tenant_id();
    }
}
