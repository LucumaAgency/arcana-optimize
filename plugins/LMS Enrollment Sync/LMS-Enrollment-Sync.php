<?php
/**
 * Plugin Name: LMS Enrollment Sync
 * Description: Automatically enrolls users in MasterStudy LMS courses based on WooCommerce product purchases, using Course Product Manager relationships.
 * Version: 2.0.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 * Text Domain: lms-enrollment-sync
 */

/**
 * Prevent direct access to the file
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check plugin dependencies
 */
add_action('admin_init', function () {
    if (!class_exists('WooCommerce') || !defined('STM_LMS_FILE')) {
        error_log('LMS Enrollment Sync: Missing dependencies (WooCommerce or MasterStudy LMS not active)');
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><strong>LMS Enrollment Sync Error:</strong> Both WooCommerce and MasterStudy LMS are required. Please ensure they are installed and activated.</p>
            </div>
            <?php
        });
        // Deactivate plugin if dependencies are missing
        deactivate_plugins(plugin_basename(__FILE__));
    }
});

/**
 * Load plugin text domain for translations
 */
add_action('init', function () {
    load_plugin_textdomain('lms-enrollment-sync', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}, 5);

/**
 * Get product-to-course mappings using Course Product Manager relationships
 * Now supports any product type that has a related_stm_course_id meta
 */
function lms_enrollment_sync_get_mappings() {
    $mappings = [];
    
    // Method 1: Get all products that have a related_stm_course_id meta
    // This catches ALL product types managed by Course Product Manager
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => 'related_stm_course_id',
                'compare' => 'EXISTS'
            ]
        ]
    ];
    
    $products = get_posts($args);
    
    if (!empty($products)) {
        foreach ($products as $product_post) {
            $course_id = get_post_meta($product_post->ID, 'related_stm_course_id', true);
            
            if ($course_id && is_numeric($course_id)) {
                // Verify the course exists and is published
                $course = get_post($course_id);
                if ($course && $course->post_type === 'stm-courses' && $course->post_status === 'publish') {
                    $mappings[] = [
                        'product_id' => absint($product_post->ID),
                        'course_id' => absint($course_id),
                    ];
                    error_log('LMS Enrollment Sync: Mapping found - Product ID ' . $product_post->ID . ' (' . $product_post->post_title . ') to Course ID ' . $course_id);
                } else {
                    error_log('LMS Enrollment Sync: Invalid or unpublished course ID ' . $course_id . ' for product ID ' . $product_post->ID);
                }
            }
        }
    }
    
    // Method 2: Also check from courses side for backward compatibility
    // This ensures we don't miss any relationships
    $courses_args = [
        'post_type' => 'stm-courses',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];

    $courses = get_posts($courses_args);
    
    if (!empty($courses)) {
        foreach ($courses as $course_id) {
            // Get related course product
            $course_product_id = get_post_meta($course_id, 'related_course_product_id', true);
            if ($course_product_id && is_numeric($course_product_id)) {
                // Check if not already in mappings
                $already_mapped = false;
                foreach ($mappings as $mapping) {
                    if ($mapping['product_id'] == $course_product_id && $mapping['course_id'] == $course_id) {
                        $already_mapped = true;
                        break;
                    }
                }
                
                if (!$already_mapped) {
                    $product = wc_get_product($course_product_id);
                    if ($product && $product->get_status() === 'publish') {
                        $mappings[] = [
                            'product_id' => absint($course_product_id),
                            'course_id' => absint($course_id),
                        ];
                        error_log('LMS Enrollment Sync: Additional mapping found - Course Product ID ' . $course_product_id . ' to Course ID ' . $course_id);
                    }
                }
            }

            // Get related webinar product
            $webinar_product_id = get_post_meta($course_id, 'related_webinar_product_id', true);
            if ($webinar_product_id && is_numeric($webinar_product_id)) {
                // Check if not already in mappings
                $already_mapped = false;
                foreach ($mappings as $mapping) {
                    if ($mapping['product_id'] == $webinar_product_id && $mapping['course_id'] == $course_id) {
                        $already_mapped = true;
                        break;
                    }
                }
                
                if (!$already_mapped) {
                    $product = wc_get_product($webinar_product_id);
                    if ($product && $product->get_status() === 'publish') {
                        $mappings[] = [
                            'product_id' => absint($webinar_product_id),
                            'course_id' => absint($course_id),
                        ];
                        error_log('LMS Enrollment Sync: Additional mapping found - Webinar Product ID ' . $webinar_product_id . ' to Course ID ' . $course_id);
                    }
                }
            }
        }
    }

    error_log('LMS Enrollment Sync: Retrieved ' . count($mappings) . ' total product-to-course mappings.');
    return $mappings;
}

/**
 * Enroll user in course upon WooCommerce order completion
 */
add_action('woocommerce_order_status_completed', 'lms_enrollment_sync_enroll_user', 10, 1);
function lms_enrollment_sync_enroll_user($order_id) {
    error_log('=== LMS Enrollment Sync: Processing order ===');
    error_log('Order ID: ' . $order_id);

    // Load order
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('Error: Failed to load order ID ' . $order_id);
        return;
    }
    error_log('Order loaded successfully.');

    // Get user ID
    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log('Error: No user associated with order ID ' . $order_id);
        return;
    }
    error_log('User ID: ' . $user_id);

    // Get product-to-course mappings
    $mappings = lms_enrollment_sync_get_mappings();
    if (empty($mappings)) {
        error_log('Error: No product-to-course mappings available.');
        return;
    }
    error_log('Found ' . count($mappings) . ' product-to-course mappings.');

    // Get order items
    $items = $order->get_items();
    if (empty($items)) {
        error_log('Error: No items found in order ID ' . $order_id);
        return;
    }
    error_log('Number of items in order: ' . count($items));

    // Check for matching products
    $enrolled_courses = [];
    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        error_log('Checking product ID ' . $product_id);

        foreach ($mappings as $mapping) {
            if ($product_id == $mapping['product_id']) {
                $course_id = $mapping['course_id'];
                $product_name = $item->get_name();
                error_log('Match found: Product ID ' . $product_id . ' (' . $product_name . ') mapped to Course ID ' . $course_id);

                // Verify course exists
                $course = get_post($course_id);
                if (!$course || $course->post_type !== 'stm-courses' || $course->post_status !== 'publish') {
                    error_log('Error: Invalid or unpublished course ID ' . $course_id);
                    continue;
                }

                // Check if function exists
                if (!function_exists('stm_lms_add_user_course')) {
                    error_log('Error: stm_lms_add_user_course function not available. Is MasterStudy LMS active?');
                    continue;
                }
                error_log('Function stm_lms_add_user_course found.');

                // Check if user is already enrolled
                global $wpdb;
                $table = $wpdb->prefix . 'stm_lms_user_courses';
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE user_id = %d AND course_id = %d",
                    $user_id,
                    $course_id
                ));

                if ($existing > 0) {
                    error_log('User ID ' . $user_id . ' already enrolled in course ID ' . $course_id);
                    continue;
                }

                // Enroll user
                $data = [
                    'user_id' => $user_id,
                    'course_id' => $course_id,
                    'progress_percent' => 0,
                    'start_time' => current_time('mysql'),
                    'status' => 'enrolled'
                ];

                $result = $wpdb->insert($table, $data, ['%d', '%d', '%d', '%s', '%s']);
                if ($result === false) {
                    error_log('Error enrolling user ID ' . $user_id . ' in course ID ' . $course_id . ': ' . $wpdb->last_error);
                } else {
                    error_log('Success: User ID ' . $user_id . ' enrolled in course ID ' . $course_id);
                    $enrolled_courses[] = $course_id;
                }
            }
        }
    }

    if (empty($enrolled_courses)) {
        error_log('No matching products found for enrollment in order ID ' . $order_id);
    } else {
        error_log('Enrollment complete for order ID ' . $order_id . '. Enrolled in courses: ' . implode(', ', $enrolled_courses));
    }
}

/**
 * Log activation for debugging
 */
register_activation_hook(__FILE__, function () {
    error_log('LMS Enrollment Sync: Plugin activated.');
});
?>
