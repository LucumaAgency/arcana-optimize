<?php
/*
 * Plugin Name: Course Utilities
 * Description: Provides reusable utility functions for other course-related plugins.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Helper: Convert an image URL to an attachment ID
function get_attachment_id_from_url($image_url) {
    global $wpdb;
    $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s' LIMIT 1;", $image_url));
    return !empty($attachment) ? $attachment[0] : 0;
}

// Helper: Get the stm-courses ID related to a course page ID
function get_related_stm_course_id($course_page_id) {
    $cache_key = 'stm_course_id_' . $course_page_id;
    $stm_course_id = wp_cache_get($cache_key, 'course_utilities');
    if (false !== $stm_course_id) {
        return $stm_course_id;
    }

    $args = [
        'post_type' => 'stm-courses',
        'posts_per_page' => 1,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => 'related_course_id',
                'value' => $course_page_id,
                'compare' => '=',
            ],
        ],
        'fields' => 'ids',
    ];
    $stm_courses = get_posts($args);

    $stm_course_id = !empty($stm_courses) ? $stm_courses[0] : 0;
    wp_cache_set($cache_key, $stm_course_id, 'course_utilities', HOUR_IN_SECONDS);

    return $stm_course_id;
}

// Helper: Get cached ACF field value
function get_cached_acf_field($field_key, $post_id) {
    $cache_key = "acf_{$field_key}_{$post_id}";
    $value = wp_cache_get($cache_key, 'acf_fields');
    if (false === $value) {
        $value = get_field($field_key, $post_id);
        wp_cache_set($cache_key, $value, 'acf_fields', HOUR_IN_SECONDS);
    }
    return $value;
}

// Helper: Get instructor photo URL by WordPress user ID
function get_instructor_photo_url($user_id) {
    $user_id = absint($user_id);
    if (!$user_id) {
        return '';
    }

    $cache_key = 'instructor_photo_' . $user_id;
    $photo_url = wp_cache_get($cache_key, 'course_utilities');
    if (false !== $photo_url) {
        return esc_url($photo_url);
    }

    $base_upload_dir = wp_upload_dir()['baseurl'];
    $photo_path = "/stm_lms_avatars/stm_lms_avatar{$user_id}.jpg";
    $photo_url = $base_upload_dir . $photo_path;

    // Check if the file exists
    $response = wp_remote_head($photo_url, ['timeout' => 5]);
    if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
        wp_cache_set($cache_key, $photo_url, 'course_utilities', DAY_IN_SECONDS);
        return esc_url($photo_url);
    }

    // Fallback to provided Gravatar URL
    $fallback_url = 'https://secure.gravatar.com/avatar/960ae940db3ec6809086442871c87a389e05b3da89bc95b29d6202c14b036c2b?s=200&d=mm&r=g';
    wp_cache_set($cache_key, $fallback_url, 'course_utilities', DAY_IN_SECONDS);
    return esc_url($fallback_url);
}
?>
