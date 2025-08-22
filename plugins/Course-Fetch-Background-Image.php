<?php
/*
 * Plugin Name: Course Fetch Background Image
 * Description: Dynamically fetches and applies the featured image from stm-courses as a background for course pages.
 * Version: 1.0
 * Author: Carlos Murillo
 * Author URI: https://lucumaagency.com/
 * License: GPL-2.0+
 */

// Define default image ID for fallback
define('DEFAULT_IMAGE_ID', 123); // Replace with actual attachment ID

// Include utilities if available
$utilities_path = WP_PLUGIN_DIR . '/course-utilities/course-utilities.php';
if (file_exists($utilities_path)) {
    require_once $utilities_path;
} else {
    // Log error and prevent fatal error
    error_log('Course Utilities plugin not found at ' . $utilities_path);
    add_action('admin_notices', function() {
        ?>
        <div class="notice notice-error">
            <p><strong>Course Frontend Styling Error:</strong> The Course Utilities plugin is required but was not found. Please ensure it is installed and activated.</p>
        </div>
        <?php
    });
}

// Add background-image style to the .selling-page-bkg class in the frontend
add_action('wp_footer', function() {
    if (!is_singular('course')) {
        return;
    }

    $course_page_id = get_the_ID();
    $stm_course_id = function_exists('get_related_stm_course_id') ? get_related_stm_course_id($course_page_id) : 0;

    if ($stm_course_id) {
        $thumbnail_id = get_post_thumbnail_id($stm_course_id);
    } else {
        $thumbnail_id = get_post_thumbnail_id($course_page_id); // Fallback to course page featured image
    }

    if (!$thumbnail_id) {
        $thumbnail_id = DEFAULT_IMAGE_ID;
    }

    $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
    if (!$thumbnail_url) {
        return;
    }

    echo '<style>
        .selling-page-bkg {
            background-image: url("' . esc_url($thumbnail_url) . '");
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
    </style>';
});
?>
