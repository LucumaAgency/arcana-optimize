<?php
/*
 * Plugin Name: Course Management MVP
 * Description: Manages the creation and updating of course pages and associated products, avoiding duplicates.
 * Version: 1.6.4
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Define default image ID for fallback
define('COURSE_MGMT_DEFAULT_IMAGE_ID', 123); // Replace with actual attachment ID

// Fix early translation loading for ACF and WooCommerce Gateway Stripe
add_action('plugins_loaded', function() {
    if (function_exists('acf_load_textdomain')) {
        remove_action('plugins_loaded', 'acf_load_textdomain', 1);
        remove_action('plugins_loaded', 'acf_load_textdomain', 10);
        add_action('init', 'acf_load_textdomain', 5);
        error_log('ACF textdomain loading delayed to init');
    }
    if (function_exists('wc_stripe_load_plugin_textdomain')) {
        remove_action('plugins_loaded', 'wc_stripe_load_plugin_textdomain', 0);
        add_action('init', 'wc_stripe_load_plugin_textdomain', 5);
        error_log('WooCommerce Gateway Stripe textdomain loading delayed to init');
    }
}, 0);

// Include utilities if available
add_action('init', function() {
    $utilities_path = WP_PLUGIN_DIR . '/course-utilities/course-utilities.php';
    if (file_exists($utilities_path)) {
        require_once $utilities_path;
    } else {
        error_log('Course Utilities plugin not found at ' . $utilities_path);
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>Course Management Error:</strong> The Course Utilities plugin is required but was not found. Please ensure it is installed and activated.</p>
            </div>
            <?php
        });
    }
});

/**
 * Sanitize course title by removing parentheses and other unwanted characters
 *
 * @param string $title The raw course title
 * @return string The sanitized course title
 */
function sanitize_course_title($title) {
    $sanitized = preg_replace('/\([^)]+\)/', '', $title);
    $sanitized = trim(preg_replace('/\s+/', ' ', $sanitized));
    $sanitized = sanitize_text_field($sanitized);
    error_log('Sanitized course title: Raw="' . $title . '" => Sanitized="' . $sanitized . '"');
    return $sanitized;
}

/**
 * Register custom REST API endpoint for course data
 */
add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/courses/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => function(WP_REST_Request $request) {
            $course_id = $request->get_param('id');
            $course = get_post($course_id);

            if (!$course || $course->post_type !== 'stm-courses' || $course->post_status !== 'publish') {
                error_log('Invalid course ID ' . $course_id . ' or not an stm-courses post');
                return new WP_Error('invalid_course', 'Course not found or invalid', ['status' => 404]);
            }

            $content = wp_kses_post($course->post_content);
            error_log('Course ID: ' . $course_id . ' | Post content length: ' . strlen($content));

            $meta = get_post_meta($course_id);
            $price = floatval($meta['price'][0] ?? 0);
            $instructor = '';
            $instructor_photo = '';

            $instructor_id = $course->post_author;
            error_log('Course ID: ' . $course_id . ' | Instructor ID (post_author): ' . $instructor_id);

            if ($instructor_id && is_numeric($instructor_id)) {
                $user = get_userdata($instructor_id);
                if ($user) {
                    $instructor = $user->display_name ?: 'Unknown Instructor';
                    error_log('Instructor found: ' . $instructor);

                    $instructor_photo_url = "https://academy.arcanalabs.ai/wp-content/uploads/stm_lms_avatars/stm_lms_avatar{$instructor_id}.jpg";
                    error_log('Instructor photo URL constructed: ' . $instructor_photo_url);

                    $instructor_photo_id = function_exists('get_attachment_id_from_url') ? get_attachment_id_from_url($instructor_photo_url) : 0;
                    $instructor_photo = $instructor_photo_id ?: $instructor_photo_url;
                    error_log($instructor_photo_id ? 'Instructor photo ID: ' . $instructor_photo_id : 'No photo ID, using URL: ' . $instructor_photo_url);
                } else {
                    error_log('No user found for instructor_id: ' . $instructor_id);
                }
            } else {
                error_log('Invalid or missing instructor_id for course_id: ' . $course_id);
            }

            if (empty($instructor) && !empty($content)) {
                if (preg_match('/class="masterstudy-single-course-instructor__name[^"]*"\s*href="[^"]*"\s*[^>]*>(.*?)</s', $content, $match)) {
                    $instructor = trim(strip_tags($match[1])) ?: 'Unknown Instructor';
                    error_log('Instructor extracted from HTML: ' . $instructor);
                } else {
                    $instructor = 'Unknown Instructor';
                    error_log('No instructor found in HTML for course_id: ' . $course_id);
                }
            }

            if (empty($instructor_photo)) {
                if (preg_match('/class="masterstudy-single-course-instructor__avatar[^"]*".*?src=["\'](.*?)["\']/', $content, $match_photo)) {
                    $instructor_photo = trim($match_photo[1]);
                    error_log('Avatar extracted from HTML: ' . $instructor_photo);
                } else {
                    error_log('No avatar found in HTML for course_id: ' . $course_id);
                }
            }

            $categories = wp_get_post_terms($course_id, 'stm_lms_course_taxonomy', ['fields' => 'names']) ?: [];

            $data = [
                'title' => $course->post_title,
                'content' => apply_filters('the_content', $course->post_content),
                'permalink' => get_permalink($course_id),
                'price' => $price,
                'instructor' => $instructor,
                'instructor_photo' => $instructor_photo,
                'categories' => $categories,
                'students' => absint($meta['current_students'][0] ?? 0),
                'views' => absint($meta['views'][0] ?? 0),
            ];

            error_log('Endpoint data for course_id ' . $course_id . ': ' . wp_json_encode(array_slice($data, 0, 100)));
            return $data;
        },
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Retrieve JSON data from the custom endpoint or directly from post data
 */
function get_course_json_data($course_id) {
    error_log('Fetching JSON data for course_id: ' . $course_id);

    $max_retries = 2;
    $attempt = 0;
    $json = false;

    while ($attempt <= $max_retries && !$json) {
        $attempt++;
        error_log('Attempt ' . $attempt . ' to fetch JSON data for course_id: ' . $course_id);
        $response = wp_remote_get(
            home_url("/wp-json/custom/v1/courses/{$course_id}"),
            [
                'timeout' => 15,
                'sslverify' => false,
                'headers' => ['Cache-Control' => 'no-cache'],
            ]
        );

        if (is_wp_error($response)) {
            error_log('WP remote get error for course_id ' . $course_id . ', attempt ' . $attempt . ': ' . $response->get_error_message());
            if ($attempt <= $max_retries) {
                sleep(1);
                continue;
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        error_log('HTTP response code for course_id ' . $course_id . ': ' . $response_code);
        error_log('Raw response body for course_id ' . $course_id . ': ' . substr($response_body, 0, 500) . '...');

        if ($response_code !== 200) {
            error_log('Non-200 response for course_id ' . $course_id . ', attempt ' . $attempt . ': ' . $response_code);
            if ($attempt <= $max_retries) {
                sleep(1);
                continue;
            }
            return false;
        }

        $json = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error for course_id ' . $course_id . ', attempt ' . $attempt . ': ' . json_last_error_msg());
            $json = false;
            if ($attempt <= $max_retries) {
                sleep(1);
                continue;
            }
            return false;
        }

        if (!is_array($json) || empty($json['title'])) {
            error_log('Invalid or empty JSON data for course_id ' . $course_id . ', attempt ' . $attempt . ': ' . print_r($json, true));
            $json = false;
            if ($attempt <= $max_retries) {
                sleep(1);
                continue;
            }
        }
    }

    if (!$json || !is_array($json) || empty($json['title'])) {
        error_log('REST API failed after ' . $max_retries . ' attempts for course_id: ' . $course_id . ', falling back to direct post data');
        $course = get_post($course_id);
        if (!$course || $course->post_type !== 'stm-courses' || $course->post_status !== 'publish') {
            error_log('Invalid course ID ' . $course_id . ' or not an stm-courses post in fallback');
            return false;
        }

        $meta = get_post_meta($course_id);
        $instructor = '';
        $instructor_photo = '';
        $instructor_id = $course->post_author;

        if ($instructor_id && is_numeric($instructor_id)) {
            $user = get_userdata($instructor_id);
            $instructor = $user ? ($user->display_name ?: 'Unknown Instructor') : 'Unknown Instructor';
            $instructor_photo_url = "https://academy.arcanalabs.ai/wp-content/uploads/stm_lms_avatars/stm_lms_avatar{$instructor_id}.jpg";
            $instructor_photo_id = function_exists('get_attachment_id_from_url') ? get_attachment_id_from_url($instructor_photo_url) : 0;
            $instructor_photo = $instructor_photo_id ?: $instructor_photo_url;
        } else {
            $instructor = 'Unknown Instructor';
        }

        $content = wp_kses_post($course->post_content);
        if (empty($instructor) && !empty($content)) {
            if (preg_match('/class="masterstudy-single-course-instructor__name[^"]*"\s*href="[^"]*"\s*[^>]*>(.*?)</s', $content, $match)) {
                $instructor = trim(strip_tags($match[1])) ?: 'Unknown Instructor';
            }
        }
        if (empty($instructor_photo)) {
            if (preg_match('/class="masterstudy-single-course-instructor__avatar[^"]*".*?src=["\'](.*?)["\']/', $content, $match_photo)) {
                $instructor_photo = trim($match_photo[1]);
            }
        }

        $categories = wp_get_post_terms($course_id, 'stm_lms_course_taxonomy', ['fields' => 'names']) ?: [];

        $json = [
            'title' => $course->post_title,
            'content' => apply_filters('the_content', $course->post_content),
            'permalink' => get_permalink($course_id),
            'price' => floatval($meta['price'][0] ?? 0),
            'instructor' => $instructor,
            'instructor_photo' => $instructor_photo,
            'categories' => $categories,
            'students' => absint($meta['current_students'][0] ?? 0),
            'views' => absint($meta['views'][0] ?? 0),
            'background_image' => 0,
        ];
        error_log('Fallback JSON data for course_id ' . $course_id . ': ' . wp_json_encode($json));
    }

    $allowed_tags = [
        'h3' => ['class' => []],
        'p' => [],
        'ul' => [],
        'li' => [],
        'strong' => [],
        'br' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'span' => ['class' => []],
    ];

    $content = isset($json['content']) ? wp_kses($json['content'], $allowed_tags) : '';
    error_log('Sanitized content for course_id ' . $course_id . ': ' . substr($content, 0, 200) . '...');

    $result = [
        'title' => isset($json['title']) ? sanitize_text_field($json['title']) : '',
        'content' => $content,
        'permalink' => isset($json['permalink']) ? esc_url($json['permalink']) : '',
        'price' => isset($json['price']) ? floatval($json['price']) : 0,
        'instructor' => isset($json['instructor']) && !empty($json['instructor']) ? sanitize_text_field($json['instructor']) : 'Unknown Instructor',
        'instructor_photo' => isset($json['instructor_photo']) ? sanitize_text_field($json['instructor_photo']) : '',
        'categories' => isset($json['categories']) && is_array($json['categories']) ? array_map('sanitize_text_field', $json['categories']) : [],
        'students' => isset($json['students']) ? absint($json['students']) : 0,
        'views' => isset($json['views']) ? absint($json['views']) : 0,
        'background_image' => 0,
    ];

    error_log('Final JSON data returned for course_id ' . $course_id . ': ' . wp_json_encode($result));
    return $result;
}

/**
 * Retrieve the background image ID from the ACF field or use the featured image
 */
function get_background_image_from_course_page($course_page_id, $stm_course_id) {
    if (!function_exists('get_field')) {
        error_log('ACF get_field not available for course_page_id: ' . $course_page_id);
        return COURSE_MGMT_DEFAULT_IMAGE_ID;
    }

    $background_image_id = function_exists('get_cached_acf_field') ? get_cached_acf_field('field_682187522193c', $course_page_id) : get_field('field_682187522193c', $course_page_id);
    if ($background_image_id && is_numeric($background_image_id)) {
        return $background_image_id;
    }

    $raw_value = get_post_meta($course_page_id, 'course_background_image', true);
    if ($raw_value && filter_var($raw_value, FILTER_VALIDATE_URL)) {
        $background_image_id = function_exists('get_attachment_id_from_url') ? get_attachment_id_from_url($raw_value) : 0;
        if ($background_image_id) {
            update_field('field_682187522193c', $background_image_id, $course_page_id);
            return $background_image_id;
        }
    }

    $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    $background_image_id = $thumbnail_id ?: COURSE_MGMT_DEFAULT_IMAGE_ID;
    update_field('field_682187522193c', $background_image_id, $course_page_id);
    return $background_image_id;
}

/**
 * Create or update a course page and associated products
 */
function create_or_update_course_page($stm_course_id, $json) {
    error_log('Starting create_or_update_course_page for stm_course_id: ' . $stm_course_id);
    wp_cache_flush();
    clean_post_cache($stm_course_id);

    if (!post_type_exists('course')) {
        error_log('Course post type not registered for stm_course_id: ' . $stm_course_id);
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>Course Management Error:</strong> The 'course' post type is not registered. Please ensure it is properly set up.</p>
            </div>
            <?php
        });
        return false;
    }

    if (!current_user_can('publish_posts', 'course')) {
        error_log('User lacks permission to publish course posts for stm_course_id: ' . $stm_course_id . ', user_id: ' . get_current_user_id());
        return false;
    }

    $course_page_id = get_post_meta($stm_course_id, 'related_course_id', true);
    error_log('Checking existing course page for stm_course_id: ' . $stm_course_id . ', related_course_id: ' . ($course_page_id ?: 'None'));
    if ($course_page_id) {
        $post = get_post($course_page_id);
        if ($post && $post->post_type === 'course' && $post->post_status === 'publish') {
            error_log('Using existing course page ID: ' . $course_page_id);
        } else {
            error_log('Existing related_course_id ' . $course_page_id . ' is invalid or not published, creating new page');
            $course_page_id = 0;
        }
    } else {
        error_log('No existing related_course_id found, creating new page');
        $course_page_id = 0;
    }

    $raw_title = $json['title'] ?: "Course {$stm_course_id}";
    $sanitized_title = sanitize_course_title($raw_title);
    $slug = isset($json['permalink']) ? basename($json['permalink']) : '';
    $slug = sanitize_title($sanitized_title);

    $course_page_data = [
        'post_title' => $sanitized_title,
        'post_name' => $slug,
        'post_type' => 'course',
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ];

    if ($course_page_id) {
        $course_page_data['ID'] = $course_page_id;
        error_log('Attempting to update course page ID: ' . $course_page_id . ', Title: ' . $sanitized_title);
        $course_page_id = wp_update_post($course_page_data, true);
        error_log('Updated course page ID: ' . (is_wp_error($course_page_id) ? 'Error: ' . $course_page_id->get_error_message() : $course_page_id));
    } else {
        error_log('Attempting to insert new course page, Title: ' . $sanitized_title);
        $course_page_id = wp_insert_post($course_page_data, true);
        error_log('Inserted course page ID: ' . (is_wp_error($course_page_id) ? 'Error: ' . $course_page_id->get_error_message() : $course_page_id));
        if ($course_page_id && !is_wp_error($course_page_id)) {
            update_post_meta($stm_course_id, 'related_course_id', $course_page_id);
            update_post_meta($course_page_id, 'related_stm_course_id', $stm_course_id);
        }
    }

    if (!$course_page_id || is_wp_error($course_page_id)) {
        error_log('Failed to create/update course page for stm_course_id: ' . $stm_course_id . ', Error: ' . (is_wp_error($course_page_id) ? $course_page_id->get_error_message() : 'Unknown'));
        return false;
    }

    if (!function_exists('update_field')) {
        error_log('ACF plugin not found for course_page_id: ' . $course_page_id);
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>Course Management Error:</strong> Advanced Custom Fields (ACF) plugin is required but not loaded. Please ensure it is installed and activated.</p>
            </div>
            <?php
        });
        return false;
    }

    $field_group = acf_get_field_group('group_681ccc3e039b0');
    if (!$field_group || !acf_get_field_group_visibility($field_group, ['post_id' => $course_page_id])) {
        error_log("Field group group_681ccc3e039b0 not assigned or not visible for course_page_id: $course_page_id");
        return $course_page_id;
    }

    $stm_course = get_post($stm_course_id);
    if ($stm_course && $stm_course->post_type === 'stm-courses') {
        $thumbnail_id = get_post_thumbnail_id($stm_course_id);
        if ($thumbnail_id) {
            set_post_thumbnail($course_page_id, $thumbnail_id);
            error_log('Set thumbnail ID ' . $thumbnail_id . ' for course_page_id: ' . $course_page_id);
        } else {
            set_post_thumbnail($course_page_id, COURSE_MGMT_DEFAULT_IMAGE_ID);
            error_log('Set default thumbnail ID ' . COURSE_MGMT_DEFAULT_IMAGE_ID . ' for course_page_id: ' . $course_page_id);
        }
    } else {
        error_log('Invalid stm_course for ID: ' . $stm_course_id);
    }

    if (class_exists('WooCommerce')) {
        $course_product_title = $sanitized_title;

        $course_product_id = get_post_meta($stm_course_id, 'related_course_product_id', true);
        if ($course_product_id) {
            $post = get_post($course_product_id);
            if ($post && $post->post_type === 'product' && $post->post_status === 'publish') {
                $course_product = [
                    'ID' => $course_product_id,
                    'post_title' => $course_product_title,
                    'post_status' => 'publish',
                    'post_author' => get_current_user_id(),
                ];
                error_log('Attempting to update course product ID: ' . $course_product_id . ', Title: ' . $course_product_title);
                $course_product_id = wp_update_post($course_product, true);
                if (is_wp_error($course_product_id)) {
                    error_log('Failed to update course product ID: ' . $course_product_id . ', Error: ' . $course_product_id->get_error_message());
                    $course_product_id = false;
                } else {
                    error_log('Updated existing course product ID: ' . $course_product_id);
                }
            } else {
                error_log('Invalid or non-existent course product ID: ' . $course_product_id . ', creating new product');
                $course_product_id = false;
            }
        }

        if (!$course_product_id) {
            $course_product = [
                'post_title' => $course_product_title,
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ];
            error_log('Attempting to create new course product, Title: ' . $course_product_title);
            $course_product_id = wp_insert_post($course_product, true);
            if (is_wp_error($course_product_id)) {
                error_log('Failed to create course product: ' . $course_product_id->get_error_message());
            } else {
                error_log('Created new course product ID: ' . $course_product_id);
                update_post_meta($stm_course_id, 'related_course_product_id', $course_product_id);
                update_post_meta($course_product_id, 'related_stm_course_id', $stm_course_id);
            }
        }

        if ($course_product_id && !is_wp_error($course_product_id)) {
            wp_set_object_terms($course_product_id, 'simple', 'product_type');
            update_post_meta($course_product_id, '_visibility', 'visible');
            update_post_meta($course_product_id, '_stock_status', 'instock');
            update_post_meta($course_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($course_product_id, '_regular_price', $json['price'] ?? 0);
            update_post_meta($course_product_id, 'related_stm_course_id', $stm_course_id);
            update_post_meta($stm_course_id, 'related_course_product_id', $course_product_id);
            $course_product_link = home_url("/?add-to-cart={$course_product_id}&quantity=1");
            update_field('field_6821879221940', $course_product_link, $course_page_id);
            error_log('Updated course product meta and ACF field for course_page_id: ' . $course_page_id);
        }

        $webinar_product_id = get_post_meta($stm_course_id, 'related_webinar_product_id', true);
        $webinar_product_title = 'Webinar - ' . $sanitized_title;
        if ($webinar_product_id) {
            $post = get_post($webinar_product_id);
            if ($post && $post->post_type === 'product' && $post->post_status === 'publish') {
                $webinar_product = [
                    'ID' => $webinar_product_id,
                    'post_title' => $webinar_product_title,
                    'post_status' => 'publish',
                    'post_author' => get_current_user_id(),
                ];
                error_log('Attempting to update webinar product ID: ' . $webinar_product_id . ', Title: ' . $webinar_product_title);
                $webinar_product_id = wp_update_post($webinar_product, true);
                if (is_wp_error($webinar_product_id)) {
                    error_log('Failed to update webinar product ID: ' . $webinar_product_id . ', Error: ' . $webinar_product_id->get_error_message());
                    $webinar_product_id = false;
                } else {
                    error_log('Updated existing webinar product ID: ' . $webinar_product_id);
                }
            } else {
                error_log('Invalid or non-existent webinar product ID: ' . $webinar_product_id . ', creating new product');
                $webinar_product_id = false;
            }
        }

        if (!$webinar_product_id) {
            $webinar_product = [
                'post_title' => $webinar_product_title,
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_author' => get_current_user_id(),
            ];
            error_log('Attempting to create new webinar product, Title: ' . $webinar_product_title);
            $webinar_product_id = wp_insert_post($webinar_product, true);
            if (is_wp_error($webinar_product_id)) {
                error_log('Failed to create webinar product: ' . $webinar_product_id->get_error_message());
            } else {
                error_log('Created new webinar product ID: ' . $webinar_product_id);
                update_post_meta($stm_course_id, 'related_webinar_product_id', $webinar_product_id);
                update_post_meta($webinar_product_id, 'related_stm_course_id', $stm_course_id);
            }
        }

        if ($webinar_product_id && !is_wp_error($webinar_product_id)) {
            wp_set_object_terms($webinar_product_id, 'simple', 'product_type');
            update_post_meta($webinar_product_id, '_visibility', 'visible');
            update_post_meta($webinar_product_id, '_stock_status', 'instock');
            update_post_meta($webinar_product_id, '_price', $json['price'] ?? 0);
            update_post_meta($webinar_product_id, '_regular_price', $json['price'] ?? 0);
            update_post_meta($webinar_product_id, 'related_stm_course_id', $stm_course_id);
            update_post_meta($stm_course_id, 'related_webinar_product_id', $webinar_product_id);
            $webinar_product_link = home_url("/?add-to-cart={$webinar_product_id}&quantity=1");
            update_field('field_6821879e21941', $webinar_product_link, $course_page_id);
            error_log('Updated webinar product meta and ACF field for course_page_id: ' . $course_page_id);
        }
    }

    $json['background_image'] = get_background_image_from_course_page($course_page_id, $stm_course_id);

    $acf_updates = [
        'field_681ccc5ab1238' => $sanitized_title,
        'field_682187522193c' => isset($json['background_image']) ? absint($json['background_image']) : 0,
        'field_681ccc66b1239' => isset($json['content']) ? wp_kses_post($json['content']) : '',
        'field_681ccc6eb123a' => isset($json['price']) ? sanitize_text_field($json['price']) : '',
        'field_681ccc7eb123b' => isset($json['instructor']) && !empty($json['instructor']) ? sanitize_text_field($json['instructor']) : 'Unknown Instructor',
        'field_681ccc91b123d' => isset($json['categories']) && is_array($json['categories']) && !empty($json['categories']) ? sanitize_text_field($json['categories'][0]) : '',
        'field_681ccc96b123e' => isset($json['students']) ? absint($json['students']) : 0,
        'field_681ccc9db123f' => isset($json['views']) ? absint($json['views']) : 0,
        'field_682187682193d' => '',
        'field_6821877b2193e' => '',
        'field_682187802193f' => '',
    ];

    wp_cache_flush();
    error_log('Flushed WordPress cache before updating ACF fields for course_page_id: ' . $course_page_id);

    foreach ($acf_updates as $field_key => $value) {
        $field_object = get_field_object($field_key, $course_page_id, false, false);
        if (!$field_object) {
            error_log('ACF field ' . $field_key . ' does not exist or is not registered for course_page_id: ' . $course_page_id);
            continue;
        }
        error_log('Attempting to update ACF field ' . $field_key . ' for course_page_id: ' . $course_page_id . ' with value: ' . (is_array($value) ? json_encode($value) : $value));
        wp_cache_delete("acf_{$field_key}_{$course_page_id}", 'acf_fields');
        $result = update_field($field_key, $value, $course_page_id);
        error_log('Updated ACF field ' . $field_key . ' for course_page_id: ' . $course_page_id . ', Result: ' . ($result ? 'Success' : 'Failed'));
    }

    if (isset($acf_updates['field_682187522193c']) && function_exists('wp_set_post_terms')) {
        wp_update_post([
            'ID' => $course_page_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1),
        ]);
        error_log('Updated post_modified for course_page_id: ' . $course_page_id);
    }

    error_log('Course page created/updated successfully: ' . $course_page_id . ', Title: ' . $sanitized_title);
    return $course_page_id;
}

/**
 * Initial creation of pages for all existing stm-courses
 */
function create_initial_course_pages() {
    if (get_transient('course_mgmt_batch_processing')) {
        error_log('Batch processing already in progress');
        wp_send_json_error(['message' => 'Batch processing already in progress'], 429);
    }

    set_transient('course_mgmt_batch_processing', true, MINUTE_IN_SECONDS);
    error_log('Batch processing lock set for 1 minute');

    check_ajax_referer('create_initial_course_pages_nonce', 'nonce');
    error_log('AJAX create_initial_course_pages: Nonce verified successfully');

    if (!current_user_can('manage_options')) {
        delete_transient('course_mgmt_batch_processing');
        error_log('AJAX create_initial_course_pages: Permission denied for user ID ' . get_current_user_id() . ', lock cleared');
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    error_log('Starting create_initial_course_pages');
    $batch_size = 5;
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    error_log('Processing batch with offset: ' . $offset . ', batch_size: ' . $batch_size);

    if (!post_type_exists('stm-courses')) {
        delete_transient('course_mgmt_batch_processing');
        error_log('stm-courses post type not registered, lock cleared');
        wp_send_json_error(['message' => 'stm-courses post type not registered'], 400);
    }

    if (!post_type_exists('course')) {
        delete_transient('course_mgmt_batch_processing');
        error_log('course post type not registered, lock cleared');
        wp_send_json_error(['message' => 'course post type not registered'], 400);
    }

    $args = [
        'post_type' => 'stm-courses',
        'posts_per_page' => $batch_size,
        'post_status' => 'publish',
        'offset' => $offset,
        'fields' => 'ids',
    ];
    $stm_courses = get_posts($args);
    error_log('Retrieved ' . count($stm_courses) . ' stm-courses for batch at offset ' . $offset . ': ' . json_encode($stm_courses));

    if (empty($stm_courses)) {
        delete_transient('course_mgmt_batch_processing');
        error_log('No more stm-courses to process at offset ' . $offset . ', completing batch, lock cleared');
        wp_send_json_success([
            'complete' => true,
            'redirect' => admin_url('edit.php?post_type=course&message=pages_created'),
            'message' => 'No courses found or all processed',
        ]);
    }

    $total_courses = wp_count_posts('stm-courses')->publish;
    error_log('Total publish stm-courses: ' . $total_courses);
    if ($total_courses <= 0) {
        delete_transient('course_mgmt_batch_processing');
        error_log('No publish stm-courses exist, aborting batch process, lock cleared');
        wp_send_json_success([
            'complete' => true,
            'redirect' => admin_url('edit.php?post_type=course&message=pages_created'),
            'message' => 'No publish stm-courses found',
        ]);
    }

    $processed = $offset + count($stm_courses);
    error_log('Processing ' . count($stm_courses) . ' courses, total: ' . $total_courses . ', processed: ' . $processed);

    $successful_creations = 0;
    $failed_creations = 0;
    foreach ($stm_courses as $stm_course_id) {
        error_log('Processing stm_course ID: ' . $stm_course_id);

        $json = get_course_json_data($stm_course_id);
        if ($json && !empty($json['title'])) {
            error_log('JSON data retrieved for stm_course ID ' . $stm_course_id . ': Title="' . $json['title'] . '"');
            $result = create_or_update_course_page($stm_course_id, $json);
            if ($result && !is_wp_error($result)) {
                error_log('create_or_update_course_page for ID ' . $stm_course_id . ': Success (Page ID: ' . $result . ')');
                $successful_creations++;
            } else {
                error_log('create_or_update_course_page for ID ' . $stm_course_id . ': Failed (Result: ' . (is_wp_error($result) ? $result->get_error_message() : 'Unknown error') . ')');
                $failed_creations++;
            }
        } else {
            error_log('Failed to get valid JSON data for stm_course ID: ' . $stm_course_id);
            $failed_creations++;
        }
    }

    delete_transient('course_mgmt_batch_processing');
    error_log('Batch completed: Successful creations=' . $successful_creations . ', Failed creations=' . $failed_creations . ', lock cleared');

    wp_send_json_success([
        'complete' => $processed >= $total_courses,
        'offset' => $offset + $batch_size,
        'progress' => min(100, round(($processed / $total_courses) * 100)),
        'successful_creations' => $successful_creations,
        'failed_creations' => $failed_creations,
        'message' => "Processed $processed of $total_courses courses",
        'redirect' => admin_url('edit.php?post_type=course&message=pages_created'),
    ]);
}
add_action('wp_ajax_create_initial_course_pages', 'create_initial_course_pages');

/**
 * Manual update with ACF dropdown
 */
function update_course_page_on_save($post_id) {
    if (get_post_type($post_id) !== 'stm-courses' || wp_is_post_revision($post_id)) {
        error_log('Skipping update_course_page_on_save: Not an stm-courses post or is revision, post_id: ' . $post_id);
        return;
    }

    if (!function_exists('get_field')) {
        error_log('ACF get_field not available in update_course_page_on_save for post_id: ' . $post_id);
        return;
    }

    $update_action = function_exists('get_cached_acf_field') ? get_cached_acf_field('update_course_page', $post_id) : get_field('update_course_page', $post_id);
    error_log('update_course_page_on_save for post_id: ' . $post_id . ', update_action: ' . ($update_action ?: 'none'));
    if ($update_action !== 'update') {
        return;
    }

    $json = get_course_json_data($post_id);
    if ($json) {
        create_or_update_course_page($post_id, $json);
    } else {
        error_log('No JSON data returned for post_id: ' . $post_id);
    }

    update_field('update_course_page', 'no_update', $post_id);
}
add_action('acf/save_post', 'update_course_page_on_save', 20);

/**
 * Add button in admin to trigger initial creation
 */
function add_create_course_pages_button() {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'stm-courses' || $screen->base !== 'edit') {
        error_log('Not displaying Create Course Pages button: Incorrect screen (post_type: ' . ($screen ? $screen->post_type : 'none') . ', base: ' . ($screen ? $screen->base : 'none') . ')');
        return;
    }

    if (!post_type_exists('stm-courses')) {
        error_log('Not displaying Create Course Pages button: stm-courses post type not registered');
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><strong>Course Management Error:</strong> MasterStudy LMS is required for the Create Course Pages button. Please ensure it is installed and activated.</p>
            </div>
            <?php
        });
        return;
    }

    wp_enqueue_script('jquery');
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            if ($('#create-course-pages-btn').length) {
                console.log('Create Course Pages button already exists, skipping initialization');
                return;
            }

            console.log('Adding Create Course Pages button to DOM');
            $('.wrap h1').after('<a href="#" id="create-course-pages-btn" class="page-title-action">Create Course Pages</a><div id="course-creation-progress" style="margin-top:10px;display:none;">Processing: <span id="progress-percentage">0%</span></div>');

            let isProcessing = false;
            $('#create-course-pages-btn').on('click', function(e) {
                e.preventDefault();
                if (isProcessing) {
                    console.log('Processing already in progress, ignoring click');
                    alert('Processing is already in progress. Please wait.');
                    return;
                }
                console.log('Create Course Pages button clicked');
                if (confirm('Are you sure you want to create Course pages for all courses?')) {
                    console.log('User confirmed, starting batch processing');
                    isProcessing = true;
                    $('#create-course-pages-btn').prop('disabled', true).addClass('disabled');
                    $('#course-creation-progress').show();
                    processCourseBatch(0);
                } else {
                    console.log('User cancelled the operation');
                }
            });

            function processCourseBatch(offset) {
                console.log('Initiating AJAX request for batch with offset: ' + offset);
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'create_initial_course_pages',
                        nonce: '<?php echo esc_js(wp_create_nonce('create_initial_course_pages_nonce')); ?>',
                        offset: offset
                    },
                    success: function(response) {
                        console.log('AJAX response received for offset ' + offset + ': ', response);
                        if (response.success) {
                            if (response.data.complete) {
                                console.log('Batch processing complete, redirecting to: ' + response.data.redirect);
                                $('#progress-percentage').text('100%');
                                if (response.data.redirect) {
                                    alert('Pages created. Redirecting...');
                                    window.location.href = response.data.redirect;
                                } else {
                                    console.error('Redirect URL missing in response');
                                    $('#course-creation-progress').hide();
                                    $('#create-course-pages-btn').prop('disabled', false).removeClass('disabled');
                                    isProcessing = false;
                                    alert('Error: No redirect URL provided.');
                                }
                            } else {
                                console.log('Batch processed, progress: ' + response.data.progress + '%, next offset: ' + response.data.offset);
                                $('#progress-percentage').text(response.data.progress + '%');
                                setTimeout(function() {
                                    processCourseBatch(response.data.offset);
                                }, 1000);
                            }
                        } else {
                            console.error('AJAX error response for offset ' + offset + ': ', response.data.message || 'Unknown server error');
                            $('#course-creation-progress').hide();
                            $('#create-course-pages-btn').prop('disabled', false).removeClass('disabled');
                            isProcessing = false;
                            alert('Error: ' + (response.data.message || 'Unknown server error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX request failed for offset ' + offset + ': Status=' + status + ', Error=' + error);
                        $('#course-creation-progress').hide();
                        $('#create-course-pages-btn').prop('disabled', false).removeClass('disabled');
                        isProcessing = false;
                        alert('Error creating pages: ' + error + ' (Status: ' + status + ')');
                    }
                });
            }
        });
    </script>
    <style>
        #create-course-pages-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
    <?php
}
add_action('admin_footer', 'add_create_course_pages_button');

/**
 * Verify nonce for AJAX action
 */
function verify_create_course_pages_nonce() {
    if (!isset($_POST['nonce'])) {
        delete_transient('course_mgmt_batch_processing');
        error_log('AJAX create_initial_course_pages: Nonce missing, lock cleared');
        wp_send_json_error(['message' => 'Security error: Nonce missing'], 400);
    }
    if (!wp_verify_nonce($_POST['nonce'], 'create_initial_course_pages_nonce')) {
        delete_transient('course_mgmt_batch_processing');
        error_log('AJAX create_initial_course_pages: Invalid nonce - Received: ' . sanitize_text_field($_POST['nonce']) . ', lock cleared');
        wp_send_json_error(['message' => 'Security error: Invalid nonce'], 400);
    }
    error_log('AJAX create_initial_course_pages: Nonce verified successfully');
}
add_action('wp_ajax_create_initial_course_pages', 'verify_create_course_pages_nonce', 1);

/**
 * One-time update for existing course categories to convert arrays to single strings
 */
function update_existing_course_categories() {
    if (get_option('course_mgmt_categories_updated')) {
        error_log('Course categories update already completed');
        return;
    }

    error_log('Starting one-time course categories update');

    $batch_size = 10;
    $offset = get_option('course_mgmt_categories_update_offset', 0);
    $args = [
        'post_type' => 'course',
        'posts_per_page' => $batch_size,
        'post_status' => 'publish',
        'offset' => $offset,
    ];
    $courses = get_posts($args);

    if (empty($courses)) {
        error_log('No more courses to process for categories update');
        update_option('course_mgmt_categories_updated', true);
        delete_option('course_mgmt_categories_update_offset');
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Course Management:</strong> Course categories have been successfully updated to use single strings.</p>
            </div>
            <?php
        });
        return;
    }

    $updated = 0;
    foreach ($courses as $course) {
        $categories = get_field('field_681ccc91b123d', $course->ID);
        error_log('Checking categories for course ID ' . $course->ID . ': ' . (is_array($categories) ? json_encode($categories) : $categories));
        if (is_array($categories) && !empty($categories)) {
            $new_value = sanitize_text_field($categories[0]);
            $result = update_field('field_681ccc91b123d', $new_value, $course->ID);
            if ($result) {
                $updated++;
                error_log('Updated categories for course ID ' . $course->ID . ': ' . $new_value);
            } else {
                error_log('Failed to update categories for course ID ' . $course->ID);
            }
        }
    }

    update_option('course_mgmt_categories_update_offset', $offset + $batch_size);
    error_log('Processed batch of ' . count($courses) . ' courses, offset: ' . ($offset + $batch_size) . ', updated: ' . $updated);

    if (!wp_next_scheduled('course_mgmt_update_categories_event')) {
        wp_schedule_single_event(time() + 5, 'course_mgmt_update_categories_event');
    }
}

add_action('init', function() {
    if (is_admin() && current_user_can('manage_options')) {
        update_existing_course_categories();
    }
}, 100);

add_action('course_mgmt_update_categories_event', 'update_existing_course_categories');

/**
 * Cleanup duplicate course pages and products
 */
function cleanup_duplicate_course_pages() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $args = [
        'post_type' => 'stm-courses',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ];
    $stm_courses = get_posts($args);

    foreach ($stm_courses as $stm_course_id) {
        $course_page_id = get_post_meta($stm_course_id, 'related_course_id', true);
        $course_product_id = get_post_meta($stm_course_id, 'related_course_product_id', true);
        $webinar_product_id = get_post_meta($stm_course_id, 'related_webinar_product_id', true);

        $duplicate_pages = get_posts([
            'post_type' => 'course',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_stm_course_id',
                    'value' => $stm_course_id,
                ],
            ],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if (count($duplicate_pages) > 1) {
            foreach ($duplicate_pages as $index => $page_id) {
                if ($page_id != $course_page_id) {
                    wp_delete_post($page_id, true);
                    error_log('Deleted duplicate course page ID: ' . $page_id . ' for stm_course_id: ' . $stm_course_id);
                }
            }
        }

        $duplicate_products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => 'related_stm_course_id',
                    'value' => $stm_course_id,
                ],
            ],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($duplicate_products as $product_id) {
            if ($product_id != $course_product_id && $product_id != $webinar_product_id) {
                wp_delete_post($product_id, true);
                error_log('Deleted duplicate product ID: ' . $product_id . ' for stm_course_id: ' . $stm_course_id);
            }
        }
    }

    error_log('Duplicate cleanup completed');
}
add_action('admin_init', function() {
    if (isset($_GET['cleanup_duplicates']) && current_user_can('manage_options')) {
        cleanup_duplicate_course_pages();
        wp_redirect(admin_url('edit.php?post_type=course&message=cleanup_completed'));
        exit;
    }
});
?>
