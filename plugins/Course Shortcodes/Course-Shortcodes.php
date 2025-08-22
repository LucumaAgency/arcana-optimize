<?php
/*
 * Plugin Name: Course Shortcodes
 * Description: Provides shortcodes for displaying course-related data, dependent on Course Utilities plugin.
 * Version: 1.3
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 * Requires Plugins: course-utilities
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if Course Utilities plugin is active and required functions exist
add_action('plugins_loaded', function () {
    if (!function_exists('get_related_stm_course_id') || !function_exists('get_instructor_photo_url')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>Course Shortcodes:</strong> The Course Utilities plugin is required and must be active for this plugin to work.</p></div>';
        });
        return;
    }

    // Register shortcodes
    add_shortcode('stm_course_id', 'stm_course_id_shortcode');
    add_shortcode('instructor_photo', 'instructor_photo_shortcode');
    add_shortcode('instructor_bio', 'instructor_bio_shortcode');
    add_shortcode('instructor_position', 'instructor_position_shortcode');
    add_shortcode('instructor_details', 'instructor_details_shortcode');
    add_shortcode('course_content', 'course_content_shortcode');
    add_shortcode('instructor_socials', 'instructor_socials_shortcode');
    add_shortcode('course_gallery', 'course_gallery_shortcode');
    add_shortcode('inject_featured_image', 'inject_featured_image_shortcode');
});

// Shortcode: Display related stm-courses ID
function stm_course_id_shortcode($atts) {
    $atts = shortcode_atts([
        'course_page_id' => 0,
    ], $atts, 'stm_course_id');

    $course_page_id = absint($atts['course_page_id']);
    if (!$course_page_id) {
        return esc_html__('Invalid course page ID', 'course-shortcodes');
    }

    $stm_course_id = get_related_stm_course_id($course_page_id);
    if (!$stm_course_id) {
        return esc_html__('No related STM course found', 'course-shortcodes');
    }

    return esc_html($stm_course_id);
}

// Shortcode: Display instructor photo as an image
function instructor_photo_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,
        'post_id' => 0, // Allow specifying stm-courses post ID
        'class' => 'instructor-photo', // CSS class for the image
        'alt' => 'Instructor Photo', // Alt text
        'width' => 200, // Image width
        'height' => 200, // Image height
    ], $atts, 'instructor_photo');

    $user_id = absint($atts['user_id']);
    if (!$user_id) {
        // Try to get user_id from course
        $course_page_id = get_the_ID();
        if (get_post_type($course_page_id) === 'course') {
            $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
            if ($stm_course_id) {
                $stm_course = get_post($stm_course_id);
                if ($stm_course && $stm_course->post_type === 'stm-courses') {
                    $user_id = get_post_meta($stm_course_id, 'instructor_id', true);
                    if (!$user_id || !is_numeric($user_id)) {
                        $user_id = $stm_course->post_author;
                    }
                    $user_id = absint($user_id);
                }
            }
        }
    }

    if (!$user_id) {
        return esc_html__('Invalid user ID', 'course-shortcodes');
    }

    $photo_url = get_instructor_photo_url($user_id);
    if (!$photo_url) {
        return esc_html__('No instructor photo found', 'course-shortcodes');
    }

    // Build the <img> tag
    return sprintf(
        '<img src="%s" class="%s" alt="%s" width="%s" height="%s" />',
        esc_url($photo_url),
        esc_attr($atts['class']),
        esc_attr($atts['alt']),
        esc_attr($atts['width']),
        esc_attr($atts['height'])
    );
}

// Shortcode: Display instructor biography
function instructor_bio_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => 0,
        'post_id' => 0, // Allow specifying stm-courses post ID
        'class' => 'instructor-bio', // CSS class for the wrapper
    ], $atts, 'instructor_bio');

    $user_id = absint($atts['user_id']);
    if (!$user_id) {
        // Try to get user_id from course
        $course_page_id = get_the_ID();
        if (get_post_type($course_page_id) === 'course') {
            $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
            if ($stm_course_id) {
                $stm_course = get_post($stm_course_id);
                if ($stm_course && $stm_course->post_type === 'stm-courses') {
                    $user_id = get_post_meta($stm_course_id, 'instructor_id', true);
                    if (!$user_id || !is_numeric($user_id)) {
                        $user_id = $stm_course->post_author;
                    }
                    $user_id = absint($user_id);
                }
            }
        }
    }

    if (!$user_id) {
        return esc_html__('Invalid user ID', 'course-shortcodes');
    }

    // Get the instructor's biography from WordPress user profile
    $bio = get_the_author_meta('description', $user_id);
    if (empty($bio)) {
        return esc_html__('No biography available', 'course-shortcodes');
    }

    // Build the output
    return sprintf(
        '<div class="%s">%s</div>',
        esc_attr($atts['class']),
        wp_kses_post($bio)
    );
}

// Shortcode: Display instructor position
function instructor_position_shortcode($atts) {
    error_log('=== instructor_position_shortcode START ===');

    $atts = shortcode_atts([
        'post_id' => 0,
        'class' => 'instructor-position',
    ], $atts, 'instructor_position');

    error_log('Shortcode attributes: ' . print_r($atts, true));

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        error_log('Not a course page: ' . $course_page_id);
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
    error_log('STM course ID: ' . $stm_course_id);
    if (!$stm_course_id) {
        error_log('No valid STM course ID found');
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        error_log('Invalid STM course: ' . $stm_course_id);
        return '';
    }

    $instructor_id = get_post_meta($stm_course_id, 'instructor_id', true);
    error_log('Instructor ID from meta: ' . ($instructor_id ?: 'Not found'));
    if (!$instructor_id || !is_numeric($instructor_id)) {
        $instructor_id = $stm_course->post_author;
        error_log('Falling back to post author: ' . $instructor_id);
    }
    $instructor_id = absint($instructor_id);
    error_log('Final instructor ID: ' . $instructor_id);

    $user = get_user_by('ID', $instructor_id);
    if (!$user) {
        error_log('No user found for instructor ID: ' . $instructor_id);
        return '';
    }

    $position = get_user_meta($instructor_id, 'position', true); // Update to correct key if needed
    error_log('Position meta: ' . ($position ?: 'Not found'));

    if (empty($position)) {
        error_log('No position available for instructor ID: ' . $instructor_id . ', course ID: ' . $stm_course_id);
        return '';
    }

    $output = sprintf(
        '<div class="%s">%s</div>',
        esc_attr($atts['class']),
        esc_html($position)
    );
    error_log('Output: ' . $output);
    error_log('=== instructor_position_shortcode END ===');

    return $output;
}
add_shortcode('instructor_position', 'instructor_position_shortcode');



// Shortcode: Display instructor details (name, position, bio)
function instructor_details_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => get_the_ID(),
    ], $atts, 'instructor_details');

    $post_id = absint($atts['post_id']);
    if (!$post_id || get_post_type($post_id) !== 'stm-courses') {
        return '<p>' . esc_html__('Invalid or missing course ID.', 'course-shortcodes') . '</p>';
    }

    // Get instructor fields
    $instructor = get_field('field_681ccc7eb123b', $post_id) ?: 'Unknown Instructor';
    $position = get_field('field_6821877b2193e', $post_id) ?: '';
    $bio = get_field('field_682187802193f', $post_id) ?: '';

    // Build output
    $output = '<div class="instructor-details">';
    $output .= '<h3>' . esc_html($instructor) . '</h3>';
    if ($position) {
        $output .= '<p class="instructor-position">' . esc_html($position) . '</p>';
    }
    if ($bio) {
        $output .= '<div class="instructor-bio">' . wp_kses_post($bio) . '</div>';
    }
    $output .= '</div>';

    return $output;
}

// Shortcode: Render course content on 'course' type pages
function course_content_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'course_content');

    $course_page_id = get_the_ID();
    $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);

    if (!$stm_course_id) {
        return esc_html__('No related STM course found', 'course-shortcodes');
    }

    // Check if get_course_json_data exists
    if (!function_exists('get_course_json_data')) {
        return esc_html__('Course content not available: get_course_json_data function missing', 'course-shortcodes');
    }

    $course_data = get_course_json_data($stm_course_id);
    if (!$course_data || !isset($course_data['content']) || empty($course_data['content'])) {
        return esc_html__('No course content available', 'course-shortcodes');
    }

    return wp_kses_post($course_data['content']);
}

// Shortcode: Display instructor's social media links on 'course' type pages
function instructor_socials_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'instructor_socials');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    $instructor_id = get_post_meta($stm_course_id, 'instructor_id', true);
    if (!$instructor_id || !is_numeric($instructor_id)) {
        $instructor_id = $stm_course->post_author;
    }

    $user = get_user_by('ID', $instructor_id);
    if (!$user) {
        return '';
    }

    $social_networks = [
        'facebook' => [
            'icon' => 'fab fa-facebook-f',
            'label' => 'Facebook',
        ],
        'twitter' => [
            'icon' => 'fab fa-x-twitter',
            'label' => 'X',
        ],
        'instagram' => [
            'icon' => 'fab fa-instagram',
            'label' => 'Instagram',
        ],
        'linkedin' => [
            'icon' => 'fab fa-linkedin-in',
            'label' => 'LinkedIn',
        ],
    ];

    $output = '<ul class="instructor-socials">';
    $has_socials = false;

    foreach ($social_networks as $key => $social) {
        $url = get_user_meta($instructor_id, $key, true);
        if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
            $has_socials = true;
            $output .= sprintf(
                '<li><a href="%s" target="_blank" title="%s"><i class="%s"></i></a></li>',
                esc_url($url),
                esc_attr($social['label']),
                esc_attr($social['icon'])
            );
        }
    }

    $output .= '</ul>';

    if (!$has_socials) {
        return '';
    }

    $output .= '<style>
        .instructor-socials {
            list-style: none;
            padding: 0;
            display: flex;
            gap: 10px;
        }
        .instructor-socials li {
            display: inline-block;
        }
        .instructor-socials a {
            color: #333;
            font-size: 20px;
            text-decoration: none;
        }
        .instructor-socials a:hover {
            color: #0073aa;
        }
    </style>';

    return $output;
}

// Shortcode: Display the course image gallery on 'course' type pages
function course_gallery_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'course_gallery');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    if (!function_exists('get_field')) {
        return esc_html__('ACF not available', 'course-shortcodes');
    }

    $gallery_images = function_exists('get_cached_acf_field') ? get_cached_acf_field('gallery_portfolio', $stm_course_id) : get_field('gallery_portfolio', $stm_course_id);
    if (empty($gallery_images) || !is_array($gallery_images)) {
        $gallery_images = [
            [
                'url' => wp_get_attachment_url(0), // Fallback to no image
                'sizes' => ['thumbnail' => wp_get_attachment_url(0)],
                'alt' => 'Default Gallery Image',
                'caption' => 'Default Image'
            ]
        ];
    }

    $output = '<div class="course-gallery">';
    foreach ($gallery_images as $image) {
        $full_url = isset($image['url']) ? esc_url($image['url']) : '';
        $thumbnail_url = isset($image['sizes']['thumbnail']) ? esc_url($image['sizes']['thumbnail']) : $full_url;
        $alt = isset($image['alt']) ? esc_attr($image['alt']) : '';
        $caption = isset($image['caption']) ? esc_attr($image['caption']) : '';

        if ($full_url) {
            $output .= sprintf(
                '<div class="gallery-item">' .
                '<a href="%s" class="gallery-lightbox" title="%s">' .
                '<img src="%s" alt="%s" class="gallery-thumbnail" />' .
                '</a>' .
                '%s' .
                '</div>',
                $full_url,
                $caption,
                $thumbnail_url,
                $alt,
                $caption ? '<p class="gallery-caption">' . $caption . '</p>' : ''
            );
        }
    }
    $output .= '</div>';

    $output .= '<style>
        .course-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .gallery-item {
            position: relative;
        }
        .gallery-thumbnail {
            width: 100%;
            height: auto;
            object-fit: cover;
            border-radius: 5px;
        }
        .gallery-caption {
            text-align: center;
            font-size: 14px;
            margin: 5px 0 0;
            color: #333;
        }
        .gallery-lightbox {
            display: block;
        }
    </style>';

    if (!wp_script_is('jquery', 'enqueued')) {
        wp_enqueue_script('jquery');
    }
    $output .= '<script>
        jQuery(document).ready(function($) {
            $(".gallery-lightbox").on("click", function(e) {
                e.preventDefault();
                var imgSrc = $(this).attr("href");
                var caption = $(this).attr("title");
                var lightbox = $("<div class=\"lightbox\"><img src=\"" + imgSrc + "\" /><p>" + caption + "</p><span class=\"close-lightbox\">×</span></div>");
                $("body").append(lightbox);
                lightbox.fadeIn();
                lightbox.find(".close-lightbox, img").on("click", function() {
                    lightbox.fadeOut(function() { $(this).remove(); });
                });
            });
        });
    </script>';

    $output .= '<style>
        .lightbox {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .lightbox img {
            max-width: 90%;
            max-height: 80%;
            border-radius: 5px;
        }
        .lightbox p {
            color: white;
            text-align: center;
            margin-top: 10px;
        }
        .lightbox .close-lightbox {
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            font-size: 30px;
            cursor: pointer;
        }
    </style>';

    return $output;
}

// Shortcode: Inject the featured image from stm-courses to its related course
function inject_featured_image_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
    ], $atts, 'inject_featured_image');

    $course_page_id = get_the_ID();
    if (get_post_type($course_page_id) !== 'course') {
        return '';
    }

    $stm_course_id = absint($atts['post_id']) ?: get_related_stm_course_id($course_page_id);
    if (!$stm_course_id) {
        return '';
    }

    $stm_course = get_post($stm_course_id);
    if (!$stm_course || $stm_course->post_type !== 'stm-courses') {
        return '';
    }

    $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    if (!$thumbnail_id) {
        $thumbnail_id = 0; // No default image
    }

    set_post_thumbnail($course_page_id, $thumbnail_id);
    return '';
}

// Note: Define a valid DEFAULT_IMAGE_ID in your theme or another plugin if needed
// define('DEFAULT_IMAGE_ID', 0);
?>
