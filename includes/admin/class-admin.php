<?php

namespace Smart_Send\Admin;

use Smart_Send\Service;
use WC_Admin_Meta_Boxes;

use function Smart_Send\get_package_types;
use function Smart_Send\get_prefix;

class Admin
{
    /**
     * @return void
     */
    public static function add_dashboard_page(): void
    {
        $prefix = get_prefix();
        add_submenu_page(
            'woocommerce',
            __('SmartSend Dashboard', 'smart_send'),
            __('SmartSend', 'smart_send'),
            'manage_woocommerce',
            $prefix . '_dashboard',
            array( __CLASS__, 'renderDashboard' )
        );
    }

    /**
     * @return void
     */
    public static function renderDashboard(): void
    {
        $prefix  = get_prefix();
        wp_enqueue_script($prefix . '-admin');
        wp_enqueue_style($prefix . '-admin');

        if (get_option($prefix . '_credentials_validated') !== 'yes') {
            $url = add_query_arg(array(
                'page'    => 'wc-settings',
                'tab'     => 'shipping',
                'section' => $prefix
            ), admin_url('admin.php'));
            ?>
            <div class="banner-to-settings">
                <div class="message">
                    <h3>Thanks for installing SmartSend plugin!</h3>
                    <p> To start using it please enter SmartSend credentials <a href="<?php echo $url; ?>">here</a></p>
                </div>
            </div>
            <?php
            return;
        }
        $service = new Service();
        $activeTab = $_GET['activeTab'] ?? '';
        $url = add_query_arg('activeTab', $activeTab, $service->get_dashboard_url());
        ?>
        <iframe id="iframe_dashboard" src="<?php echo $url; ?>" title="SmartSend dashboard"></iframe>
        <?php
    }

    /**
     * @param array $links
     *
     * @return array
     */
    public static function settings_links(array $links): array
    {
        $url = get_admin_url() . "admin.php?page=wc-settings&tab=shipping&section=" . get_prefix();
        $settingsLink = '<a href="' . $url . '">' . __('Settings', 'smart_send') . '</a>';

        if (get_option(get_prefix() . "_credentials_validated") === 'yes') {
            $url = get_admin_url() . "admin.php?page=" . get_prefix() . '_dashboard';
            $dashboardLink = '<a href="' . $url . '">' . __('Dashboard', 'smart_send') . '</a>';
            $links = array_reverse($links);
            $links['dashboard'] = $dashboardLink ;
            $links = array_reverse($links) ;
        }

        $links = array_reverse($links);
        $links['settings'] = $settingsLink ;

        return array_reverse($links);
    }

    /**
     * @return void
     */
    public static function admin_notices(): void
    {
        $notice = get_option(get_prefix() . '_removed');

        if ($notice !== 'yes') {
            return;
        }
        delete_option(get_prefix() . '_removed');
        echo '<div class="notice notice-success is-dismissible">
             <p>Instances deleted successfully.</p>
         </div>';
    }

    /**
     * @param $product
     *
     * @return void
     */
    public static function admin_process_product_object($product): void
    {
        if (! isset($_POST['product_shipping_class'])) {
          return;
        }
        $shippingClassID = absint(wp_unslash($_POST['product_shipping_class']));
        if ($shippingClassID) {
            $weightUnit    = get_option('woocommerce_weight_unit');
            $dimensionUnit = get_option('woocommerce_dimension_unit');
            $term = get_term_by('id', $shippingClassID, 'product_shipping_class');
            $packageTypes = get_package_types();

            if ($term && isset($packageTypes[$term->name])) {
                $length = isset($_POST['_length']) ? wc_clean(wp_unslash($_POST['_length'])) : null;
                $weight = isset($_POST['_weight']) ? wc_clean(wp_unslash($_POST['_weight'])) : null;
                $package = $packageTypes[$term->name];
                if (wc_get_dimension($length, 'cm', $dimensionUnit) > $package['length']) {
                    WC_Admin_Meta_Boxes::add_error(sprintf(__('The length cannot be larger than allowed for the shipping class. Max length %dcm.', 'smartsend'), $package['length']));
                }
                if (wc_get_weight($weight, 'kg', $weightUnit) > $package['weight']) {
                    WC_Admin_Meta_Boxes::add_error(sprintf(__('The weight cannot be larger than allowed for the shipping class. Max weight %dkg.', 'smartsend'), $package['weight']));
                }
            }
        }
    }
}
