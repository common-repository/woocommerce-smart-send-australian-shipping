<?php

namespace Smart_Send;

use WP_REST_Response;

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Hook into actions and filters.
 */
class Hooks
{
  /**
   * @return void
   */
  public static function init(): void
  {
    add_action('init', [__CLASS__, 'checkConfig'], 0);
    add_action('woocommerce_save_settings_shipping_' . strtolower(__NAMESPACE__), [__NAMESPACE__ . '\Install', 'instanceCreationUpdate']);
    add_filter('woocommerce_shipping_methods', [__CLASS__, 'register_shipping_methods']);
    add_filter('woocommerce_webhook_payload', [__CLASS__, 'update_webhook_payload']);
    add_filter('woocommerce_webhook_http_args', [__CLASS__, 'update_webhook_http_args'], 99, 3);
    add_filter('woocommerce_rest_prepare_shop_order_object', [__CLASS__, 'update_rest_shop_order_object']);
    add_filter('pre_http_request', [__CLASS__, 'pre_http_request'], 99, 3);
    add_action('admin_menu', [__NAMESPACE__ . '\Admin\Admin', 'add_dashboard_page'], 99);
    add_action('admin_notices', [__NAMESPACE__ . '\Admin\Admin', 'admin_notices']);
    add_action('plugin_action_links_' . plugin_basename(get_constant('PLUGIN_FILE')), [__NAMESPACE__ . '\Admin\Admin', 'settings_links'], 99);
    add_filter('woocommerce_admin_process_product_object', [__NAMESPACE__ . '\Admin\Admin', 'admin_process_product_object'], 99, 3);
    add_filter('woocommerce_cart_totals_after_shipping', [__CLASS__, 'additional_shipping_options'], 99);
    add_filter('woocommerce_review_order_after_shipping', [__CLASS__, 'additional_shipping_options'], 99);
    add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'update_order_meta'], 80, 2);
    add_action('wp_ajax_smart_send_shipping_options', [__CLASS__, 'ajax_save_shipping_options']);
    add_action('wp_ajax_nopriv_smart_send_shipping_options', [__CLASS__, 'ajax_save_shipping_options']);

    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts'], 10);  // Load frontend JS
    add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_enqueue_scripts'), 10, 1);  // Load admin JS & CSS
  }

  /**
   * @return bool
   */
  private static function isNeedShippingOptions(): bool
  {
    return self::isNeedTransportAssurance() || self::isNeedReceiptedDelivery();
  }

  private static function isNeedTransportAssurance(): bool
  {
    $settings = get_settings();
    return (isset($settings['transportAssurance']) && $settings['transportAssurance'] === 'optional'
      && isset($settings['transportAssuranceMin'])
      && $settings['transportAssuranceMin'] <= WC()->cart->get_cart_contents_total());
  }

  private static function isNeedReceiptedDelivery(): bool
  {
    $settings = get_settings();
    return (isset($settings['receiptedDelivery']) && $settings['receiptedDelivery'] === 'optional');
  }

  /**
   * @return void
   */
  public static function additional_shipping_options(): void
  {
    $chosenMethod = WC()->session->chosen_shipping_methods;
    $result = preg_grep('/^' . preg_quote(get_prefix(), '/') . '/', $chosenMethod);

    if (!self::isNeedShippingOptions()) {
      return;
    }

    $settings = get_settings();

    $receiptedDelivery  = WC()->session->get(get_prefix() . '_receiptedDelivery');
    $transportAssurance = WC()->session->get(get_prefix() . '_transportAssurance');

?>
    <tr class="shipping">
      <th><?php esc_html_e('Shipping options', 'smart_send'); ?></th>
      <td>
        <?php
        if (self::isNeedReceiptedDelivery()) {
        ?>
          <label style="display: block">
            <input type="checkbox" class="smart_send_option" name="smart_send_receiptedDelivery" data-option="receiptedDelivery" <?php checked($receiptedDelivery, 'yes', true); ?>>
            <?php _e('I require receipted delivery', 'smart_send'); ?><br>
            <small><?php _e('(A signature will be required upon delivery)', 'smart_send'); ?></small>
          </label>
        <?php
        }

        if (self::isNeedTransportAssurance()) {
        ?>
          <label style="display: block">
            <input type="checkbox" class="smart_send_option" name="smart_send_transportAssurance" data-option="transportAssurance" <?php checked($transportAssurance, 'yes', true); ?>>
            <?php _e('Cover my order for any loss/damage', 'smart_send'); ?>
          </label>
        <?php
        }
        ?>
      </td>
    </tr>
<?php
  }

  /**
   * @param $order_id
   * @param $data
   *
   * @return void
   */
  public static function update_order_meta($order_id, $data): void
  {
    $prefix = get_prefix();

    $order = wc_get_order($order_id);
    $quotes = WC()->session->get($prefix . '_quotes') ?? [];
    $order->update_meta_data('quotes', $quotes);

    if (self::isNeedShippingOptions()) {
      $shipping_options  = WC()->session->get($prefix . '_shipping_options');
      $order->update_meta_data($prefix . '_shipping_options', $shipping_options);
    }

    $order->save();
  }

  /**
   * @return void
   */
  public static function ajax_save_shipping_options(): void
  {
    $prefix = get_prefix();
    check_ajax_referer($prefix . '_save_shipping_options', 'security');

    if (!self::isNeedShippingOptions()) {
      wp_die();
    }

    $option = $_POST['option'] ?? '';
    $value  = $_POST['value'] ?? '';

    if (
      !in_array($value, array('yes', 'no')) ||
      !in_array($option, array('receiptedDelivery', 'transportAssurance'))
    ) {
      wp_die();
    }

    WC()->session->set($prefix . '_' . $option, $value);
    WC()->session->set('_updated_shipping_options', true);

    foreach (WC()->cart->get_shipping_packages() as $package_key => $package) {
      WC()->session->set('shipping_for_package_' . $package_key, false);
    }

    WC()->cart->calculate_totals();

    wp_die();
  }

  /**
   * @return void
   */
  public static function enqueue_scripts(): void
  {
    $prefix     = get_prefix();
    $assets_url = esc_url(trailingslashit(plugins_url('/assets/js/', get_constant('PLUGIN_FILE'))));
    wp_register_script($prefix . '-frontend', $assets_url .  'frontend.js', array('jquery'), get_constant('VERSION'));
    wp_enqueue_script($prefix . '-frontend');
    $args_array = array(
      'security' => wp_create_nonce($prefix . '_save_shipping_options'),
      'prefix'   => $prefix,
      'ajax_url' => admin_url('admin-ajax.php')
    );
    wp_localize_script($prefix . '-frontend', $prefix . '_options', $args_array);
  }

  /**
   * @return void
   */
  public static function admin_enqueue_scripts(): void
  {
    $prefix     = get_prefix();
    $assetsUrl = esc_url(trailingslashit(plugins_url('/assets/', get_constant('PLUGIN_FILE'))));

    wp_register_script($prefix . '-admin', $assetsUrl .  'js/admin.js', false, get_constant('VERSION'));
    wp_register_style($prefix . '-admin', $assetsUrl .  'css/admin.css', false, get_constant('VERSION'));
  }

  /**
   * @return void
   */
  public static function checkConfig()
  {
    $config        = get_config();
    $configUrlsExists = get_option(get_prefix() . '_config_urls_exists');
    if ($configUrlsExists === 'yes')
      return;

    if (
      empty($config['API_URL']) ||
      empty($config['DASHBOARD_URL']) ||
      empty($config['BFF_URL'])
    ) {
      $configUrlsExists = 'no';
    } else {
      $configUrlsExists = 'yes';
    }
    update_option(get_prefix() . '_config_urls_exists', $configUrlsExists);
  }


  /**
   * @param string $delivery_url
   *
   * @return bool
   */
  public static function check_webhook_delivery_url(string $deliveryUrl): bool
  {
    $createdWebhookUrl = get_webhook_delivery_url();
    $updatedWebhookUrl = get_webhook_delivery_url('updated');
    return in_array($deliveryUrl, [$createdWebhookUrl, $updatedWebhookUrl]);
  }

  /**
   * @param string|array $body
   *
   * @return bool
   */
  public static function check_shipping_method($body): bool
  {
    if (is_array($body)) {
      $body = json_encode($body);
    }
    $body     = json_decode($body);
    $shipping = $body->shipping_lines[0] ?? [];
    $method   = $shipping->method_id ?? '';
    return $method === get_prefix() || $method === 'flat_rate';
  }

  /**
   * @param  array  $payload
   *
   * @return array
   */
  public static function update_webhook_payload(array $payload): array
  {
    if (!self::check_shipping_method($payload)) {
      return $payload;
    }

    $payload['package'] = get_package_payload($payload);

    return $payload;
  }

  /**
   * @param array $http_args request args
   * @param $arg
   * @param $webhook_id
   *
   * @return array
   */
  public static function update_webhook_http_args(array $http_args, $arg, $webhook_id): array
  {
    try {
      $webhook = new \WC_Webhook($webhook_id);

      if (self::check_webhook_delivery_url($webhook->get_delivery_url())) {
        $service = new Service();
        $http_args['headers'][$service::HEADER_AUTHORIZATION] = $service->get_access_token();
        $http_args['headers']['instanceId'] = $service->get_tenant_id();
      }
    } catch (\Throwable $e) {
      wp_log("ERROR", 'update_webhook_http_args', $e->getMessage());
    }

    return $http_args;
  }

  /**
   * @param WP_REST_Response $response
   *
   * @return WP_REST_Response
   */
  public static function update_rest_shop_order_object(WP_REST_Response $response): WP_REST_Response
  {
    wp_log("INFO", 'update_rest_shop_order_object');
    $data = $response->data;
    $data['package'] = get_package_payload($data);
    $response->set_data($data);
    return $response;
  }

  /**
   * @param  array  $methods
   *
   * @return array
   */
  public static function register_shipping_methods(array $methods): array
  {
    $methods[get_prefix()] = __NAMESPACE__ . '\Shipping_Method';
    return $methods;
  }

  /**
   * @param $preempt
   * @param $parsed_args
   * @param $url
   *
   * @return bool|mixed
   */
  public static function pre_http_request($preempt, $parsed_args, $url)
  {
    if (!self::check_webhook_delivery_url($url)) {
      return $preempt;
    }

    return !self::check_shipping_method($parsed_args['body']);
  }
}
