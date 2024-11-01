<?php

namespace Smart_Send;

use Exception;

/**
 * Installation related functions and actions.
 *
 * @version  1.0.0
 */

if (!defined("ABSPATH")) {
  exit;
}

class Install
{
  /**
   * @return void
   */
  public static function instanceCreationUpdate(): void
  {
    if (
      !is_admin() ||
      !'wc-settings' === $_GET['page'] ||
      !isset($_POST['woocommerce_smart_send_vip_username'])
    ) {
      wp_log('ERROR', 'Invalid call for instanceCreationUpdate method');
      return;
    }

    $vipUsername = sanitize_textarea_field(trim($_POST['woocommerce_smart_send_vip_username']));
    $vipPassword = sanitize_textarea_field(trim($_POST['woocommerce_smart_send_vip_password']));
    $testMode = $_POST['woocommerce_smart_send_test_mode'] == 1 ? 'yes' : 'no';


    if (isset($vipUsername) && isset($vipPassword)) {
      update_option(get_prefix() . '_vip_username', $vipUsername);
      update_option(get_prefix() . '_vip_password', $vipPassword);
      update_option(get_prefix() . '_test_mode', $testMode);
    } else {
      return;
    }

    $case = 'unknown';

    if (
      !get_option(get_prefix() . "_admin_install_timestamp") &&
      get_option(get_prefix() . '_config_urls_exists') === 'yes' &&
      self::validate_credentials()
    ) {
      $case = 'install';
    }
    if (get_option(get_prefix() . "_admin_install_timestamp") && self::validate_credentials()) {
      $case = 'update';
    }
    $updateConfigurationArray = [
      "configuration" => [],
      "data" => [
        'wordpressdata' => self::getActivePluginsAndThemes()
      ]
    ];

    switch ($case) {
      case 'install':
        self::install();
        self::updateApplicationConfigurationData($updateConfigurationArray);
        break;
      case 'update':
        self::updateInstallation();
        self::updateApplicationConfigurationData($updateConfigurationArray);
        break;
      default:
        wp_log('ERROR', 'Invalid statement of instanceCreationUpdate method: ' . $case);
        break;
    }
  }

  /**
   * Check if we are not already running this routine.
   *
   * @return bool
   */
  private static function isInProcessing(): bool
  {
    wp_log('INFO', 'We still in process and running installation');

    $constantName = get_constant_prefix() . '_PROCESSING';
    $result = get_transient(get_prefix() . "_processing") || (defined($constantName) && constant($constantName));

    wp_log('INFO', 'IS_IN_PROCESSING: ', $result);

    return $result;
  }

  /**
   * @return void
   */
  private static function start_process(): void
  {
    wp_log('INFO', 'Setting up processing flag...');
    $constantName = get_constant_prefix() . '_PROCESSING';
    set_transient(get_prefix() . '_processing', 'yes', MINUTE_IN_SECONDS * 10);
    if (!defined($constantName)) {
      define($constantName, true);
    }
  }

  /**
   * @return void
   */
  private static function process_completed(): void
  {
    update_option(get_prefix() . "_version", constant(get_constant_prefix() . '_VERSION'));
    delete_transient(get_prefix() . "_processing");

    // Trigger action
    do_action(get_prefix() . "_process_completed");

    wp_log('INFO', 'Cleared processing flag.');
  }

  /**
   * @return bool
   *
   */
  private static function validate_credentials(): bool
  {
    $prefix    = get_prefix();
    $installed = get_option($prefix . "_admin_install_timestamp");
    $service   = new Service();
    $shipping  = new Shipping_Method();
    $credentials     = [
      'username' => get_option(get_prefix() . "_vip_username"),
      'password' => get_option(get_prefix() . "_vip_password"),
      'isProd'   => get_option(get_prefix() . "_test_mode") !== 'yes',
      'tenantId' => get_tenant_id(),
      'continue' => true
    ];
    wp_log(' INFO', ' Credentials : ', $credentials);

    if ($installed) {
      $credentials['continue'] = false;
    }

    $result       = $service->validate_credentials($credentials);

    $isValidated = (!$result || $result['status'] === 'ERROR') ? 'no' : 'yes';

    update_option($prefix . '_credentials_validated', $isValidated, 'yes');

    if ($isValidated === 'yes' && !$installed) {
      update_option($prefix . '_secret_id', $result['referenceId']);
      update_option($prefix . '_secret_key', $result['secret']);
    }

    return $isValidated === 'yes';
  }

  /**
   * @return void
   */
  private static function install(): void
  {
    $isValid = get_option(get_prefix() . "_credentials_validated");

    // Check if we are not already running this routine or if the credentials are invalid.
    if (self::isInProcessing() || !($isValid === 'yes')) {
      return;
    }

    wp_log('INFO', 'START INSTALLATION');

    try {
      flush_cache();
      self::start_process();
      self::create_installation();
      self::add_shipping_classes();
      self::setInstallationResult('yes', '');
    } catch (\Throwable $e) {
      wp_log('ERROR', $e->getMessage());
      self::setInstallationResult('no', 'Installation error ' . $e->getMessage() . ' installation has cancelled');
    } finally {
      // Flush rules after install
      self::process_completed();
    }
    wp_log('INFO', 'END INSTALLATION');
  }

  /**
   * @throws Exception
   */
  private static function create_installation(): void
  {
    wp_log('INFO', 'CREATE INSTALLATION');

    $shipping = new Shipping_Method();
    $apiKeys = self::get_wc_api_keys();
    $data     = [
      "tenantId"       => get_tenant_id(),
      "name"           => get_bloginfo("name"),
      "configuration"  => array(
        "username"       => get_option(get_prefix() . "_vip_username"),
        "password"       => get_option(get_prefix() . "_vip_password"),
        "isProd"         => get_option(get_prefix() . "_test_mode") !== 'yes',
        "restUrl"        => get_rest_url(null, "wc/v3/orders/{orderId}"),
        "restSettingsUrl" => get_rest_url(null, "wc/v3/settings/general"),
        "consumerKey"    => $apiKeys["consumer_key"],
        "consumerSecret" => $apiKeys["consumer_secret"],
        "secretKey"      => self::create_order_webhooks()
      )
    ];
    $service   = new Service();
    $service->install($data);

    add_option(get_prefix() . '_admin_install_timestamp', time());

    wp_log('INFO', 'INSTALLATION CREATED');
  }


  /**
   * @return void
   */
  private static function update_installation(): void
  {
    wp_log('INFO', 'START UPDATE INSTALLATION');
    if (self::isInProcessing()) {
      return;
    }
    self::start_process();
    $shipping = new Shipping_Method();
    $data     = [
      "tenantId"       => get_tenant_id(),
      "name"           => get_bloginfo("name"),
      "configuration"  => array(
        "username" => $shipping->get_option("vip_username"),
        "password" => $shipping->get_option("vip_password"),
        "isProd"   => $shipping->get_option("test_mode") !== 'yes'
      )
    ];
    $service  = new Service();
    $service->update_installation($data);
    self::process_completed();

    wp_log('INFO', 'END UPDATE INSTALLATION');
  }

  /**
   * Update Installation  without the key generation
   *
   * @return void
   */
  private static function updateInstallation(): void
  {
    wp_log('INFO', 'START UPDATE INSTALLATION');
    if (self::isInProcessing()) {
      return;
    }
    $shipping  = new Shipping_Method();
    self::start_process();
    $data     = [
      "tenantId"       => get_tenant_id(),
      "name"           => get_bloginfo("name"),
      "configuration"  => array(
        "username" => get_option(get_prefix() . '_vip_username'),
        "password" => get_option(get_prefix() . '_vip_password'),
        "isProd"   => get_option(get_prefix() . '_test_mode') !== 'yes',
      ),
    ];

    $service  = new Service();
    $service->update_installation($data);
    self::process_completed();
    self::setUpdateResult('yes', '');
    wp_log('INFO', 'END UPDATE INSTALLATION');
  }

  /**
   * Set Installation Result
   *
   * @param string $created
   * @param string $message
   * @return void
   */
  private static function setInstallationResult(string $created, string $message): void
  {
    $installationInfo = [
      'created' => $created,
      'message' => $message,
      'time'    => time(),
    ];

    update_option(get_prefix() . '_installation_created', $installationInfo);
  }

  /**
   * Set Update Result
   *
   * @param string $updated
   * @param string $message
   * @return void
   */
  private static function setUpdateResult(string $updated, string $message): void
  {
    $updateInfo = [
      'updated' => $updated,
      'message' => $message,
      'time'    => time(),
    ];

    update_option(get_prefix() . '_installation_updated', $updateInfo);
  }


  /**
   * @return array
   */
  private static function get_wc_api_keys(): array
  {
    global $wpdb;

    //Check for old API keys.
    $apiKeys = $wpdb->get_results("SELECT * FROM  {$wpdb->prefix}woocommerce_api_keys  WHERE description = 'SmartSend Orders'");
    if (!empty($apiKeys)) {
      $wpdb->delete($wpdb->prefix . "woocommerce_api_keys", ['description' => "SmartSend Orders"]);
    }

    $userID         = get_current_user_id();
    $consumerKey    = "ck_" . wc_rand_hash();
    $consumerSecret = "cs_" . wc_rand_hash();

    $data = array(
      "user_id"         => $userID,
      "description"     => "SmartSend Orders",
      "permissions"     => "read_write",
      "consumer_key"    => wc_api_hash($consumerKey),
      "consumer_secret" => $consumerSecret,
      "truncated_key"   => substr($consumerKey, -7),
    );

    $wpdb->insert(
      $wpdb->prefix . "woocommerce_api_keys",
      $data,
      array(
        "%d",
        "%s",
        "%s",
        "%s",
        "%s",
        "%s",
      )
    );

    if ($wpdb->insert_id === 0) {
      throw new Exception(__("There was an error generating your API Key."));
    }


    return [
      "consumer_key"    => $consumerKey,
      "consumer_secret" => $consumerSecret,
    ];
  }


  /**
   * @return string
   * @throws Exception
   */
  private static function create_order_webhooks(): string
  {
    $config     = get_config();

    // Check for current webhooks.
    $dataStore = \WC_Data_Store::load("webhook");
    $webhooks   = $dataStore->search_webhooks(["search" => $config["WEBHOOK_NAME"]["created"]]);
    if ($webhooks) {
      $webhook = \wc_get_webhook($webhooks[0]);
      return $webhook->get_secret();
    }

    // Create new webhooks.
    $userID     = get_current_user_id();
    $secretKey = \wp_generate_password(24);
    foreach (["created", "updated"] as $type) {
      $webhookUrl = get_webhook_delivery_url($type);

      $webhook    = new \WC_Webhook();
      $webhook->set_user_id($userID); // User ID used while generating the webhook payload.
      $webhook->set_topic("order." . $type); // Event used to trigger a webhook.
      $webhook->set_status("active"); // Webhook status.
      $webhook->set_name($config["WEBHOOK_NAME"][$type]);
      $webhook->set_secret($secretKey); // Secret to validate webhook when received.
      $webhook->set_delivery_url($webhookUrl); // URL where webhook should be sent.

      $webhook->save();
    }


    return $secretKey;
  }

  /**
   * @return void
   */
  private static function add_shipping_classes(): void
  {
    $package_types = get_package_types();

    foreach ($package_types as $type => $package) {
      $term = get_term_by('name', $type, 'product_shipping_class');
      if (!$term) {
        wp_insert_term(
          $type,
          'product_shipping_class',
          array(
            'slug'        => strtolower(preg_replace('/\W/', '_', $type)),
            'description' => $package['description']
          )
        );
      }
    }
  }

  /**
   *
   * @param array $updateConfigurationArray
   *
   * @return void
   */
  public static function updateApplicationConfigurationData(array $updateConfigurationArray): void
  {
    $config = get_config();
    $updateConfigurationUrl = $config['BFF_URL'] . '/wc/' . $config['CLIENT_ID'] . '/update-configuration';
    $body = $updateConfigurationArray;
    $service = new Service();
    $service->update_configuration($updateConfigurationUrl, $body);
  }

  /**
   * @return array
   */
  public static function getActivePluginsAndThemes(): array
  {
    if (!function_exists('get_plugins')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugins = get_plugins();
    $activePluginsList = [];
    foreach ($plugins as $name => $plugin) {
      if (is_plugin_active($name)) {
        $activePluginsList[] = $plugin['Name'] . ', version ' . $plugin['Version'];
      }
    }
    $activeTheme = wp_get_theme();
    $activeThemeData =  $activeTheme->get('Name') . ', version ' . $activeTheme->get('Version');

    return [
      'php' => phpversion(),
      'wordpress' => get_bloginfo('version'),
      'plugins' => $activePluginsList,
      'theme' => $activeThemeData
    ];
  }
}
