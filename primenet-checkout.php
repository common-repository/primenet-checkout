<?php
/*
Plugin Name: Primenet Checkout
Description: Primenet Checkout is a custom payment gateway for WooCommerce, allowing customers to make payments using Primenet.
Short Description: Primenet Checkout is a custom payment gateway for WooCommerce.
Version: 1.0
Requires at least: 5.5
Requires PHP: 7.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Author: PrimeNet Zambia
Author URI: https://primenetzambia.com
*/

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

add_action('plugins_loaded', 'primenet_checkout_init', 0);

function primenet_checkout_init()
{
  if (!class_exists('WC_Payment_Gateway')) return;
  /**
   * Gateway class
   */
  class PrimenetCheckout extends WC_Payment_Gateway
  {

    public $api_key;

    public function __construct()
    {
      $this->id                 = 'primenet_checkout';
      $this->icon               = plugin_dir_url(__FILE__) . 'assets/icon.png';  // URL of the icon that will be displayed on checkout page
      $this->has_fields         = false;
      $this->method_title       = __('PrimeNet Checkout', 'primenet-checkout');
      $this->method_description = __('Allows payments with PrimeNet Checkout.', 'primenet-checkout');

      $this->init_form_fields();
      $this->init_settings();

      $this->title        = "Primenet Checkout";
      $this->api_key = sanitize_text_field($this->get_option('api_key'));

      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_api_primenet_checkout_order_details', array($this, 'primenet_checkout_order_details'));
      add_action('woocommerce_api_primenet_checkout_update_order_status', array($this, 'primenet_checkout_update_order_status'));

      add_action('admin_notices', array($this, 'primenet_checkout_check_api_key'));
    }

    public function primenet_checkout_check_api_key()
    {
      if (empty($this->api_key)) {
        echo '<div class="notice notice-error is-dismissible">
                <p>' . esc_html(__('PrimeNet Checkout: Please configure your API key in the plugin settings.', 'primenet-checkout')) . '</p>
          </div>';
      }
    }

    public function is_available()
    {
      $this->init_settings();
      $this->api_key = sanitize_text_field($this->get_option('api_key'));

      if (empty($this->api_key)) {
        return false;
      }

      return parent::is_available();
    }

    public function init_form_fields()
    {
      $this->form_fields = array(
        'enabled' => array(
          'title'   => __('Enable/Disable', 'primenet-checkout'),
          'type'    => 'checkbox',
          'label'   => __('Enable PrimeNet Checkout', 'primenet-checkout'),
          'default' => 'yes'
        ),
        'api_key' => array(
          'title'       => __('API Key', 'primenet-checkout'),
          'type'        => 'text',
          'description' => __('Enter your PrimeNet API Key here.', 'primenet-checkout'),
          'default'     => '',
          'desc_tip'    => true,
        )
      );
    }

    public function process_payment($order_id)
    {
      $redirect_url = 'https://ecommerce.primenetpay.com/woo';

      $redirect_url = add_query_arg(
        array(
          'order_id' => $order_id,
          'api_key' => $this->api_key,
          'shop_url' => home_url(),
        ),
        $redirect_url
      );

      return array(
        'result' => 'success',
        'redirect' => $redirect_url,
      );
    }

    public function primenet_checkout_order_details()
    {
      try {
        if (!$this->primenet_checkout_is_valid_ip()) {
          return wp_send_json(["message" => "Forbidden"], 403);
        }

        $order_id = filter_input(INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT);

        if ($order_id === false || $order_id === null) {
          return wp_send_json(["message" => "order_id is invalid"], 400);
        }

        $order = wc_get_order($order_id);

        if (!isset($order)) {
          return wp_send_json(["message" => "Order not found"], 404);
        }

        $order_details = array(
          'amount' => $order->get_total(),
          'currency' => $order->get_currency(),
        );

        return wp_send_json($order_details);
      } catch (Exception $e) {
        return wp_send_json(["message" => "Something went wrong", "error" => $e], 500);
      }
    }

    // Update order status
    public function primenet_checkout_update_order_status()
    {
      try {
        if (!$this->primenet_checkout_is_valid_ip()) {
          return wp_send_json(["message" => "Forbidden"], 403);
        }

        $order_id = filter_input(INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT);
        $order_status = filter_input(INPUT_GET, 'order_status', FILTER_SANITIZE_SPECIAL_CHARS);

        if ($order_id === false || $order_id === null) {
          return wp_send_json(["message" => "order_id is invalid"], 400);
        }

        if (!isset($order_status) || empty($order_status)) {
          return wp_send_json(["message" => "order_status is required"], 400);
        }

        if (!in_array($order_status, ['completed', 'failed'], strict: true)) {
          return wp_send_json(["message" => "Invalid Data"], 400);
        }

        $order = wc_get_order($order_id);

        if (!isset($order)) {
          return wp_send_json(["message" => "Order not found"], 404);
        }

        $order->update_status($order_status);

        $order->save();

        if ($order_status == "completed") {
          WC()->cart->empty_cart();
        }

        // Output JSON response
        return wp_send_json(array('success' => true));
      } catch (Exception $e) {
        return wp_send_json(["message" => "Something went wrong", "error" => $e], 500);
      }
    }

    public function primenet_checkout_is_valid_ip()
    {
      $allowed_ip = '34.240.193.187';
      $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : "";

      if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
        $ip = trim(end($ips));
      }

      return $ip === $allowed_ip;
    }
  }

  /**
   * Add the Gateway to WooCommerce
   **/
  function add_primenet_checkout_to_woocommerce($methods)
  {
    $methods[] = 'PrimenetCheckout';
    return $methods;
  }

  function primenet_checkout_settings_link($actions)
  {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=primenet_checkout') . '">Settings</a>';
    return array_merge(array($settings_link), $actions);
  }

  add_filter('woocommerce_payment_gateways', 'add_primenet_checkout_to_woocommerce');

  add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'primenet_checkout_settings_link');
}
