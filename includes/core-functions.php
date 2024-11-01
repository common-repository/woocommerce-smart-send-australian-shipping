<?php

namespace Smart_Send;

use WP_Error;

function flush_cache()
{
  $prefix   = get_prefix();
  $cache_group = 'config';
  wp_cache_delete($prefix, $cache_group);
}
function get_config(): array
{
  $prefix   = get_prefix();
  $cache_group = 'config';
  $config      = wp_cache_get($prefix, $cache_group);
  if (! $config) {
    $dir = untrailingslashit(dirname(plugin_dir_path(__FILE__)));
    $config = require $dir . '/config.php';
    $config['SECRET_KEY'] = get_option($prefix . '_secret_key');
    $config['SECRET_ID'] = get_option($prefix . '_secret_id');
    $debug = filter_var(getenv('SD_WP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
    $file = dirname($dir) . "/config/safe-digit.php";
    if ($debug && file_exists($file)) {
      $file = require $file;
      $_config = $file[$config['CLIENT_ID']] ?? $config;
      $config = array_merge($config, $_config);
    }
    wp_cache_set($prefix, $config, $cache_group);
  }

  $service_config = require WP_PLUGIN_DIR . '/woocommerce-smart-send-australian-shipping/config.php'; // set config params to options
  if (! get_option(get_prefix() . '_admin_install_config')) {
    add_option(get_prefix() . '_admin_install_config', $service_config);
  }
  return $config;
}

/**
 * @return string
 */
function get_prefix(): string
{
  $instance = prototype();

  return $instance->prefix;
}

/**
 * @return string
 */
function get_constant_prefix(): string
{
  return strtoupper(get_prefix());
}

/**
 * A file based logging utility.
 *
 * Made for WordPress, but can be used anywhere with a single change.
 *
 * @param string $type  type of the log
 * @param string $message  log message
 * @param  mixed  $var  any variable to log
 */
function wp_log(string $type = '', string $message = '', $var = null)
{
  if ($var !== null) {
    $message .= ' - ';
    if (is_array($var)) {
      $message .= str_replace(array("\n", '  '), array('', ' '), var_export($var, true));
    } elseif (is_object($var)) {
      $message .= str_replace(array('":', ',', '"'), array(' => ', ', ', ''), json_encode($var, true));
    } elseif (is_bool($var)) {
      $message .= $var ? 'TRUE' : 'FALSE';
    } else {
      $message .= $var;
    }
  }

  $log_message = sprintf("[%s][%s] %s\n", date('d.m.Y h:i:s'), $type, $message);
  error_log($log_message, 3, WP_PLUGIN_DIR . '/woocommerce-smart-send-australian-shipping/smart-send-debug.log');
}

/**
 * @return mixed
 */
function get_tenant_id()
{
  $config = get_config();
  if (isset($config['TENANT_ID'])) {
    return $config['TENANT_ID'];
  }
  $uid = get_option(get_prefix() . '_tenant_uid');
  if (!$uid) {
    $uid = wp_generate_uuid4();
    add_option(get_prefix() . '_tenant_uid', $uid);
  }
  $url   = get_bloginfo('wpurl');
  $parse = parse_url($url);
  return $parse['host'] . '.' . $uid;
}

/**
 * @param string $type
 *
 * @return string
 */
function get_order_webhook_path(string $type = "created"): string
{
  $config = get_config();

  return $config["CLIENT_ID"] . "/order-" . $type . "-webhook-auth";
}

/**
 * @param string $type
 *
 * @return string
 */
function get_webhook_delivery_url(string $type = "created"): string
{
  $path   = get_order_webhook_path($type);
  $config = get_config();
  return $config["API_URL"] . "/public/api/wc/" . $path;
}

function get_package_types(): array
{
  return [
    'Carton'      => [
      'description' => __('Delivered by Carton. Max deadweight 80kg, Max length 400cm', 'smart_send'),
      'weight' => 80,
      'length' => 400,
    ],
    'Satchel/Bag' => [
      'description' => __('Delivered as a Satchel. Max deadweight 17kg, Max length 400cm', 'smart_send'),
      'weight' => 17,
      'length' => 400,
    ],
    'Tube'        => [
      'description' => __('Delivered as a Tube. Max deadweight 17kg, Max length 400cm', 'smart_send'),
      'weight' => 17,
      'length' => 400,
    ],
    'Skid'        => [
      'description' => __('Delivered as a Skid. Max deadweight 1000kg, Max length 400cm', 'smart_send'),
      'weight' => 1000,
      'length' => 400,
    ],
    'Pallet'      => [
      'description' => __('Delivered as a Pallet. Max deadweight 1000kg, Max length 400cm', 'smart_send'),
      'weight' => 1000,
      'length' => 400,
    ],
    'Crate'       => [
      'description' => __('Delivered by Crate.  Max deadweight 1000kg, Max length 400cm', 'smart_send'),
      'weight' => 1000,
      'length' => 400,
    ],
    'Flat Pack'   => [
      'description' => __('Delivered by Flat Pack.  Max deadweight 80kg, Max length 400cm', 'smart_send'),
      'weight' => 80,
      'length' => 400,
    ],
    'Roll'        => [
      'description' => __('Delivered as a Roll.  Max deadweight 80kg, Max length 400cm', 'smart_send'),
      'weight' => 80,
      'length' => 400,
    ],
    'Length'      => [
      'description' => __('Delivered as Length.  Max deadweight 80kg, Max length 400cm', 'smart_send'),
      'weight' => 80,
      'length' => 400,
    ],
    'Envelope'    => [
      'description' => __('Delivered by Envelope. Max deadweight 1kg, Max length 400cm', 'smart_send'),
      'weight' => 80,
      'length' => 400,
    ]
  ];
}

/**
 * @param array $items
 *
 * @return array|WP_Error
 */
function prepare_line_items(array $items)
{
  $errors = [];
  $weight_unit    = get_option('woocommerce_weight_unit');
  $dimension_unit = get_option('woocommerce_dimension_unit');
  $dimensions = [
    'length' => [
      'func'      => 'wc_get_dimension',
      'to_unit'   => 'cm',
      'from_unit' => $dimension_unit
    ],
    'width'  => [
      'func'      => 'wc_get_dimension',
      'to_unit'   => 'cm',
      'from_unit' => $dimension_unit
    ],
    'height' => [
      'func'      => 'wc_get_dimension',
      'to_unit'   => 'cm',
      'from_unit' => $dimension_unit
    ],
    'weight' => [
      'func'      => 'wc_get_weight',
      'to_unit'   => 'kg',
      'from_unit' => $weight_unit
    ],
  ];

  $items = array_filter(array_values($items), function ($item) {
    $product  = wc_get_product($item['product_id']);
    return !$product->get_virtual();
  });

  $items = array_map(function ($item) use ($dimensions, &$errors) {
    if (!empty($item['variation_id'])) {
      $product = wc_get_product($item['variation_id']);
      foreach ($dimensions as $dimension => $args) {
        $func      = 'get_' . $dimension;
        $converter = $args['func'];
        $value     = $product->$func();
        if (!$value) {
          wp_log('INFO', "Product variation {$product->get_title()} has no $dimension set. Attempting to load from parent. Variation ID:", $item['variation_id']);
          $product  = wc_get_product($item['product_id']);
          break;
        }
      }
    } else {
      $product  = wc_get_product($item['product_id']);
    }

    $class_id = $product->get_shipping_class_id();
    $item = [
      'description' => '',
      'quantity' => $item['quantity']
    ];
    if ($class_id) {
      $term = get_term_by('id', $class_id, 'product_shipping_class');

      if ($term && ! is_wp_error($term)) {
        $item['description'] = $term->name;
      }
    }

    foreach ($dimensions as $dimension => $args) {
      $func      = 'get_' . $dimension;
      $converter = $args['func'];
      $value     = $product->$func();
      if (!$value) {
        $errors[] = "Product <b>{$product->get_title()}</b> has no <em>$dimension</em> set.";
      }
      $item[$dimension] = $converter($value, $args['to_unit'], $args['from_unit']);
    }

    return $item;
  }, $items);

  foreach ($items as $item) {
    if ($item['quantity'] < 2) {
      continue;
    }
    foreach (range(1, $item['quantity'] - 1) as $_index) {
      $items[] = $item;
    }
  }

  foreach ($items as &$item) {
    unset($item['quantity']);
  }

  if ($errors) {
    $wp_error = new WP_Error();
    foreach ($errors as $message) {
      $wp_error->add('ERROR', $message);
    }
    return $wp_error;
  }

  return $items;
}

/**
 * @param array $payload
 *
 * @return array
 */
function get_package_payload(array $payload): array
{
  wp_log('INFO', 'get_package_payload', $payload);
  $prefix  = get_prefix();
  $package = [
    'id' => $payload['id'],
    'status'          => $payload['status'],
    'webhook_source'  => get_tenant_id(),
    'view_order_link' => admin_url('post.php?post=' . $payload['id'] . '&action=edit'),
    'destination' => [
      'first_name' => $payload['shipping']['first_name'],
      'last_name'  => $payload['shipping']['last_name'],
      'city'       => $payload['shipping']['city'],
      'state'      => $payload['shipping']['state'],
      'postcode'   => $payload['shipping']['postcode'],
      'address_1'  => $payload['shipping']['address_1'],
      'address_2'  => $payload['shipping']['address_2'],
      'company'    => $payload['shipping']['company'],
      'phone'      => !empty($payload['shipping']['phone']) ? $payload['shipping']['phone'] : $payload['billing']['phone'],
      'email'      => !empty($payload['shipping']['email']) ? $payload['shipping']['email'] : $payload['billing']['email']
    ],
    'shipping_total'   => $payload['shipping_total'] ?? 0,
    'shipping_options' => get_post_meta($payload['id'], $prefix . '_shipping_options', true)
  ];

  if (isset($payload['line_items'])) {
    $contents = prepare_line_items($payload['line_items']);
    $package['contents'] = !is_wp_error($contents) ? $contents : $payload['line_items'];
  }

  return $package;
}

function get_transient_keys_with_prefix($prefix): array
{
  global $wpdb;
  $prefix = $wpdb->esc_like('_transient_' . $prefix);
  $sql    = "SELECT `option_name` FROM $wpdb->options WHERE `option_name` LIKE '%s'";
  $keys   = $wpdb->get_results($wpdb->prepare($sql, $prefix . '%'), ARRAY_A);
  if (is_wp_error($keys)) {
    return [];
  }

  return array_map(function ($key) {
    return str_replace('_transient_', '', $key['option_name']);
  }, $keys);
}

function set_settings($settings)
{
  set_transient(get_prefix() . '_settings', $settings, 30);
}

function get_settings()
{
  $settings = get_transient(get_prefix() . '_settings');
  if (! $settings) {
    $service = new Service();
    $settings = $service->load_settings();
    set_settings($settings);
  }
  return $settings;
}

/**
 * @param string $name
 *
 * @return mixed
 */
function get_constant(string $name)
{
  $prefix = strtoupper(__NAMESPACE__);
  return constant($prefix . '_' . $name);
}
