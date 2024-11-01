<?php

namespace Smart_Send;

if (! defined('ABSPATH')) {
    exit;
}

class Service extends BE_Service
{
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * @param  array  $credentials
     *
     * @return false|string
     */
    public function validate_credentials(array $credentials)
    {
        wp_log('INFO', 'validate_credentials', $credentials);
        if (empty($credentials['username']) && empty($credentials['password'])) {
            return false;
        }
        $url = $this->api_url . '/public/api/wc/' . $this->client_id . '/validate-credentials';
        $url = add_query_arg('is-prod', json_encode($credentials['isProd']), $url);

        return $this->post_api($url, $credentials, ['instanceId' => $this->client_id]);
    }

    public function get_quotes(array $package)
    {
        if (empty($package)) {
            return false;
        }
        $key = $this->prefix . '_quotes_' . md5(serialize($package));
        $quotes = get_transient($key) ;

        if (! $quotes) {
            $url = $this->api_url . '/public/api/wc/' . $this->client_id . '/get-quotes-for-package';
            $quotes = $this->post_api($url, $package);
            if ($quotes['success']) {
                set_transient($key, $quotes, MINUTE_IN_SECONDS * 5);
            }
        }

        return $quotes;
    }

    public function load_settings()
    {
        $url = $this->api_url . '/public/api/wc/' . $this->client_id . '/settings';
        return $this->get_api($url);
    }

    public function uninstall()
    {
        $url = $this->api_url . '/public/api/wc/' . $this->client_id . '/uninstall-webhook';
        return $this->post_api($url);
    }

    /**
     * @param array $data
     *
     * @return false|string
     */
    public function install(array $data)
    {
        $url = $this->bff_url . '/wc/' . $this->client_id . '/install';
        return $this->post_api($url, $data);
    }

    /**
     * @param array $data
     *
     * @return false|string
     */
    public function update_installation(array $data)
    {
        $url = $this->bff_url . '/wc/' . $this->client_id . '/update-installation';
        return $this->put_api($url, $data);
    }

    /**
     * @param $url
     * @param $body
     *
     * @return void
     */
    public function update_configuration($url, $body)
    {
        $this->put_api($url, $body);
    }
}
