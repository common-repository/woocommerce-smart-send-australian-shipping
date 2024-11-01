<?php

namespace Smart_Send;

use WC_Tax;

if (!defined('ABSPATH')) {
  exit;
}

class Shipping_Method extends \WC_Shipping_Method
{
  private array $_errors = [];

  /**
   * @var string
   */
  private string $vip_password;

  /**
   * @var string
   */
  private string $vip_username;

  /**
   * @var string
   */
  private string $test_mode;

  /**
   * @var bool
   */
  private bool $validated;

  /**
   * Constructor. The instance ID is passed to this.
   *
   * @param  int  $instance_id
   */
  public function __construct($instance_id = 0)
  {
    $this->id                 = get_prefix();
    $this->instance_id        = absint($instance_id);
    $this->method_title       = __('SmartSend', 'smart_send');
    $this->method_description = __('SmartSend shipping method.', 'smart_send');

    $this->init();

    add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
  }

  /**
   * @return void
   */
  private function init(): void
  {
    $this->form_fields  = array(
      'title'        => array(
        'title'       => __('Method Title', 'smart_send'),
        'type'        => 'text',
        'description' => __('This is the shipping method shown on the Shipping Zones menu.', 'smart_send'),
        'default'     => __('SmartSend', 'smart_send'),
        'desc_tip'    => true
      ),
      'test_mode' => array(
        'title'    => __('Test mode', 'smart_send'),
        'label'    => __('Enable test mode', 'smart_send'),
        'type'     => 'checkbox',
        'desc_tip' => true,
        'default'  => get_option($this->id . '_test_mode'),
        'description' => __('Force plugin to use the test API server.', 'smart_send'),
      ),
      'vip_username' => array(
        'title'    => __('VIP username', 'smart_send'),
        'type'     => 'text',
        'desc_tip' => true,
        'default'  => get_option($this->id . '_vip_username'),
      ),
      'vip_password' => array(
        'title'    => __('VIP password', 'smart_send'),
        'type'     => 'password',
        'desc_tip' => true,
        'default'  => get_option($this->id . '_vip_password'),
      ),
    );
    $isValidated = get_option($this->id . '_credentials_validated') === 'yes';
    $this->title            = $this->get_option('title');
    $this->vip_username     = $this->get_option('vip_username');
    $this->vip_password     = $this->get_option('vip_password');
    $this->test_mode        = $this->get_option('test_mode');
    $this->validated        = $isValidated;

    if ($this->validated) {
      $this->supports = array(
        'shipping-zones',
        'settings'
      );
    }
  }

  /**
   * Output the admin options table.
   *
   * @return void
   */
  public function admin_options(): void
  {
    parent::admin_options();

    $configUrlsExists           = get_option(get_prefix() . '_config_urls_exists') === 'yes';
    $validation                 = get_option(get_prefix() . '_credentials_validated') === 'yes';
    $case = '';

    if (!$validation) {
      $case = 'credentialsnotvalidated';
    }
    if (!$configUrlsExists) {
      $case = 'confignotexists';
    }

    switch ($case) {
      case 'confignotexists':
        $message = __('Error : config file or API URLs are invalid ', 'smart_send');
        $class   = 'notice-error';
        break;
      case 'credentialsnotvalidated':
        $message = __('Note: You must have a VIP account to use this plugin.', 'smart_send');
        $class   = 'notice-error';
        break;
      default:
        $message = __('Success: Your account has been successfully verified.', 'smart_send');
        $class = 'notice-success';
    }

?>
    <div class="<?php echo $class; ?> notice inline" style="display: inline-block;">
      <p><?php echo $message; ?></p>
    </div>
    <?php

    if ($class == 'notice-success') {
      $this->renderTimeZoneNotify();
      $this->renderProductsWithMissingShippingParams(); // Check for unfilled shipping attributes products.
    }
  }

  /**
   * Not Australian timezone warning on plugin's settings page.
   *
   * @return void
   */
  public function renderTimeZoneNotify(): void
  {
    $australiaTimeZones = $this->getAustralianTimezones();
    if (!self::isAustralianTimezone($australiaTimeZones)) {
      $timezoneNotice  = 'Timezone problem : Current Wordpress timezone is not Australian. Change your timezone ';
      $timezoneNotice .=   '<a href="' . get_site_url() . '/wp-admin/options-general.php">here</a>';
      $timezoneNotice .=   ' to calculate right order time';
    ?>
      <div class="smart_send-unfilled__warning" style="margin:15px 0 45px 0;">
        <span class="notice notice-warning" style="padding:13px;"><?php echo $timezoneNotice; ?>
      </div>
    <?php
    }
  }
  /**
   * Weight or Dimensions unfilled products output on plugin's settings page.
   *
   * @return void
   */
  public function renderProductsWithMissingShippingParams(): void
  {
    $products  = wc_get_products(['numberposts' => -1]);

    $unfilledProducts = array_filter($products, function ($product) {
      if (!$product->get_weight() || $product->get_dimensions(false) === 'N/A' || !$product->get_shipping_class()) {
        return $product;
      }
    });

    $productsToOutput = array_map(function ($product) {
      return [
        'id'          => $product->get_ID(),
        'name'        => $product->get_name(),
        'dimensions'  => $product->get_dimensions(false),
        'shipping'    => $product->get_shipping_class() ? $product->get_shipping_class() : 'N/A',
        'description' => substr($product->get_description(), 0, 50) . '...',
        'link'        => get_bloginfo('url') . '/wp-admin/post.php?post=' . $product->get_ID() . '&action=edit',
      ];
    }, $unfilledProducts);


    if (!empty($productsToOutput)) {
      $message = __('Warning : Some of your Products have no weight or dimensions or shipping class (see list below ). This may make shipping calculations for these items inaccurate or impossible.', 'smart_send');
    ?>
      <div class="smart_send-unfilled__warning" style="margin:15px 0;">
        <span class="notice notice-warning" style="padding:13px;"><?php echo $message; ?>
      </div>
      <div id="smart_send-unfilled-output__block" style="background: #FFF; height: 200px; overflow: hidden ; overflow-y: scroll; padding: 0 !important;margin-top: 30px;border: 1px solid #C3C4C7;width:100%;">
        <table id="smart_send-unfilled-output__table" style="border-collapse: collapse; width: 100%;">
          <tr class="smart_send-unfilled-output__raw" style=" border: 1px solid #dddddd; text-align: left ;">
            <th style="padding : 10px 0 10px 20px;"><?php echo __('Product ID', 'smart_send'); ?></th>
            <th style="padding : 10px 0;"><?php echo __('Product Name', 'smart_send'); ?></th>
            <th style="padding : 10px 0;"><?php echo __('Product Dimensions', 'smart_send'); ?></th>
            <th style="padding : 10px 0;"><?php echo __('Product Shipping Class', 'smart_send'); ?></th>
            <th style="padding : 10px 0;"><?php echo __('Product Description', 'smart_send'); ?></th>
            <th style="padding : 10px 0;"><?php echo __('Product Link', 'smart_send'); ?></th>
          </tr>
          <?php foreach ($productsToOutput as $product) : ?>
            <tr style=" border: 1px solid #dddddd; ">
              <td style="padding:5px 0 5px 40px; text-align: left;"><?php echo $product['id'] ?></td>
              <td style="padding:5px 0; text-align: left"><?php echo $product['name'] ?></td>
              <td style="padding:5px 0; text-align: left"><?php echo $product['dimensions'] ?></td>
              <td style="padding:5px 0; text-align: left"><?php echo $product['shipping'] ?></td>
              <td style="padding:5px 0; text-align: left"><?php echo $product['description'] ?></td>
              <td style="padding:5px 0; text-align: left"><a href="<?php echo $product['link'] ?>" style="text-decoration-line: none">Edit Product</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
<?php
    } //end if
  } //end unfilled_attributes_products()


  /**
   * Processes and saves global shipping method options in the admin area.
   *
   * @return bool was anything saved?
   */
  public function process_admin_options(): bool
  {
    if (parent::process_admin_options()) {
      $prefix    = get_prefix();
      update_option($prefix . '_process_admin_options', true);

      $url = get_admin_url() . "admin.php?page=" . get_prefix() . '_installation';
      wp_safe_redirect($url);
    }

    return false;
  }

  /**
   * @param  array  $package  (default: array())
   *
   */
  public function calculate_shipping($package = array())
  {
    wp_log("INFO", "Start calculate shipping");
    wp_log("DEBUG", "is_cart", is_cart());
    wp_log("DEBUG", "is_checkout", is_checkout());

    $package = $this->prepare_package($package);

    if (!empty($this->_errors)) {
      $errors = is_array($this->_errors) ? implode(' ', $this->_errors) : $this->_errors;
      wp_log("INFO", "Calculate shipping method has errors: " . $errors);
      if (!current_user_can('manage_options')) {
        return;
      }

      foreach ($this->_errors as $error) {
        wc_add_notice(__($error, 'smart_send'), 'error');
      }
      return;
    }

    $service = new Service();
    $get_quotes_response  = $service->get_quotes($package);

    if (!$get_quotes_response['success']) {
      $errors = is_array($get_quotes_response['errors']) ? implode(' ', $get_quotes_response['errors']) : $get_quotes_response['errors'];
      wp_log("ERROR", "Error getting quotes: " . $errors);
      return;
    }
    WC()->session->set(get_prefix() . '_quotes', $get_quotes_response);

    if ($get_quotes_response['errors'] && current_user_can('manage_options')) {
      foreach ($get_quotes_response['errors'] as $error) {
        wc_add_notice(__($error, 'smart_send'), 'error');
      }
    }

    $quotes = (isset($get_quotes_response['quote']) && is_array($get_quotes_response['quote']))
      ? $get_quotes_response['quote']
      : $get_quotes_response['quotes'];

    foreach ($quotes as $key => $quote) {
      $rate       = array(
        'id'        => $this->id . $this->instance_id . $key,
        'label'     => !empty($quote['label']) ? $quote['label'] : $this->title,
        'cost'      => $quote['cost'],
        'calc_tax'  => 'per_order',
        'meta_data' => [
          'priceID' => $quote['original']['priceID'],
        ]
      );

      $this->add_rate($rate);
      wp_log("INFO", "Added rate: ", $rate);
    }
    set_settings($get_quotes_response['settings']);
  }

  /**
   * @param $package
   *
   * @return array
   */
  private function prepare_package($package = array()): array
  {
    $prefix = get_prefix();

    $items  = prepare_line_items($package['contents']);
    if (is_wp_error($items)) {
      $this->_errors = $items->get_error_messages('ERROR');
    } else {
      $package['contents'] = $items;
    }

    $package['id'] = 1;
    wp_log('DEBUG', 'WC customer billing:', WC()->customer->get_billing());
    wp_log('DEBUG', 'WC customer shipping:', WC()->customer->get_shipping());
    wp_log('DEBUG', 'WC woocommerce_ship_to_destination setting:', get_option('woocommerce_ship_to_destination'));

    if (trim(get_option('woocommerce_ship_to_destination')) === 'billing' ||  trim(get_option('woocommerce_ship_to_destination')) === 'billing_only') {
      wp_log('DEBUG', 'prepare_package: using billing address');
      $billing_params = WC()->customer->get_billing();

      $package['destination']['first_name']   = $billing_params['first_name'];
      $package['destination']['last_name']    = $billing_params['last_name'];
      $package['destination']['company']      = $billing_params['company'];
      $package['destination']['address_1']    = $billing_params['address_1'];
      $package['destination']['address_2']    = $billing_params['address_2'];
      $package['destination']['city']         = $billing_params['city'];
      $package['destination']['postcode']     = $billing_params['postcode'];
      $package['destination']['country']      = $billing_params['country'];
      $package['destination']['state']        = $billing_params['state'];
      $package['destination']['email']        = $billing_params['email'];
      $package['destination']['phone']        = $billing_params['phone'];
    }

    if (trim(get_option('woocommerce_ship_to_destination')) ===  'shipping') {
      wp_log('DEBUG', 'prepare_package: using shipping address');
      $shipping_params = WC()->customer->get_shipping();

      $package['destination']['first_name']   = $shipping_params['first_name'];
      $package['destination']['last_name']    = $shipping_params['last_name'];
      $package['destination']['company']      = $shipping_params['company'];
      $package['destination']['address_1']    = $shipping_params['address_1'];
      $package['destination']['address_2']    = $shipping_params['address_2'];
      $package['destination']['city']         = $shipping_params['city'];
      $package['destination']['postcode']     = $shipping_params['postcode'];
      $package['destination']['country']      = $shipping_params['country'];
      $package['destination']['state']        = $shipping_params['state'];
      $package['destination']['email']        = WC()->customer->get_billing_email();
      $package['destination']['phone']        = $shipping_params['phone'];
    }


    if (
      empty($package['destination']['postcode'])
      || empty($package['destination']['city'])
      || empty($package['destination']['state'])
    ) {
      $this->_errors[] = 'Incomplete destination address';
    }

    $package['max_item_weight'] = max(array_map(function ($item) {
      return $item['weight'];
    }, $package['contents']));
    $package['shipping_options'] = self::get_shipping_options($package);

    WC()->session->set($prefix . '_shipping_options', $package['shipping_options']);
    wp_log('DEBUG', 'prepare_package() result:', $package);

    return $package;
  }

  /**
   * @param array $package
   *
   * @return array
   */
  private function get_shipping_options(array $package): array
  {
    $prefix = get_prefix();

    $settings = get_settings();

    $receipted_delivery  = WC()->session->get($prefix . '_receiptedDelivery');
    $transport_assurance = WC()->session->get($prefix . '_transportAssurance');

    $pickupFlag = $deliveryFlag = false;
    $post_data = $_POST['post_data'] ?? $_GET['post_data'] ?? 'billing_company=&';

    if ($settings && $settings['forceTailLiftDelivery'] && $package['max_item_weight'] > 30 && preg_match('/billing_company=&/', $post_data)) {
      $deliveryFlag = true;
    }
    if ($settings && $settings['tailLiftPickup'] > 0 && $package['max_item_weight'] > $settings['tailLiftPickup']) {
      $pickupFlag = true;
    } else {
      if ($settings['tailLiftPickup']) {
        wp_log("INFO", "tailLiftPickup: " . $settings['tailLiftPickup']
          . " max_item_weight: " . $package['max_item_weight']);
      } else {
        wp_log("INFO", "tailLiftPickup: " . 'None'
          . " max_item_weight: " . $package['max_item_weight']);
      }
    }

    $tailLift = $pickupFlag ? 'PICKUP' : 'NONE';

    if ($deliveryFlag) {
      $tailLift = $pickupFlag ? 'BOTH' : 'DELIVERY';
    }

    wp_log("INFO", "Tail lift: " . $tailLift);
    return [
      'receiptedDelivery'  => $receipted_delivery === 'yes',
      'transportAssurance' => $transport_assurance === 'yes',
      'tailLift' => $tailLift
    ];
  }

  /**
   * @return array
   *
   */
  public function getAustralianTimezones(): array
  {
    $timezones = get_option(get_prefix() . '_australian_timezones');

    if (empty($timezones)) {
      foreach (timezone_identifiers_list() as $timezone) {
        $datetime = new \DateTime('now', new \DateTimeZone($timezone));

        if (strpos($timezone, 'Australia') >= 0) {
          $name        = str_replace('Australia/', '', $timezone);
          $name        = str_replace('_', ' ', $name);
          $timezones[] = [
            'offset'   => trim($datetime->format('P')),
            'name'     => trim($name),
            'timezone' => trim($timezone),
          ];
        }
      }

      $sortArray = [];
      foreach ($timezones as $key => $row) {
        $sortArray[$key] = $row['name'];
      }
      array_multisort($sortArray, SORT_ASC, SORT_REGULAR);
      update_option(get_prefix() . '_australian_timezones', $sortArray);
      $timezones = $sortArray;
    }
    return $timezones;
  }

  /**
   * @param array $timeZones
   *
   * @return bool
   */
  public static function isAustralianTimezone(array $timeZones): bool
  {
    $currentTimezone = trim(wp_timezone_string());
    $offsets = array_map(function ($item) {
      $key = 'offset';
      return isset($item[$key]) ?? '';
    }, $timeZones);

    $australianTimezone = true;
    if (strpos(trim($currentTimezone), 'Australia') === false  && !in_array($currentTimezone, $offsets)) {
      $australianTimezone = false;
    }
    return $australianTimezone;
  }
}
