<?php
// Add your custom functions here.

// Add product thumbnail to order details table in My Account
add_filter('woocommerce_order_item_name', 'add_thumbnail_to_order_details_table', 20, 3);
function add_thumbnail_to_order_details_table($item_name, $item, $is_visible) {
    // Target only the view order pages in My Account
    if (is_wc_endpoint_url('view-order')) {
        $product = $item->get_product(); // Get the WC_Product object
        if ($product && $product->get_image_id() > 0) {
            $thumbnail = $product->get_image(array(50, 50)); // Get thumbnail (50x50 pixels)
            $item_name = '<div class="item-thumbnail" style="float:left; margin-right:10px;">' . $thumbnail . '</div>' . $item_name;
        }
    }
    return $item_name;
}

add_action('woocommerce_order_details_after_order_table', 'show_webinar_info_in_my_account');
function show_webinar_info_in_my_account($order) {
    // Check if the product has webinar info (via Purchase Note or meta)
    foreach ($order->get_items() as $item_id => $item) {
        $product = $item->get_product();
        if ($product) {
            // Get the Purchase Note
            $note = $product->get_purchase_note();
            if ($note) {
                echo '<h3>Webinar Information</h3>';
                echo wpautop(wptexturize($note));
            }
        }
    }
}




/* add_action('wp_enqueue_scripts', 'add_icon_script');
function add_icon_script() {
    // Make sure jQuery is loaded
    wp_enqueue_script('jquery');
    
    // Enqueue your custom CSS file
    wp_enqueue_style(
        'nav-icon-style',
        get_template_directory_uri() . '/css/nav-icon.css',
        array(),
        '1.0.0'
    );
    
    // Add our custom script
    wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            // Add SVG icon after the last menu item
            if($("nav ul li").length > 0) {
                $("nav ul li").last().find("a").append("<img src=\'https://academy.arcanalabs.ai/wp-content/uploads/2025/05/open-in-new-1.svg\' class=\'nav-external-icon\' alt=\'external link\'>");
                console.log("SVG icon script executed");
            }
        });
    ');
}

*/






add_action('acf/save_post', 'populate_course_custom_title', 20);
function populate_course_custom_title($post_id) {
    // Only run for 'course' post type and not revisions
    if (get_post_type($post_id) !== 'course' || wp_is_post_revision($post_id)) {
        error_log("Skipping populate_course_custom_title: Not a course post or is revision, post_id: $post_id");
        return;
    }

    // Ensure ACF is loaded
    if (!function_exists('update_field')) {
        error_log("update_field function not available for post_id: $post_id");
        return;
    }

    // Verify field group
    $field_group = acf_get_field_group('group_681ccc3e039b0');
    if (!$field_group || !acf_get_field_group_visibility($field_group, ['post_id' => $post_id])) {
        error_log("Field group group_681ccc3e039b0 not assigned or not visible for post_id: $post_id");
        return;
    }

    // Get the related stm_course_id from post meta
    $stm_course_id = get_post_meta($post_id, 'related_stm_course_id', true);
    if (!$stm_course_id) {
        error_log("No related stm_course_id found for course post_id: $post_id");
        // Fallback: Try reverse lookup
        $posts = get_posts([
            'post_type' => 'stm-courses',
            'meta_query' => [
                [
                    'key' => 'related_course_id',
                    'value' => $post_id,
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => 1,
        ]);
        if (!empty($posts)) {
            $stm_course_id = $posts[0]->ID;
            error_log("Found stm_course_id $stm_course_id via reverse lookup for course post_id: $post_id");
            update_post_meta($post_id, 'related_stm_course_id', $stm_course_id);
        } else {
            error_log("Reverse lookup failed: No related stm-courses post found for course post_id: $post_id");
            return;
        }
    }

    // Fetch course data
    $api_url = home_url("/wp-json/custom/v1/courses/{$stm_course_id}");
    $response = wp_remote_get($api_url, ['timeout' => 10]);
    if (is_wp_error($response)) {
        error_log("API request failed for stm_course_id: $stm_course_id, Error: " . $response->get_error_message());
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $course_data = json_decode($body, true);

    if (!$course_data || !is_array($course_data) || empty($course_data['title'])) {
        error_log("No valid title data for stm_course_id: $stm_course_id, Data: " . (is_array($course_data) ? json_encode($course_data) : $course_data));
        return;
    }

    // Update the course_custom_title ACF field
    $title = sanitize_text_field($course_data['title']);
    $field_object = get_field_object('field_681ccc5ab1238', $post_id);
    if (!$field_object) {
        error_log("ACF field field_681ccc5ab1238 does not exist or is not registered for post_id: $post_id");
        return;
    }

    $result = update_field('field_681ccc5ab1238', $title, $post_id);
    error_log("Updated course_custom_title for post_id $post_id: " . ($result ? 'Success' : 'Failed') . ", Title: $title");
}

/**
 * Test sync woocomerce product with lms enrollment
 */

add_action('woocommerce_order_status_completed', 'inscribir_usuario_curso_tras_compra_pdf', 10, 1);
function inscribir_usuario_curso_tras_compra_pdf($order_id) {
    error_log('=== Depuración de inscribir_usuario_curso_tras_compra_pdf ===');
    error_log('Order ID: ' . $order_id);

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('Error: No se pudo cargar el pedido con ID ' . $order_id);
        return;
    }
    error_log('Pedido cargado correctamente.');

    $user_id = $order->get_user_id();
    if (!$user_id) {
        error_log('Error: No se encontró un usuario asociado al pedido.');
        return;
    }
    error_log('User ID: ' . $user_id);

    $curso_id = 57265; // ID confirmado del curso
    $product_id_esperado = 57535; // ID confirmado del producto PDF
    error_log('Curso ID configurado: ' . $curso_id);
    error_log('Producto ID esperado: ' . $product_id_esperado);

    $items = $order->get_items();
    if (empty($items)) {
        error_log('Error: No se encontraron ítems en el pedido.');
        return;
    }
    error_log('Número de ítems en el pedido: ' . count($items));

    $producto_encontrado = false;
    foreach ($items as $item) {
        $product_id = $item->get_product_id();
        error_log('Producto en el pedido: ID ' . $product_id);
        if ($product_id == $product_id_esperado) {
            $producto_encontrado = true;
            error_log('Producto PDF encontrado: ID ' . $product_id);
            break;
        }
    }

    if (!$producto_encontrado) {
        error_log('Error: El producto PDF (ID ' . $product_id_esperado . ') no está en el pedido.');
        return;
    }

    if (!function_exists('stm_lms_add_user_course')) {
        error_log('Error: La función stm_lms_add_user_course no está disponible. ¿Está MasterStudy LMS activo?');
        return;
    }
    error_log('Función stm_lms_add_user_course encontrada.');

    global $wpdb;
    $table = $wpdb->prefix . 'stm_lms_user_courses';
    $data = array(
        'user_id' => $user_id,
        'course_id' => $curso_id,
        'progress_percent' => 0,
        'start_time' => current_time('mysql'),
        'status' => 'enrolled'
    );

    $result = $wpdb->insert($table, $data, array('%d', '%d', '%d', '%s', '%s'));
    if ($result === false) {
        error_log('Error al inscribir al usuario: ' . $wpdb->last_error);
    } else {
        error_log('Éxito: Usuario ' . $user_id . ' inscrito en el curso ' . $curso_id);
    }
}


/**
 * Redirect course and webinar product pages, and stm-courses pages to their corresponding course pages
 */
add_action('template_redirect', function() {
    // Case 1: Redirect product pages to course pages
    if (is_singular('product')) {
        $product_id = get_the_ID();
        error_log('Checking product redirection for product ID: ' . $product_id);

        // Check if the product is associated with an stm-courses post
        $stm_course_id = get_post_meta($product_id, 'related_stm_course_id', true);
        if (!$stm_course_id) {
            error_log('No related stm_course_id found for product ID: ' . $product_id);
            return;
        }

        // Get the related course page ID
        $course_page_id = get_post_meta($stm_course_id, 'related_course_id', true);
        if (!$course_page_id) {
            error_log('No related course page ID found for stm_course_id: ' . $stm_course_id);
            return;
        }

        // Get the course page post
        $course_page = get_post($course_page_id);
        if (!$course_page || $course_page->post_type !== 'course' || $course_page->post_status !== 'publish') {
            error_log('Invalid or unpublished course page for course_page_id: ' . $course_page_id);
            return;
        }

        // Get the course page permalink
        $course_permalink = get_permalink($course_page_id);
        if (!$course_permalink) {
            error_log('Failed to get permalink for course_page_id: ' . $course_page_id);
            return;
        }

        error_log('Redirecting from product ID ' . $product_id . ' to course page: ' . $course_permalink);
        wp_safe_redirect($course_permalink, 301);
        exit;
    }

    // Case 2: Redirect stm-courses pages to course pages
    if (is_singular('stm-courses')) {
        $stm_course_id = get_the_ID();
        error_log('Checking redirection for stm-courses ID: ' . $stm_course_id);

        // Get the related course page ID
        $course_page_id = get_post_meta($stm_course_id, 'related_course_id', true);
        if (!$course_page_id) {
            error_log('No related course page ID found for stm_course_id: ' . $stm_course_id);
            return;
        }

        // Get the course page post
        $course_page = get_post($course_page_id);
        if (!$course_page || $course_page->post_type !== 'course' || $course_page->post_status !== 'publish') {
            error_log('Invalid or unpublished course page for course_page_id: ' . $course_page_id);
            return;
        }

        // Get the course page permalink
        $course_permalink = get_permalink($course_page_id);
        if (!$course_permalink) {
            error_log('Failed to get permalink for course_page_id: ' . $course_page_id);
            return;
        }

        error_log('Redirecting from stm-courses ID ' . $stm_course_id . ' to course page: ' . $course_permalink);
        wp_safe_redirect($course_permalink, 301);
        exit;
    }
});





/**
 * Shortcode para mostrar la cantidad de productos vendidos por fecha seleccionada
 * @param array $atts Atributos del shortcode
 * @return string HTML con la cantidad de productos vendidos
 */
function seats_remaining_shortcode($atts) {
    global $post;
    $post_id = $post ? $post->ID : 0;
    if (!$post_id || get_post_type($post_id) !== 'course') {
        error_log('Seats Remaining Shortcode: Invalid post ID or not a course post, ID: ' . $post_id);
        return '<p>Error: This shortcode must be used on a course page.</p>';
    }

    // Get available start dates and stocks from ACF repeater field
    $available_dates = [];
    $date_stocks = [];
    if (have_rows('field_6826dd2179231', $post_id)) {
        while (have_rows('field_6826dd2179231', $post_id)) {
            the_row();
            $date_text = get_sub_field('field_6826dfe2d7837');
            $stock = get_sub_field('field_684ba360c13e2'); // webinar_stock
            if (!empty($date_text)) {
                $sanitized_date = sanitize_text_field($date_text);
                $available_dates[] = $sanitized_date;
                $date_stocks[$sanitized_date] = is_numeric($stock) ? intval($stock) : 10;
                error_log('Seats Remaining Shortcode: Available date added: ' . $sanitized_date . ', Stock: ' . $date_stocks[$sanitized_date]);
            }
        }
    }

    if (empty($available_dates)) {
        error_log('Seats Remaining Shortcode: No start dates available for post ID ' . $post_id);
        return '<p>No start dates available for this course.</p>';
    }

    // Default date: the first available date
    $default_date = $available_dates[0];
    error_log('Seats Remaining Shortcode: Default date set to ' . $default_date);

    // Get enroll product ID
    $enroll_product_link = get_field('field_6821879e21941', $post_id);
    $enroll_product_id = 0;
    if (!empty($enroll_product_link)) {
        $url_parts = parse_url($enroll_product_link, PHP_URL_QUERY);
        parse_str($url_parts, $query_params);
        $enroll_product_id = isset($query_params['add-to-cart']) ? intval($query_params['add-to-cart']) : 0;
    }

    if (!$enroll_product_id) {
        error_log('Seats Remaining Shortcode: No valid enroll product ID for post ID ' . $post_id);
        return '<p>Error: Enroll product not found.</p>';
    }
    error_log('Seats Remaining Shortcode: Enroll product ID ' . $enroll_product_id);

    // Get initial stock for default date
    $initial_seats = isset($date_stocks[$default_date]) ? $date_stocks[$default_date] : 10;
    error_log('Seats Remaining Shortcode: Initial stock for ' . $default_date . ': ' . $initial_seats);

    // Function to count sales and calculate remaining seats
    $calculate_seats_remaining = function($product_id, $selected_date) use ($available_dates, $date_stocks) {
        // Validate selected date is in available dates
        if (!in_array($selected_date, $available_dates)) {
            error_log('Seats Remaining: Invalid selected date ' . $selected_date . ' not in available dates: ' . implode(', ', $available_dates));
            return 10; // Fallback if date is invalid
        }

        // Get initial stock for selected date
        $initial_seats = isset($date_stocks[$selected_date]) ? $date_stocks[$selected_date] : 10;
        error_log('Seats Remaining Shortcode: Calculating seats for date ' . $selected_date . ', Initial stock: ' . $initial_seats);

        $args = [
            'status' => ['wc-completed'], // Only count completed orders
            'limit' => -1,
            'date_query' => [
                'after' => '2020-01-01', // Broad range to include all relevant orders
            ],
        ];

        $orders = wc_get_orders($args);
        $sales_count = 0;
        error_log('Seats Remaining Shortcode: Total completed orders found: ' . count($orders));

        foreach ($orders as $order) {
            $order_status = $order->get_status();
            foreach ($order->get_items() as $item) {
                $item_product_id = $item->get_product_id();
                $start_date = $item->get_meta('Start Date');
                $quantity = $item->get_quantity();
                error_log('Seats Remaining Shortcode: Order ID ' . $order->get_id() . ', Status: ' . $order_status . ', Item Product ID ' . $item_product_id . ', Start Date: ' . ($start_date ?: 'None') . ', Quantity: ' . $quantity);

                // Exact string match for product ID and start date
                if ($item_product_id == $product_id && $start_date === $selected_date) {
                    $sales_count += $quantity;
                    error_log('Seats Remaining Shortcode: Match found: Added ' . $quantity . ' to sales count for date ' . $selected_date . ', Order ID ' . $order->get_id());
                }
            }
        }

        $seats_remaining = $initial_seats - $sales_count;
        if ($seats_remaining < 0) {
            error_log('Seats Remaining Shortcode: Negative seats detected, setting to 0. Initial: ' . $initial_seats . ', Sold: ' . $sales_count);
            $seats_remaining = 0;
        }
        error_log('Seats Remaining Shortcode: Product ID ' . $product_id . ', Date ' . $selected_date . ', Sold: ' . $sales_count . ', Initial: ' . $initial_seats . ', Remaining: ' . $seats_remaining);

        return $seats_remaining;
    };

    // Calculate seats for default date
    $seats_remaining = $calculate_seats_remaining($enroll_product_id, $default_date);
    error_log('Seats Remaining Shortcode: Final seats remaining for default date: ' . $seats_remaining);
    $hide_seats_text = $seats_remaining <= 0;

    // Generate HTML for the shortcode
    ob_start();
    ?>
    <div class="seats-remaining-container" <?php if ($hide_seats_text) echo 'style="display: none;"'; ?>>
        <p class="seats-label"><span id="seats-remaining"><?php echo esc_html(max(0, $seats_remaining)); ?></span> Remaining seats</p>
    </div>

    <style>
        .seats-remaining-container {
            max-width: 1200px;
            margin: 20px auto;
            text-align: center;
        }
        .seats-remaining-container .seats-label {
            font-size: 1.1em;
            color: #fff;
            font-weight: 400;
        }
    </style>

    <script>
        // Debounce function to prevent multiple rapid AJAX calls
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Log initial state
            console.log('Seats Remaining Shortcode: Initial seats remaining displayed: <?php echo esc_js($seats_remaining); ?>');
            console.log('Seats Remaining Shortcode: Default date: <?php echo esc_js($default_date); ?>');
            console.log('Seats Remaining Shortcode: Enroll product ID: <?php echo esc_js($enroll_product_id); ?>');

            // Track last selected date to prevent redundant AJAX calls
            let lastSelectedDate = '<?php echo esc_js($default_date); ?>';

            // Update seats remaining when a date is selected
            const updateSeats = debounce(function(selectedDate) {
                if (selectedDate === lastSelectedDate) {
                    console.log('Seats Remaining Shortcode: Skipping AJAX call, same date selected: ' + selectedDate);
                    return;
                }
                console.log('Seats Remaining Shortcode: Updating seats for date: ' + selectedDate);
                lastSelectedDate = selectedDate;

                // Make AJAX request to get seats remaining
                jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    action: 'get_seats_remaining',
                    product_id: '<?php echo esc_js($enroll_product_id); ?>',
                    selected_date: selectedDate,
                    post_id: '<?php echo esc_js($post_id); ?>',
                    nonce: '<?php echo wp_create_nonce('get_seats_remaining_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        console.log('Seats Remaining Shortcode: AJAX success, seats remaining: ' + response.data.seats_remaining);
                        document.getElementById('seats-remaining').textContent = response.data.seats_remaining;
                        // Toggle seats text visibility based on seats
                        const seatsContainer = document.querySelector('.seats-remaining-container');
                        if (seatsContainer) {
                            console.log('Seats Remaining Shortcode: Setting seats container display to: ' + (response.data.seats_remaining <= 0 ? 'none' : ''));
                            seatsContainer.style.display = response.data.seats_remaining <= 0 ? 'none' : '';
                        }
                    } else {
                        console.error('Seats Remaining Shortcode: AJAX error: ' + response.data.message);
                        document.getElementById('seats-remaining').textContent = 'Error';
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('Seats Remaining Shortcode: AJAX request failed: ' + textStatus + ', ' + errorThrown);
                    document.getElementById('seats-remaining').textContent = 'Error';
                });
            }, 300);

            // Attach click event to date buttons
            document.querySelectorAll('.date-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const selectedDate = this.getAttribute('data-date') || this.textContent.trim();
                    updateSeats(selectedDate);
                });
            });

            // Skip forced AJAX call if default date is already selected
            const defaultDateBtn = document.querySelector('.date-btn.selected');
            if (defaultDateBtn && (defaultDateBtn.getAttribute('data-date') || defaultDateBtn.textContent.trim()) === '<?php echo esc_js($default_date); ?>') {
                console.log('Seats Remaining Shortcode: Skipping forced AJAX call, default date already selected');
            } else {
                console.log('Seats Remaining Shortcode: Attempting forced AJAX call for default date');
                updateSeats('<?php echo esc_js($default_date); ?>');
            }
        });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * AJAX action to get the number of seats remaining for a specific date
 */
add_action('wp_ajax_get_seats_remaining', function() {
    check_ajax_referer('get_seats_remaining_nonce', 'nonce');

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $selected_date = isset($_POST['selected_date']) ? sanitize_text_field($_POST['selected_date']) : '';
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

    if (!$product_id || !$selected_date || !$post_id) {
        error_log('AJAX get_seats_remaining: Invalid parameters, Product ID: ' . $product_id . ', Date: ' . $selected_date . ', Post ID: ' . $post_id);
        wp_send_json_error(['message' => 'Invalid parameters']);
    }

    // Get available start dates and stocks from ACF repeater field
    $available_dates = [];
    $date_stocks = [];
    if (have_rows('field_6826dd2179231', $post_id)) {
        while (have_rows('field_6826dd2179231', $post_id)) {
            the_row();
            $date_text = get_sub_field('field_6826dfe2d7837');
            $stock = get_sub_field('field_684ba360c13e2');
            if (!empty($date_text)) {
                $sanitized_date = sanitize_text_field($date_text);
                $available_dates[] = $sanitized_date;
                $date_stocks[$sanitized_date] = is_numeric($stock) ? intval($stock) : 10;
                error_log('AJAX get_seats_remaining: Available date: ' . $sanitized_date . ', Stock: ' . $date_stocks[$sanitized_date]);
            }
        }
    }

    if (!in_array($selected_date, $available_dates)) {
        error_log('AJAX get_seats_remaining: Invalid selected date ' . $selected_date);
        wp_send_json_error(['message' => 'Invalid date']);
    }

    // Get initial stock for selected date
    $initial_seats = isset($date_stocks[$selected_date]) ? $date_stocks[$selected_date] : 10;
    error_log('AJAX get_seats_remaining: Initial stock for ' . $selected_date . ': ' . $initial_seats);

    $args = [
        'status' => ['wc-completed'], // Only count completed orders
        'limit' => -1,
        'date_query' => ['after' => '2020-01-01'],
    ];

    $orders = wc_get_orders($args);
    $sales_count = 0;
    error_log('AJAX get_seats_remaining: Total completed orders found: ' . count($orders));

    foreach ($orders as $order) {
        $order_status = $order->get_status();
        foreach ($order->get_items() as $item) {
            $item_product_id = $item->get_product_id();
            $start_date = $item->get_meta('Start Date');
            $quantity = $item->get_quantity();
            error_log('AJAX get_seats_remaining: Order ID ' . $order->get_id() . ', Status: ' . $order_status . ', Item Product ID ' . $item_product_id . ', Start Date: ' . ($start_date ?: 'None') . ', Quantity: ' . $quantity);

            // Exact string match for product ID and start date
            if ($item_product_id == $product_id && $start_date === $selected_date) {
                $sales_count += $quantity;
                error_log('AJAX get_seats_remaining: Match found: Added ' . $quantity . ' to sales count for date ' . $selected_date . ', Order ID ' . $order->get_id());
            }
        }
    }

    $seats_remaining = $initial_seats - $sales_count;
    if ($seats_remaining < 0) {
        error_log('AJAX get_seats_remaining: Negative seats detected, setting to 0. Initial: ' . $initial_seats . ', Sold: ' . $sales_count);
        $seats_remaining = 0;
    }
    error_log('AJAX get_seats_remaining: Product ID ' . $product_id . ', Date ' . $selected_date . ', Sold: ' . $sales_count . ', Initial: ' . $initial_seats . ', Remaining: ' . $seats_remaining);

    wp_send_json_success(['seats_remaining' => max(0, $seats_remaining)]);
});

add_shortcode('seats_remaining', 'seats_remaining_shortcode');




/**
 * Button window selectable boxes with overlay
 */

function popup_selectable_boxes_shortcode() {
    ob_start();
    ?>
    <div style="display: flex; justify-content: center; align-items: center; margin: 0;">
        <button onclick="showPopup()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background-color: #F22EBE; border: none; border-radius: 5px;">Enroll now</button>
        <div id="overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.2); z-index: 9998;"></div>
        <div id="popup" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); font-family: Arial, sans-serif; font-size: 24px; z-index: 9999; justify-content: center;">
            <img src="https://academy.arcanalabs.ai/wp-content/uploads/2025/06/close-icon-selectable-boxes.png" alt="Close" style="position: absolute; top: -30px; right: 0px; width: 24px; height: 24px; cursor: pointer; filter: invert(100%);" onclick="closePopup()">
            <?php echo do_shortcode('[selectable_boxes]'); ?>
        </div>
    </div>
    <script>
        function showPopup() {
            const popup = document.getElementById('popup');
            const overlay = document.getElementById('overlay');
            popup.style.display = 'block';
            overlay.style.display = 'block';

            // Forzar reinicialización de selectable_boxes con un retraso mayor
            setTimeout(() => {
                console.log('Popup opened, initializing enroll-course selection');

                // Limpiar cualquier selección previa dentro del popup
                popup.querySelectorAll('.box').forEach(box => {
                    box.classList.remove('selected');
                    box.classList.add('no-button');
                    const circleContainer = box.querySelector('.circle-container');
                    const circlecontainer = box.querySelector('.circlecontainer');
                    const startDates = box.querySelector('.start-dates');
                    if (circleContainer) circleContainer.style.display = 'flex';
                    if (circlecontainer) circlecontainer.style.display = 'none';
                    if (startDates) startDates.style.display = 'none';
                    console.log('Cleared selection for box:', box.className);
                });

                // Seleccionar explícitamente la caja .enroll-course dentro del popup
                const enrollBox = popup.querySelector('.enroll-course');
                if (enrollBox) {
                    console.log('Enroll box found, applying selected state');
                    enrollBox.classList.add('selected');
                    enrollBox.classList.remove('no-button');
                    const selectedCircleContainer = enrollBox.querySelector('.circle-container');
                    const selectedCirclecontainer = enrollBox.querySelector('.circlecontainer');
                    const selectedStartDates = enrollBox.querySelector('.start-dates');
                    if (selectedCircleContainer) {
                        selectedCircleContainer.style.display = 'none';
                        console.log('Hid circle-container for enroll box');
                    }
                    if (selectedCirclecontainer) {
                        selectedCirclecontainer.style.display = 'flex';
                        console.log('Showed circlecontainer for enroll box');
                    }
                    if (selectedStartDates) {
                        selectedStartDates.style.display = 'block';
                        console.log('Showed start-dates for enroll box');
                    }
                    enrollBox.scrollIntoView({ behavior: 'smooth', block: 'center' });

                    // Seleccionar la primera fecha por defecto
                    const firstDateBtn = enrollBox.querySelector('.date-btn');
                    if (firstDateBtn) {
                        popup.querySelectorAll('.date-btn').forEach(b => b.classList.remove('selected'));
                        firstDateBtn.classList.add('selected');
                        window.selectedDate = firstDateBtn.getAttribute('data-date') || firstDateBtn.textContent.trim();
                        console.log('Default selected date in popup:', window.selectedDate);
                    } else {
                        console.error('No date buttons found in enroll box');
                    }
                } else {
                    console.error('Enroll box not found in popup');
                }
            }, 300); // Aumentado a 300ms para asegurar que el DOM esté listo
        }

        function closePopup() {
            const popup = document.getElementById('popup');
            const overlay = document.getElementById('overlay');
            if (popup && overlay) {
                popup.style.display = 'none';
                overlay.style.display = 'none';
            }
        }

        document.addEventListener('click', function(event) {
            const popup = document.getElementById('popup');
            const overlay = document.getElementById('overlay');
            const button = event.target.closest('button');
            const closeIcon = event.target.closest('img[alt="Close"]');
            if (popup && overlay && popup.style.display === 'block' && !popup.contains(event.target) && !button && !closeIcon) {
                popup.style.display = 'none';
                overlay.style.display = 'none';
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('popup_selectable_boxes', 'popup_selectable_boxes_shortcode');








/**
 * Synchronize webinar product prices with ACF fields, prevent negative or invalid prices,
 * and set default ACF field values from product prices
 */

/**
 * Synchronize webinar product prices to ACF fields on product save
 *
 * @param int $product_id The ID of the WooCommerce product being updated
 */
function sync_webinar_product_to_acf($product_id) {
    static $is_syncing = false; // Prevent recursive loop
    if ($is_syncing) return;

    $is_syncing = true; // Set syncing flag

    // Verify if the post is a WooCommerce product
    if (get_post_type($product_id) !== 'product') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_webinar_product_to_acf: Not a product, post_id: ' . $product_id);
        }
        $is_syncing = false;
        return;
    }

    // Get related stm_course_id
    $stm_course_id = get_post_meta($product_id, 'related_stm_course_id', true);
    if (!$stm_course_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_webinar_product_to_acf: No related stm_course_id for product_id: ' . $product_id);
        }
        $is_syncing = false;
        return;
    }

    // Get related course page
    $course_page_id = get_post_meta($stm_course_id, 'related_course_id', true);
    if (!$course_page_id || get_post_type($course_page_id) !== 'course' || get_post_status($course_page_id) !== 'publish') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_webinar_product_to_acf: Invalid or missing course_page_id for stm_course_id: ' . $stm_course_id);
        }
        $is_syncing = false;
        return;
    }

    // Get product prices
    $regular_price = get_post_meta($product_id, '_regular_price', true) ?: 0;
    $sale_price = get_post_meta($product_id, '_sale_price', true);

    // Validate prices
    $regular_price = floatval($regular_price);
    $regular_price = $regular_price < 0 ? 0 : $regular_price; // Prevent negative regular price
    $sale_price = $sale_price !== '' ? floatval($sale_price) : '';
    if ($sale_price !== '') {
        $sale_price = $sale_price < 0 ? '' : $sale_price; // Prevent negative sale price
        $sale_price = $sale_price > $regular_price ? '' : $sale_price; // Prevent sale price > regular price
    }

    // Update ACF fields
    if (function_exists('update_field')) {
        // Check if prices have changed to avoid unnecessary updates
        $current_regular_price = get_field('field_6853a215dbd49', $course_page_id);
        $current_sale_price = get_field('field_6853a231dbd4a', $course_page_id);
        if ($current_regular_price != $regular_price || $current_sale_price != $sale_price) {
            update_field('field_6853a215dbd49', sanitize_text_field($regular_price), $course_page_id);
            update_field('field_6853a231dbd4a', $sale_price !== '' ? sanitize_text_field($sale_price) : '', $course_page_id);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('sync_webinar_product_to_acf: Updated ACF fields for course_page_id: ' . $course_page_id . ', regular_price: ' . $regular_price . ', sale_price: ' . ($sale_price !== '' ? $sale_price : 'none'));
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_webinar_product_to_acf: ACF update_field not available for course_page_id: ' . $course_page_id);
        }
    }

    $is_syncing = false; // Release syncing flag
}
add_action('woocommerce_update_product', 'sync_webinar_product_to_acf', 20);

/**
 * Synchronize ACF fields to webinar product prices on course save
 *
 * @param int $post_id The ID of the course post being updated
 */
function sync_acf_to_webinar_product($post_id) {
    static $is_syncing = false; // Prevent recursive loop
    if ($is_syncing) return;

    $is_syncing = true; // Set syncing flag

    // Verify if the post is a course and not a revision
    if (get_post_type($post_id) !== 'course' || wp_is_post_revision($post_id)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_acf_to_webinar_product: Not a course or is revision, post_id: ' . $post_id);
        }
        $is_syncing = false;
        return;
    }

    // Get related stm_course_id
    $stm_course_id = get_post_meta($post_id, 'related_stm_course_id', true);
    if (!$stm_course_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_acf_to_webinar_product: No related stm_course_id for course_page_id: ' . $post_id);
        }
        $is_syncing = false;
        return;
    }

    // Get related webinar product
    $webinar_product_id = get_post_meta($stm_course_id, 'related_webinar_product_id', true);
    if (!$webinar_product_id || get_post_type($webinar_product_id) !== 'product' || get_post_status($webinar_product_id) !== 'publish') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_acf_to_webinar_product: Invalid or missing webinar_product_id for stm_course_id: ' . $stm_course_id);
        }
        $is_syncing = false;
        return;
    }

    // Get ACF field values
    if (!function_exists('get_field')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_acf_to_webinar_product: ACF get_field not available for course_page_id: ' . $post_id);
        }
        $is_syncing = false;
        return;
    }

    $regular_price = get_field('field_6853a215dbd49', $post_id);
    $sale_price = get_field('field_6853a231dbd4a', $post_id);

    // Sanitize and validate prices
    $regular_price = $regular_price !== '' ? floatval($regular_price) : 0;
    $regular_price = $regular_price < 0 ? 0 : $regular_price; // Prevent negative regular price
    $sale_price = $sale_price !== '' ? floatval($sale_price) : '';
    if ($sale_price !== '') {
        $sale_price = $sale_price < 0 ? '' : $sale_price; // Prevent negative sale price
        $sale_price = $sale_price > $regular_price ? '' : $sale_price; // Prevent sale price > regular price
    }

    // Check if prices have changed to avoid unnecessary updates
    $current_regular_price = get_post_meta($webinar_product_id, '_regular_price', true);
    $current_sale_price = get_post_meta($webinar_product_id, '_sale_price', true);
    if ($current_regular_price != $regular_price || $current_sale_price != $sale_price) {
        // Update product prices
        update_post_meta($webinar_product_id, '_regular_price', $regular_price);
        update_post_meta($webinar_product_id, '_price', $sale_price !== '' ? $sale_price : $regular_price);
        if ($sale_price !== '') {
            update_post_meta($webinar_product_id, '_sale_price', $sale_price);
        } else {
            delete_post_meta($webinar_product_id, '_sale_price');
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('sync_acf_to_webinar_product: Updated webinar product prices for product_id: ' . $webinar_product_id . ', regular_price: ' . $regular_price . ', sale_price: ' . ($sale_price !== '' ? $sale_price : 'none'));
        }
    }

    $is_syncing = false; // Release syncing flag
}
add_action('acf/save_post', 'sync_acf_to_webinar_product', 20);

/**
 * Set default values for ACF price fields based on related WooCommerce product prices
 *
 * @param mixed $value   The field value
 * @param int   $post_id The post ID
 * @param array $field   The field array
 * @return mixed The default or original value
 */
function set_default_acf_price_fields($value, $post_id, $field) {
    // Only apply to course post type and specific price fields
    if (get_post_type($post_id) !== 'course' || wp_is_post_revision($post_id)) {
        return $value;
    }

    // Only set default if the field is empty
    if ($value !== '' && $value !== null) {
        return $value;
    }

    // Get related stm اولیه_id
    $stm_course_id = get_post_meta($post_id, 'related_stm_course_id', true);
    if (!$stm_course_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('set_default_acf_price_fields: No related stm_course_id for course_page_id: ' . $post_id);
        }
        return $value;
    }

    // Get related webinar product
    $webinar_product_id = get_post_meta($stm_course_id, 'related_webinar_product_id', true);
    if (!$webinar_product_id || get_post_type($webinar_product_id) !== 'product' || get_post_status($webinar_product_id) !== 'publish') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('set_default_acf_price_fields: Invalid or missing webinar_product_id for stm_course_id: ' . $stm_course_id);
        }
        return $value;
    }

    // Get product prices
    $regular_price = get_post_meta($webinar_product_id, '_regular_price', true) ?: 0;
    $sale_price = get_post_meta($webinar_product_id, '_sale_price', true);

    // Validate prices
    $regular_price = floatval($regular_price);
    $regular_price = $regular_price < 0 ? 0 : $regular_price; // Prevent negative regular price
    $sale_price = $sale_price !== '' ? floatval($sale_price) : '';
    if ($sale_price !== '') {
        $sale_price = $sale_price < 0 ? '' : $sale_price; // Prevent negative sale price
        $sale_price = $sale_price > $regular_price ? '' : $sale_price; // Prevent sale price > regular price
    }

    // Set default value based on field key
    if ($field['key'] === 'field_6853a215dbd49') {
        return $regular_price;
    } elseif ($field['key'] === 'field_6853a231dbd4a') {
        return $sale_price !== '' ? $sale_price : '';
    }

    return $value;
}
add_filter('acf/load_value', 'set_default_acf_price_fields', 10, 3);




/**
 * Shortcode to display webinar product prices with sale price below regular price
 */
function webinar_price_shortcode($atts) {
    $atts = shortcode_atts(['course_id' => 0], $atts, 'webinar_price');
    $course_page_id = absint($atts['course_id']);

    // Use current post ID if not provided
    if (!$course_page_id && is_singular('course')) {
        $course_page_id = get_the_ID();
    }

    if (!$course_page_id || get_post_type($course_page_id) !== 'course') {
        error_log('webinar_price_shortcode: Invalid or missing course_page_id: ' . $course_page_id);
        return '';
    }

    // Get related stm_course_id
    $stm_course_id = get_post_meta($course_page_id, 'related_stm_course_id', true);
    if (!$stm_course_id) {
        error_log('webinar_price_shortcode: No related stm_course_id for course_page_id: ' . $course_page_id);
        return '';
    }

    // Get related webinar product
    $webinar_product_id = get_post_meta($stm_course_id, 'related_webinar_product_id', true);
    if (!$webinar_product_id || get_post_type($webinar_product_id) !== 'product') {
        error_log('webinar_price_shortcode: Invalid or missing webinar_product_id for stm_course_id: ' . $stm_course_id);
        return '';
    }

    // Get prices from product (fallback to ACF if needed)
    $regular_price = get_post_meta($webinar_product_id, '_regular_price', true) ?: 0;
    $sale_price = get_post_meta($webinar_product_id, '_sale_price', true);

    // Format prices using WooCommerce, appending USD
    $formatted_regular_price = wc_price($regular_price) . ' USD';
    $formatted_sale_price = $sale_price !== '' ? wc_price($sale_price) . ' USD' : '';

    // Determine the lowest price for mobile display
    $lowest_price = ($sale_price !== '' && $sale_price < $regular_price) ? $formatted_sale_price : $formatted_regular_price;

    // Build output
    $output = '<div class="webinar-price">';
    $output .= '<div class="lowest-price-mobile">' . $lowest_price . '</div>'; // Lowest price for mobile
    if ($sale_price !== '' && $sale_price < $regular_price) {
        $output .= '<div class="woocommerce-Price-amount amount regular-price desktop-price"><del>' . $formatted_regular_price . '</del></div>';
        $output .= '<div class="woocommerce-Price-amount amount sale-price desktop-price">' . $formatted_sale_price . '</div>';
    } else {
        $output .= '<div class="woocommerce-Price-amount amount regular-price desktop-price">' . $formatted_regular_price . '</div>';
    }
    $output .= '</div>';

    error_log('webinar_price_shortcode: Rendered for course_page_id: ' . $course_page_id . ', regular_price: ' . $regular_price . ', sale_price: ' . ($sale_price !== '' ? $sale_price : 'none'));
    return $output;
}
add_shortcode('webinar_price', 'webinar_price_shortcode');

/**
 * Enqueue styles for the shortcode
 */
function webinar_price_shortcode_styles() {
    if (is_singular('course')) {
        wp_enqueue_style('webinar-price-style', false);
        wp_add_inline_style('woocommerce-general', '
            .webinar-price .regular-price {
                color: #FFF;
                display: block;
                margin-top: 5px;
            }
            .webinar-price .regular-price del {
                color: #999;
                text-decoration: line-through;
                font-size: 18px; /* Strikethrough regular price always 18px */
            }
            .webinar-price .sale-price {
                display: block;
                font-size: 30px; /* Sale price for desktop and popup */
                color: #FFF;
            }
            .webinar-price .regular-price:not(.sale-price) {
                font-size: 30px; /* Regular price when no sale for desktop and popup */
            }
            .webinar-price .lowest-price-mobile {
                display: none; /* Hidden by default */
                font-size: 20px; /* Lowest price in sticky CTA on mobile */
                color: #FFF; /* White for sticky CTA */
            }
            .selectable-box-container .webinar-price .lowest-price-mobile,
            .box-container .webinar-price .lowest-price-mobile {
                display: none !important; /* Always hidden in selectable-box-container and box-container */
            }
            .selectable-box-container .webinar-price .desktop-price,
            .box-container .webinar-price .desktop-price {
                display: block !important; /* Always show regular and sale prices in selectable-box-container and box-container */
            }
            #popup .webinar-price .lowest-price-mobile {
                display: none !important; /* Always hidden in popup */
            }
            #popup .webinar-price .desktop-price {
                display: block !important; /* Always show regular and sale prices in popup */
            }
            @media screen and (max-width: 767px) {
                .sticky-cta .webinar-price .desktop-price {
                    display: none !important; /* Hide regular and sale prices in sticky-cta on mobile */
                }
                .sticky-cta .webinar-price .lowest-price-mobile {
                    display: block !important; /* Show only lowest price in sticky-cta on mobile */
                }
            }
        ');
    }
}
add_action('wp_enqueue_scripts', 'webinar_price_shortcode_styles');





	
/**
 * Sync Strapi courses with WordPress ACF

function sync_strapi_courses($single_course_id = null) {
    // 1. Configurar la URL del API según si es un curso específico
    $url = 'https://deserving-cuddle-3fb76d5250.strapiapp.com/api/coursesv3s';
    if ($single_course_id) {
        $url .= '?filters[documentId][$eq]=' . urlencode($single_course_id);
    }

    // 2. Hacer solicitud al API de Strapi
    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization' => 'Bearer 0de0f745e9b03827b9fc9e21015b33061a3fa5ccab7a39cbda8a84889183637b96324ac9df4730eb212d9bd082d5aa66dca5ad2871f7c53ade4d6b34ad25e55582f9902b019a55fc3c65ae0dbbd0d0135d777a60c382f40e8dadaed1426cbbde7d422ebd64a59ab16653fd5d5f1d4f018d614452f2a31dbd789d8d541d3a5e9c'
        ]
    ]);

    // 3. Verificar si la solicitud fue exitosa
    if (is_wp_error($response)) {
        error_log('Error al conectar con Strapi: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        error_log('Respuesta vacía desde Strapi API');
        return false;
    }

    // 4. Convertir la respuesta JSON en array
    $courses = json_decode($body, true);
    if (!isset($courses['data'])) {
        error_log('Formato de datos inválido desde Strapi API');
        return false;
    }

    // 5. Ajustar datos para un solo curso o múltiples
    $courses_data = $single_course_id && isset($courses['data'][0]) ? [$courses['data'][0]] : $courses['data'];

    // 6. Iterar sobre los cursos
    foreach ($courses_data as $course) {
        // Extraer descripción como texto plano
        $description = '';
        if (!empty($course['CourseDescription']) && is_array($course['CourseDescription'])) {
            foreach ($course['CourseDescription'] as $paragraph) {
                if (isset($paragraph['children']) && is_array($paragraph['children'])) {
                    foreach ($paragraph['children'] as $child) {
                        if (isset($child['type']) && $child['type'] === 'text' && !empty($child['text'])) {
                            $description .= wp_kses_post($child['text']) . "\n\n";
                        }
                    }
                }
            }
        }

        // Extraer biografía del instructor como texto plano
        $instructor_bio = '';
        if (!empty($course['InstructorBiography']) && is_array($course['InstructorBiography'])) {
            foreach ($course['InstructorBiography'] as $paragraph) {
                if (isset($paragraph['children']) && is_array($paragraph['children'])) {
                    foreach ($paragraph['children'] as $child) {
                        if (isset($child['type']) && $child['type'] === 'text' && !empty($child['text'])) {
                            $instructor_bio .= wp_kses_post($child['text']) . "\n\n";
                        }
                    }
                }
            }
        }

        // 7. Verificar si el curso ya existe
        $existing_posts = get_posts([
            'post_type' => 'strapicourse',
            'meta_query' => [
                [
                    'key' => 'strapi_document_id',
                    'value' => $course['documentId'],
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1,
            'post_status' => ['publish', 'draft', 'pending', 'trash']
        ]);

        if (!empty($existing_posts)) {
            // Actualizar curso existente
            $post_id = $existing_posts[0]->ID;
            wp_update_post([
                'ID' => $post_id,
                'post_title' => sanitize_text_field($course['CourseTitle']),
                'post_status' => 'publish'
            ]);
        } else {
            // Crear nuevo curso
            $post_id = wp_insert_post([
                'post_type' => 'strapicourse',
                'post_title' => sanitize_text_field($course['CourseTitle']),
                'post_status' => 'draft',
                'meta_input' => [
                    'strapi_document_id' => sanitize_text_field($course['documentId']),
                    'strapi_course_id' => sanitize_text_field($course['id'])
                ]
            ]);
        }

        // 8. Actualizar campos ACF
        update_field('field_685b06d427518', sanitize_text_field($course['CourseTitle']), $post_id); // CourseTitle
        update_field('field_685b06e527519', wp_kses_post($description), $post_id); // CourseDescription
        update_field('field_685b06f02751a', floatval($course['CourseRegularPrice']), $post_id); // CourseRegularPrice
        update_field('field_685b06fc2751b', floatval($course['CourseSalesPrice']), $post_id); // CourseSalesPrice
        update_field('field_685e164734e9d', sanitize_text_field($course['Instructor'] ?? ''), $post_id); // Instructor
        update_field('field_685e165034e9e', sanitize_text_field($course['InstructorPosition'] ?? ''), $post_id); // InstructorPosition
        update_field('field_685e165734e9f', wp_kses_post($instructor_bio), $post_id); // InstructorBiography

        // Campos no presentes en el JSON (pueden requerir manejo manual o datos adicionales)
        update_field('field_685e16b634ea1', '', $post_id); // Instructor Photo (no en JSON)
        update_field('field_685e16ec34ea2', [], $post_id); // Course Lessons (no en JSON, asumir vacío)
        update_field('field_685e175534ea5', [], $post_id); // Webinar Dates (no en JSON, asumir vacío)
        update_field('field_685e176f34ea6', [], $post_id); // Portfolio (no en JSON, asumir vacío)
    }

    return true;
}

// 9. Sincronizar al cargar el dashboard (todos los cursos)
add_action('admin_init', function() {
    if (!get_transient('strapi_sync_admin_running')) {
        set_transient('strapi_sync_admin_running', true, 60);
        sync_strapi_courses();
        delete_transient('strapi_sync_admin_running');
    }
});

// 10. Sincronizar al cargar la página de un curso en el frontend
add_action('template_redirect', function() {
    if (is_singular('strapicourse')) {
        $transient_key = 'strapi_sync_frontend_' . get_the_ID();
        if (!get_transient($transient_key)) {
            set_transient($transient_key, true, 60);
            $document_id = get_post_meta(get_the_ID(), 'strapi_document_id', true);
            if ($document_id) {
                sync_strapi_courses($document_id);
            }
            delete_transient($transient_key);
        }
    }
});

 */
