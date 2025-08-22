<?php
/*
Plugin Name: WooCommerce Product Launch Date
Description: Adds a launch date and time field to WooCommerce products and allows its use in other elements.
Version: 1.1
Author: Carlos Murillo
Text Domain: wc-launch-date
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add launch date and time field to product admin
add_action('woocommerce_product_options_general_product_data', 'wc_launch_date_add_field');
function wc_launch_date_add_field() {
    woocommerce_wp_text_input(
        array(
            'id' => '_launch_date',
            'label' => __('Launch Date and Time', 'wc-launch-date'),
            'type' => 'datetime-local',
            'desc_tip' => true,
            'description' => __('Set the launch date and time for this product.', 'wc-launch-date'),
        )
    );
}

// Save launch date and time field
add_action('woocommerce_process_product_meta', 'wc_launch_date_save_field');
function wc_launch_date_save_field($post_id) {
    $launch_date = isset($_POST['_launch_date']) ? sanitize_text_field($_POST['_launch_date']) : '';
    update_post_meta($post_id, '_launch_date', $launch_date);
}

// Display launch date and time on single product page
add_action('woocommerce_single_product_summary', 'wc_launch_date_display', 25);
function wc_launch_date_display() {
    global $product;
    $launch_date = get_post_meta($product->get_id(), '_launch_date', true);
    if (!empty($launch_date)) {
        echo '<p class="launch-date">' . __('Launch Date: ', 'wc-launch-date') . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($launch_date))) . '</p>';
    }
}

// Shortcode to display launch date and time
add_shortcode('wc_product_launch_date', 'wc_launch_date_shortcode');
function wc_launch_date_shortcode($atts) {
    $atts = shortcode_atts(array('product_id' => 0), $atts, 'wc_product_launch_date');
    $product_id = $atts['product_id'] ? $atts['product_id'] : get_the_ID();
    $launch_date = get_post_meta($product_id, '_launch_date', true);
    if (!empty($launch_date)) {
        return '<span class="wc-launch-date">' . __('Launch Date: ', 'wc-launch-date') . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($launch_date))) . '</span>';
    }
    return '';
}

// Filter to access launch date and time in other elements
add_filter('wc_launch_date_get', 'wc_launch_date_get_filter', 10, 2);
function wc_launch_date_get_filter($value, $product_id) {
    return get_post_meta($product_id, '_launch_date', true);
}
?>
