<?php

/**
 * Uninstall
 *
 * Uninstalling Plugin deletes user roles, pages, tables, and options.
 *
 * @version     4.1.1
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

$prefix =  'smart_send';

$config = get_option($prefix . '_admin_install_config');
if (is_array($config)) {
  $api_url = $config['API_URL'];
}

// Remote instance deletion.

if (! empty($api_url) && ! empty($prefix)) {
  $api_url = $api_url . '/public/api/wc/' . $prefix . '/uninstall-webhook';
  $url = get_bloginfo('wpurl');
  $parse = parse_url($url);

  $headers = [
    'instanceid' => $parse['host'] . '.' . get_option($prefix . '_tenant_uid'),
    'Content-Type' => 'application/json',
  ];
  $args = [
    'timeout' => 450,
    'redirection' => 5,
    'httpversion' => '1.1',
    'blocking' => true,
    'headers' => $headers,
    'body' => [],
    'cookies' => [],
  ];

  wp_remote_post($api_url, $args);
}

// Cache config deletion.
$cache_group = 'config';
$cache_config = wp_cache_get($prefix, $cache_group);

if (false === empty($cache_config)) {
  wp_cache_delete($prefix, $cache_group);
}

// Remove WC webhooks
foreach ($config["WEBHOOK_NAME"] as $name) {
  $data_store = \WC_Data_Store::load("webhook");
  $webhooks   = $data_store->search_webhooks(["search" => $name]);

  if ($webhooks) {
    $webhook = \wc_get_webhook($webhooks[0]);
    $webhook->delete(true);
  }
}

// Plugin Options deletion.
delete_option($prefix . '_admin_install_timestamp');
delete_option($prefix . '_admin_install_config');
delete_option($prefix . '_config_urls_exists');
delete_option($prefix . '_tenant_uid');
delete_option($prefix . '_version');
delete_option($prefix . '_credentials_validated');
delete_option($prefix . '_secret_key');
delete_option($prefix . '_secret_id');
delete_option($prefix . '_australian_timezones');
delete_option($prefix . '_installation_created');
delete_option($prefix . '_installation_updated');
delete_option($prefix . '_vip_username');
delete_option($prefix . '_vip_password');
delete_option($prefix . '_test_mode');
delete_option('woocommerce_' . $prefix . '_settings');
delete_option('_transient_timeout_' . $prefix . '_access_token');
delete_option('_transient_' . $prefix . '_access_token');

// Plugin expired transient options deletion.
delete_expired_transients(true);

// Delete registered WC API key.
global $wpdb;
$apiKeys = $wpdb->get_results("SELECT * FROM  {$wpdb->prefix}woocommerce_api_keys  WHERE description = 'SmartSend Orders'");
if (! empty($apiKeys)) {
  $wpdb->delete($wpdb->prefix . "woocommerce_api_keys", ['description' => "SmartSend Orders"]);
}
