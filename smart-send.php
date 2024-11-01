<?php

/**
 * Plugin Name:       Smart Send Shipping for WooCommerce
 * Plugin URI:        http://digital.smartsend.com.au/plugins/woocommerce
 * Description:       Add Smart Send Australian shipping calculations to Woo Commerce.
 * Version:           4.1.1
 * Requires at least: 4.7
 * Tested up to:      6.6.2
 * Requires PHP:      7.4
 *
 * Author:            SafeDigit
 * Author URI:        https://safedigit.io
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Text Domain:       smart_send
 * Domain Path:       /languages
 *
 */

namespace Smart_Send;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

if (!defined('WPINC')) {
  die;
}

if (version_compare('7.4.0', PHP_VERSION, '>')) {
  die(sprintf('We are sorry, but you need to have at least PHP 7.4.0 to run this plugin
    (currently installed version: %s)'
    . ' - please upgrade or contact your system administrator.', PHP_VERSION));
}

if (version_compare('5.9.0', get_bloginfo('version'), '>')) {
  die(sprintf('We are sorry, but you need to have at least WordPress 5.9 to run this plugin
    (currently installed version: %s)'
    . ' - please upgrade or contact your system administrator.', get_bloginfo('version')));
}


include_once(ABSPATH . 'wp-admin/includes/plugin.php');
if (!is_plugin_active('woocommerce/woocommerce.php')) {
  die(__('We are sorry, but you need to have activated WooCommerce plugin to use Trellus plugin', 'trellus'));
} else {
  $plugins = get_plugins();
  $woocommerceCurrentVersion = $plugins['woocommerce/woocommerce.php']['Version'];
  if (version_compare('5.0.0', $woocommerceCurrentVersion, '>')) {
    die(sprintf('We are sorry, but you need to have at least WooCommerce 5.0.0 to run this plugin
                            (currently installed version: %s)'
      . ' - please upgrade or contact your system administrator.', $woocommerceCurrentVersion));
  }
}

include_once('includes/class-autoloader.php');

if (!class_exists('Prototype')) :

  /**
   * Main Class.
   *
   * @class Prototype
   * @version 1.0.0
   */
  final class Prototype
  {
    /**
     * Version.
     *
     * @var string
     */
    public string $version = '1.0.0';

    /**
     * Prefix.
     *
     * @var string
     */
    public string $prefix = 'smart_send';

    /**
     * The single instance of the class.
     *
     * @var ?Prototype
     */
    protected static ?Prototype $_instance = null;

    /**
     * Main Instance.
     *
     * Ensures only one instance of Plugin is loaded or can be loaded.
     *
     * @static
     * @see prototype()
     * @return Prototype - Main instance.
     */
    public static function instance(): ?Prototype
    {
      if (is_null(self::$_instance)) {
        self::$_instance = new self();
      }
      return self::$_instance;
    }

    /**
     * Cloning is forbidden.
     */
    public function __clone()
    {
      _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), '1.0.0');
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup()
    {
      _doing_it_wrong(__FUNCTION__, __('Cheatin&#8217; huh?'), '1.0.0');
    }

    /**
     * Prototype Constructor.
     */
    public function __construct()
    {
      $this->define_constants();
      $this->load();
      $this->init_hooks();

      do_action($this->prefix . '_loaded');
    }

    /**
     * Define Constants.
     */
    private function define_constants()
    {
      $upload_dir = wp_upload_dir();

      $prefix = strtoupper(__NAMESPACE__);

      $this->define($prefix . '_PLUGIN_FILE', __FILE__);
      $this->define($prefix . '_PLUGIN_BASENAME', plugin_basename(__FILE__));
      $this->define($prefix . '_VERSION', $this->version);
      $this->define($prefix . '_LOG_DIR', $upload_dir['basedir'] . $this->prefix . '/-logs/');
    }

    /**
     * Define constant if not already set.
     *
     * @param  string  $name
     * @param  string|bool  $value
     */
    private function define(string $name, $value)
    {
      if (!defined($name)) {
        define($name, $value);
      }
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function load()
    {
      include_once 'includes/core-functions.php';
      new Autoloader();
    }

    /**
     * Hook into actions and filters.
     */
    private function init_hooks()
    {
      add_action('init', array($this, 'init'), 0);
      Hooks::init();
    }

    /**
     * Init Plugin when WordPress Initialises.
     */
    public function init()
    {
      // Before init action.
      do_action('before_' . $this->prefix . '_init');


      // Set up localisation.
      $this->load_plugin_textdomain();

      // Init action.
      do_action($this->prefix . '_init');
    }

    /**
     * Load Localisation files.
     *
     * Note: the first-loaded translation file overrides any following ones if the same translation is present.
     *
     * Locales found in:
     *      - WP_LANG_DIR/plugin-prefix/plugin-prefix-LOCALE.mo
     *      - WP_LANG_DIR/plugins/plugin-prefix-LOCALE.mo
     */
    public function load_plugin_textdomain()
    {
      $locale = apply_filters('plugin_locale', get_locale(), $this->prefix);

      load_textdomain($this->prefix, WP_LANG_DIR . '/' . $this->prefix . '/' . $this->prefix . '-' . $locale . '.mo');
      load_plugin_textdomain($this->prefix, false, plugin_basename(dirname(__FILE__)) . '/i18n/languages');
    }
  }

endif;

/**
 * Main instance of Plugin.
 *
 * Returns the main instance of Plugin to prevent the need to use globals.
 *
 * @return ?Prototype
 */
function prototype(): ?Prototype
{
  return Prototype::instance();
}

// Global for backwards compatibility.
$GLOBALS['smart_send'] = prototype();


register_activation_hook(__FILE__, 'flush_rewrite_rules');
