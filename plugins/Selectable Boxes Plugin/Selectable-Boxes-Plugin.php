<?php
/*
Plugin Name: Selectable Boxes Plugin
Description: A plugin to create selectable boxes for courses with live course date options from ACF, dynamic launch countdown, an admin dropdown in course post type to show/hide course box, and saves selected start date to order metadata, displayed in orders and emails. Supports multiple products in FunnelKit Cart.
Version: 1.41
Author: Carlos Murillo
*/

// Ensure FunnelKit Cart and Checkout are enabled on course post type
add_filter('fkcart_disabled_post_types', function ($post_types) {
    $post_types = array_filter($post_types, function ($i) {
        return $i !== 'course';
    });
    return $post_types;
});

function selectable_boxes_shortcode() {
    global $post;
    $post_id = $post ? $post->ID : 0;
    
    // Debug log to verify shortcode is being called
    error_log('=== SELECTABLE BOXES SHORTCODE CALLED ===');
    error_log('Post ID: ' . $post_id);
    error_log('Request URI: ' . $_SERVER['REQUEST_URI']);
    error_log('Is AJAX: ' . (wp_doing_ajax() ? 'Yes' : 'No'));

    // Fetch ACF fields
    $course_product_link = get_field('field_6821879221940', $post_id);
    $enroll_product_link = get_field('field_6821879e21941', $post_id);
    $course_price = get_field('field_681ccc6eb123a', $post_id) ?: '749.99';

    // Get available start dates and stocks from ACF repeater field
    $available_dates = [];
    $date_stocks = [];
    $has_dates = false;
    $all_dates_sold_out = true;
    if (have_rows('field_6826dd2179231', $post_id)) {
        while (have_rows('field_6826dd2179231', $post_id)) {
            the_row();
            $date_text = get_sub_field('field_6826dfe2d7837');
            $stock = get_sub_field('field_684ba360c13e2'); // webinar_stock
            if (!empty($date_text)) {
                $sanitized_date = sanitize_text_field($date_text);
                $available_dates[] = $sanitized_date;
                $date_stocks[$sanitized_date] = is_numeric($stock) ? intval($stock) : 10;
                $has_dates = true;
                // Check if at least one date has stock
                if ($date_stocks[$sanitized_date] > 0) {
                    $all_dates_sold_out = false;
                }
            }
        }
    }

    // Handle case when no dates are available AND no product links
    if (empty($available_dates) && empty($course_product_link) && empty($enroll_product_link)) {
        error_log('Selectable Boxes Plugin: No start dates or product links available for post ID ' . $post_id);
        ob_start();
        ?>
        <div class="box-container">
            <div class="course-launch">
                <h3>Join Waitlist for Free</h3>
                <p class="launch-subline">Be the first to know when the course launches. No Spam. We Promise!</p>
                <div class="contact-form">[contact-form-7 id="255b390" title="Course Launch"]</div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Debug: Log field values
    error_log('Selectable Boxes Plugin: Post ID = ' . $post_id);
    error_log('Selectable Boxes Plugin: course_product_link = ' . ($course_product_link ?: 'empty'));
    error_log('Selectable Boxes Plugin: enroll_product_link = ' . ($enroll_product_link ?: 'empty'));
    error_log('Selectable Boxes Plugin: course_price = ' . $course_price);
    error_log('Selectable Boxes Plugin: available_dates = ' . implode(', ', $available_dates));

    $enroll_price = '1249.99';
    $is_out_of_stock = false;
    $is_enroll_out_of_stock = false;
    $course_product_id = 0;
    $enroll_product_id = 0;

    // Extract course product ID
    if (!empty($course_product_link)) {
        $url_parts = parse_url($course_product_link, PHP_URL_QUERY);
        parse_str($url_parts, $query_params);
        $course_product_id = isset($query_params['add-to-cart']) ? intval($query_params['add-to-cart']) : 0;

        if ($course_product_id && function_exists('wc_get_product')) {
            $product = wc_get_product($course_product_id);
            if ($product && !$product->is_in_stock()) {
                $is_out_of_stock = true;
            }
        }
    }

    // Extract enroll product ID and price
    if (!empty($enroll_product_link)) {
        $url_parts = parse_url($enroll_product_link, PHP_URL_QUERY);
        parse_str($url_parts, $query_params);
        $enroll_product_id = isset($query_params['add-to-cart']) ? intval($query_params['add-to-cart']) : 0;

        if ($enroll_product_id && function_exists('wc_get_product')) {
            $product = wc_get_product($enroll_product_id);
            if ($product) {
                $enroll_price = $product->get_price() ?: '1249.99';
                // Check if product is out of stock OR all dates are sold out
                if (!$product->is_in_stock() || $all_dates_sold_out) {
                    $is_enroll_out_of_stock = true;
                }
            }
        } else if ($all_dates_sold_out) {
            // Even if we can't get the product, mark as sold out if all dates are sold out
            $is_enroll_out_of_stock = true;
        }
    }

    // Get launch date
    $launch_date = $course_product_id ? apply_filters('wc_launch_date_get', '', $course_product_id) : '';
    $show_countdown = !empty($launch_date) && strtotime($launch_date) > current_time('timestamp');

    ob_start();
    ?>
    <div class="selectable-box-container">
        <div class="box-container">
            <?php 
            // Show waitlist ONLY when both products exist and both are sold out
            // OR when there are no products at all
            $both_exist_both_sold_out = !empty($course_product_link) && !empty($enroll_product_link) && $is_out_of_stock && $is_enroll_out_of_stock;
            $no_products = empty($course_product_link) && empty($enroll_product_link);
            ?>
            <?php if ($both_exist_both_sold_out) : ?>
                <div class="box soldout-course">
                    <div class="soldout-header"><span>SOLD OUT</span></div>
                    <h3>Join Waitlist for Free</h3>
                    <p class="description">Gain access to live streams, free credits for Arcana, and more.</p>
                    [contact-form-7 id="c2b4e27" title="Course Sold Out"]
                    <p class="terms">By signing up, you agree to the Terms & Conditions.</p>
                </div>
            <?php elseif ($no_products) : ?>
                <div class="box course-launch">
                    <h3>Join Waitlist for Free</h3>
                    <p class="description">Gain access to live streams, free credits for Arcana, and more.</p>
                    [contact-form-7 id="255b390" title="Course Launch"]
                    <p class="terms">By signing up, you agree to the Terms & Conditions.</p>
                </div>
            <?php elseif ($show_countdown && empty($enroll_product_link)) : ?>
                <div class="box course-launch">
                    <div class="countdown">
                        <span>COURSE LAUNCH IN:</span>
                        <div class="countdown-timer" id="countdown-timer" data-launch-date="<?php echo esc_attr($launch_date); ?>">
                            <?php
                            if ($show_countdown) {
                                $time_diff = strtotime($launch_date) - current_time('timestamp');
                                $days = floor($time_diff / (60 * 60 * 24));
                                $hours = floor(($time_diff % (60 * 60 * 24)) / (60 * 60));
                                $minutes = floor(($time_diff % (60 * 60)) / 60);
                                $seconds = $time_diff % 60;
                                ?>
                                <div class="time-unit" data-unit="days">
                                    <span class="time-value"><?php echo esc_html(sprintf('%02d', $days)); ?></span>
                                    <span class="time-label">days</span>
                                </div>
                                <div class="time-unit" data-unit="hours">
                                    <span class="time-value"><?php echo esc_html(sprintf('%02d', $hours)); ?></span>
                                    <span class="time-label">hrs</span>
                                </div>
                                <div class="time-unit" data-unit="minutes">
                                    <span class="time-value"><?php echo esc_html(sprintf('%02d', $minutes)); ?></span>
                                    <span class="time-label">min</span>
                                </div>
                                <div class="time-unit" data-unit="seconds">
                                    <span class="time-value"><?php echo esc_html(sprintf('%02d', $seconds)); ?></span>
                                    <span class="time-label">sec</span>
                                </div>
                                <?php
                            } else {
                                echo '<span class="launch-soon">Launching Soon</span>';
                            }
                            ?>
                        </div>
                    </div>
                    <h3>Join Waitlist for Free</h3>
                    <p class="description">Gain access to live streams, free credits for Arcana, and more.</p>
                    [contact-form-7 id="255b390" title="Course Launch"]
                    <p class="terms">By signing up, you agree to the Terms & Conditions.</p>
                </div>
            <?php else : ?>
                <?php if (!empty($course_product_link)) : ?>
                    <div class="box buy-course<?php echo empty($enroll_product_link) ? ' selected' : ''; ?>" <?php echo !empty($enroll_product_link) ? 'onclick="selectBox(this, \'box1\')"' : 'style="cursor: default;"'; ?>>
                        <div class="statebox">
                            <div class="circlecontainer" style="display: <?php echo empty($enroll_product_link) ? 'flex' : 'none'; ?>;">
                                <div class="outer-circle">
                                    <div class="middle-circle">
                                        <div class="inner-circle"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="circle-container" style="display: <?php echo empty($enroll_product_link) ? 'none' : 'flex'; ?>;">
                                <div class="circle"></div>
                            </div>
                            <div>
                                <h3>Buy This Course</h3>
                                <p>[course_price]</p>
                                <p class="description">Pay once. Get instant access to the full course.</p>
                            </div>
                        </div>
                        <button class="add-to-cart-button" 
                                data-product-id="<?php echo esc_attr($course_product_id); ?>" 
                                <?php echo $is_out_of_stock ? 'disabled' : ''; ?> 
                                style="<?php echo empty($enroll_product_link) ? 'display: block;' : ''; ?>"
                                onclick="return handleAddToCart(this, '<?php echo esc_attr($course_product_id); ?>', false);">
                            <span class="button-text"><?php echo $is_out_of_stock ? 'Sold Out' : 'Buy Course'; ?></span>
                            <span class="loader" style="display: none;"></span>
                        </button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($enroll_product_link)) : ?>
                <div class="box enroll-course<?php echo empty($course_product_link) ? ' selected' : ''; ?>" <?php echo !empty($course_product_link) ? 'onclick="selectBox(this, \'box2\')"' : 'style="cursor: default;"'; ?>>
                    <div class="statebox">
                        <div class="circlecontainer" style="display: <?php echo empty($course_product_link) ? 'flex' : 'none'; ?>;">
                            <div class="outer-circle">
                                <div class="middle-circle">
                                    <div class="inner-circle"></div>
                                </div>
                            </div>
                        </div>
                        <div class="circle-container" style="display: <?php echo empty($course_product_link) ? 'none' : 'flex'; ?>;">
                            <div class="circle"></div>
                        </div>
                        <div>
                            <h3>Enroll in the Live Course</h3>
                            <p>[webinar_price]</p>
                            <p class="description">Join weekly live sessions with feedback and expert mentorship. Pay Once.</p>
                        </div>
                    </div>
                    <hr class="divider">
                    <div class="start-dates" style="display: <?php echo empty($course_product_link) ? 'block' : 'none'; ?>;">
                        <p class="choose-label">Choose a starting date</p>
                        <div class="date-options">
                            <?php
                            foreach ($available_dates as $date) {
                                $stock = isset($date_stocks[$date]) ? $date_stocks[$date] : 0;
                                $disabled = $stock <= 0 ? 'disabled' : '';
                                $class = $stock <= 0 ? 'date-btn sold-out' : 'date-btn';
                                $label = $stock <= 0 ? $date . ' (Sold Out)' : $date;
                                echo '<button class="' . esc_attr($class) . '" data-date="' . esc_attr($date) . '" data-stock="' . esc_attr($stock) . '" ' . $disabled . '>' . esc_html($label) . '</button>';
                            }
                            ?>
                        </div>
                    </div>
                    <button class="add-to-cart-button" 
                            data-product-id="<?php echo esc_attr($enroll_product_id); ?>" 
                            <?php echo $is_enroll_out_of_stock ? 'disabled' : ''; ?>
                            onclick="return handleAddToCart(this, '<?php echo esc_attr($enroll_product_id); ?>', true);">
                        <span class="button-text"><?php echo $is_enroll_out_of_stock ? 'Sold Out' : 'Enroll Now'; ?></span>
                        <span class="loader" style="display: none;"></span>
                    </button>
                    [seats_remaining]
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <style>
        .selectable-box-container {
            max-width: 350px;
        }

        .box-container {
            padding: 0;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            justify-content: center;
        }

        .box-container .box {
            position: relative;
            max-width: 350px;
            width: 100%;
            padding: 15px;
            background: transparent;
            border: 2px solid #9B9FAA7A;
            border-radius: 15px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
            box-sizing: border-box;
        }

        .box-container .box.selected {
            background: linear-gradient(180deg, rgba(242, 46, 190, 0.2), rgba(170, 0, 212, 0.2)) !important;
            border: none;
            padding: 16px 12px;
        }
        
        /* Ensure gradient shows in popup */
        #popup .box-container .box.selected {
            background: linear-gradient(180deg, rgba(242, 46, 190, 0.2), rgba(170, 0, 212, 0.2)) !important;
        }
        
        /* Force gradient for single buy-course box with selected class */
        .box-container .box.buy-course.selected:only-child {
            background: linear-gradient(180deg, rgba(242, 46, 190, 0.2), rgba(170, 0, 212, 0.2)) !important;
        }

        .box-container .box:not(.selected) {
            opacity: 0.7;
        }
        
        /* When there's only one box, remove hover effects and opacity changes */
        .box-container .box:only-child {
            opacity: 1 !important;
            cursor: default !important;
        }
        
        .box-container .box:only-child:hover {
            transform: none !important;
        }
        
        /* Ensure single selected box shows gradient */
        .box-container .box.buy-course:only-child.selected,
        .box-container .box.enroll-course:only-child.selected {
            background: linear-gradient(180deg, rgba(242, 46, 190, 0.2), rgba(170, 0, 212, 0.2)) !important;
        }

        .box-container .box.no-button button {
            display: none;
        }

        .box-container .box h3 {
            color: #fff;
            margin-left: 10px;
            margin-top: 0;
            font-size: 1.5em;
        }

        .box-container .box .price {
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
            font-size: 26px;
            line-height: 135%;
            letter-spacing: 0.48px;
            text-transform: capitalize;
        }

        .box-container .box .description {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.64);
            margin: 10px 0;
        }

        .box-container .box button {
            width: 100%;
            padding: 5px 12px;
            background-color: rgba(255, 255, 255, 0.08);
            border: none;
            border-radius: 4px;
            color: white;
            font-size: 12px;
            cursor: pointer;
        }

        .box-container .box button:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .box-container .box button:disabled {
            background-color: #555;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .box-container .divider {
            border: none;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px 0;
        }

        .box-container .box:not(.selected) button {
            background-color: #cc3071;
        }

        .box-container .soldout-course,
        .box-container .course-launch {
            background: #2a2a2a;
            text-align: center;
        }

        .box-container .soldout-header {
            background: #ff3e3e;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .box-container .countdown {
            background: #800080;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .box-container .countdown-timer {
            display: flex;
            gap: 15px;
        }

        .box-container .time-unit {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .box-container .time-value {
            font-size: 1.5em;
            font-weight: bold;
        }

        .box-container .time-label {
            font-size: 0.9em;
            color: rgba(255, 255, 255, 0.8);
        }

        .box-container .countdown span:first-child {
            display: none;
        }

        .box-container .terms {
            font-size: 0.7em;
            color: #aaa;
        }

        .box-container .start-dates {
            display: none;
            margin-top: 15px;
            animation: fadeIn 0.4s ease;
        }

        .box-container .box.selected .start-dates {
            display: block;
        }

        .box-container .statebox {
            display: flex;
        }

        .box-container .outer-circle {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: #DE04A4;
            border: 1.45px solid #DE04A4;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .box-container .middle-circle {
            width: 11.77px;
            height: 11.77px;
            border-radius: 50%;
            background-color: #050505;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .box-container .inner-circle {
            width: 6.16px;
            height: 6.16px;
            border-radius: 50%;
            background-color: #DE04A4;
        }

        .box-container .circlecontainer {
            margin: 6px 7px;
        }

        .box-container .circle-container {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .box-container .circle {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(155, 159, 170, 0.24);
        }

        .box-container .box:not(.selected) .circlecontainer {
            display: none;
        }

        .box-container .box:not(.selected) .circle-container {
            display: flex;
        }

        .box-container .box.selected .circle-container {
            display: none;
        }

        .box-container .box.selected .circlecontainer {
            display: flex;
        }

        .box-container .choose-label {
            font-size: 0.95em;
            margin-bottom: 10px;
            color: #fff;
        }

        .box-container .date-options {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .box-container .date-btn {
            width: auto;
            min-width: 68px;
            padding: 5px 8px;
            border: none;
            border-radius: 25px;
            background-color: rgba(255, 255, 255, 0.08);
            color: white;
            cursor: pointer;
        }

        .box-container .date-btn:hover:not(:disabled),
        .box-container .date-btn.selected:not(:disabled) {
            background-color: #cc3071;
        }

        .box-container .date-btn.sold-out,
        .box-container .date-btn:disabled {
            background-color: #555;
            color: #999;
            cursor: not-allowed;
            opacity: 0.6;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 767px) {
            .box-container .box {
                padding: 10px;
            }
            .box-container .box h3 {
                font-size: 1.2em;
            }
            
            /* Ensure single Buy Course box shows gradient on mobile */
            .box-container .box.buy-course:only-child.selected,
            .box-container .box.buy-course:only-child {
                background: linear-gradient(180deg, rgba(242, 46, 190, 0.2), rgba(170, 0, 212, 0.2)) !important;
                border: none !important;
                opacity: 1 !important;
            }
            
            /* Prevent pointer events on single box container but allow on button */
            .box-container .box.buy-course:only-child {
                pointer-events: none !important;
            }
            
            /* Ensure button is visible and clickable for single box on mobile */
            .box-container .box.buy-course:only-child .add-to-cart-button {
                display: block !important;
                pointer-events: auto !important;
            }
        }

.add-to-cart-button {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 40px; /* Fixed height to match original styling */
    min-height: 40px; /* Prevent shrinking */
    max-height: 40px; /* Prevent expansion */
    padding: 5px 12px;
    background-color: rgba(255, 255, 255, 0.08);
    border: none;
    border-radius: 4px;
    color: white;
    font-size: 12px;
    cursor: pointer;
    box-sizing: border-box;
    overflow: hidden; /* Ensure loader doesn't overflow */
}

.add-to-cart-button.loading .button-text {
    visibility: hidden;
}

.add-to-cart-button.loading .loader {
    display: inline-block;
}

.loader {
    width: 8px; /* Smaller size to prevent overflow */
    height: 8px; /* Smaller size to prevent overflow */
    border: 2px solid transparent;
    border-top-color: #fff; /* White color as requested */
    border-radius: 50%;
    animation: spin 1s linear infinite;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    margin: 0; /* Remove any default margins */
}
.loading:before{
height: 20px!important;
width: 20px!important;
border:2px solid rgb(255 255 255 / 50%)!important;
margin-left:0px!important;
top: 7px!important;
left: 45%!important;
right: 40%!important;
}

@keyframes spin {
    to { transform: translate(-50%, -50%) rotate(360deg); }
}
    </style>

    <script type="text/javascript">
        // Define the unified add to cart handler
        window.handleAddToCart = function(button, productId, isEnroll) {
            console.log('=== handleAddToCart called ===');
            console.log('Product ID:', productId);
            console.log('Is Enroll:', isEnroll);
            console.log('Button element:', button);
            
            // Prevent default action
            event.preventDefault();
            event.stopPropagation();
            
            // Check if jQuery is available
            if (typeof jQuery === 'undefined') {
                alert('Error: jQuery is not loaded. Please refresh the page.');
                return false;
            }
            
            // Get selected date if it's an enroll button
            var selectedDate = '';
            if (isEnroll) {
                var selectedDateBtn = button.closest('.enroll-course').querySelector('.date-btn.selected');
                if (selectedDateBtn) {
                    selectedDate = selectedDateBtn.getAttribute('data-date') || selectedDateBtn.textContent.trim();
                    console.log('Selected date:', selectedDate);
                } else {
                    // Try to find first available date
                    var firstDateBtn = button.closest('.enroll-course').querySelector('.date-btn:not(.sold-out)');
                    if (firstDateBtn) {
                        firstDateBtn.classList.add('selected');
                        selectedDate = firstDateBtn.getAttribute('data-date') || firstDateBtn.textContent.trim();
                        console.log('Auto-selected first available date:', selectedDate);
                    }
                }
                
                if (!selectedDate) {
                    alert('Please select a start date before adding to cart.');
                    return false;
                }
            }
            
            // Show loading state
            button.classList.add('loading');
            
            // Prepare AJAX data
            var data = {
                action: 'woocommerce_add_to_cart',
                product_id: productId,
                quantity: 1
            };
            
            if (isEnroll && selectedDate) {
                data.start_date = selectedDate;
            }
            
            console.log('Sending AJAX request with data:', data);
            
            // Send AJAX request
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response) {
                console.log('AJAX response received:', response);
                
                if (response && response.fragments) {
                    console.log('Product added successfully');
                    
                    // Trigger WooCommerce events
                    jQuery(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash]);
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    
                    // Open FunnelKit cart
                    setTimeout(function() {
                        jQuery(document).trigger('fkcart_open_cart');
                        jQuery('.fkcart-cart-toggle').trigger('click');
                        
                        // Try specific FunnelKit method
                        if (typeof window.fkcart_open_cart === 'function') {
                            window.fkcart_open_cart();
                        }
                    }, 500);
                    
                } else if (response && response.error && response.product_url) {
                    // Fallback for products needing options
                    console.log('Using fallback method');
                    var cartUrl = '<?php echo wc_get_cart_url(); ?>?add-to-cart=' + productId;
                    if (isEnroll && selectedDate) {
                        cartUrl += '&start_date=' + encodeURIComponent(selectedDate);
                    }
                    
                    jQuery.get(cartUrl, function() {
                        jQuery(document.body).trigger('wc_fragment_refresh');
                        jQuery(document).trigger('fkcart_open_cart');
                    });
                } else {
                    console.error('Error in response:', response);
                    alert('Error adding product to cart. Please try again.');
                }
            }).fail(function(jqXHR, textStatus) {
                console.error('AJAX failed:', textStatus);
                alert('Error communicating with server. Please try again.');
            }).always(function() {
                // Remove loading state
                button.classList.remove('loading');
            });
            
            return false;
        };
        
        // Debug immediately when script loads
        console.log('=== SELECTABLE BOXES SCRIPT LOADED (MAIN PAGE) ===');
        <?php error_log('=== JAVASCRIPT BLOCK RENDERED IN SHORTCODE ==='); ?>
        
        // Check jQuery availability
        if (typeof jQuery === 'undefined') {
            console.error('CRITICAL: jQuery is not available!');
            alert('ERROR: jQuery is not loaded on this page!');
            // Try to log to server without jQuery
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('action=log_js_debug&message=ERROR: jQuery not found on main page');
        } else {
            console.log('jQuery is available, version:', jQuery.fn.jquery);
            // Log to server
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'log_js_debug',
                message: 'Main page script loaded. jQuery: ' + jQuery.fn.jquery + ', URL: ' + window.location.href
            });
        }
        
        let selectedDate = '';
        let wasCartOpened = false;
        let wasCartManuallyClosed = false;

        function selectBox(element, boxId) {
            document.querySelectorAll('.box').forEach(box => {
                box.classList.remove('selected');
                box.classList.add('no-button');
                const circleContainer = box.querySelector('.circle-container');
                const circlecontainer = box.querySelector('.circlecontainer');
                const startDates = box.querySelector('.start-dates');
                if (circleContainer) circleContainer.style.display = 'flex';
                if (circlecontainer) circlecontainer.style.display = 'none';
                if (startDates) startDates.style.display = 'none';
            });
            element.classList.add('selected');
            element.classList.remove('no-button');
            const selectedCircleContainer = element.querySelector('.circle-container');
            const selectedCirclecontainer = element.querySelector('.circlecontainer');
            const selectedStartDates = element.querySelector('.start-dates');
            if (selectedCircleContainer) selectedCircleContainer.style.display = 'none';
            if (selectedCirclecontainer) selectedCirclecontainer.style.display = 'flex';
            if (selectedStartDates) selectedStartDates.style.display = 'block';
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function openFunnelKitCart() {
            console.log('openFunnelKitCart called');
            wasCartOpened = false;
            wasCartManuallyClosed = false;

            return new Promise((resolve) => {
                console.log('Triggering wc_fragment_refresh');
                jQuery(document.body).trigger('wc_fragment_refresh');

                const checkVisibility = () => {
                    const sidebar = document.querySelector('#fkcart-sidecart, .fkcart-sidebar, .fk-cart-panel, .fkcart-cart-sidebar, .cart-sidebar, .fkcart-panel');
                    if (sidebar) {
                        const isVisible = sidebar.classList.contains('fkcart-active') ||
                                          sidebar.classList.contains('active') ||
                                          sidebar.classList.contains('fkcart-open') ||
                                          window.getComputedStyle(sidebar).display !== 'none' ||
                                          window.getComputedStyle(sidebar).visibility !== 'hidden';
                        console.log('Sidebar visibility check - Classes:', sidebar.classList, 'IsVisible:', isVisible);
                        return isVisible;
                    }
                    console.log('No sidebar element found');
                    return false;
                };

                try {
                    console.log('Triggering fkcart_open_cart');
                    jQuery(document).trigger('fkcart_open_cart');

                    const toggles = ['.fkcart-mini-open', '.fkcart-toggle', '[data-fkcart-open]', '.fkcart-cart-toggle', '.cart-toggle', '.fkcart-open'];
                    let toggleClicked = false;
                    toggles.forEach(selector => {
                        const toggle = document.querySelector(selector);
                        if (toggle && !toggleClicked) {
                            console.log('Clicking toggle:', selector);
                            toggle.click();
                            toggleClicked = true;
                        } else {
                            console.log('No toggle found for selector:', selector);
                        }
                    });

                    const sidebars = ['#fkcart-sidecart', '.fkcart-sidebar', '.fk-cart-panel', '.fkcart-cart-sidebar', '.cart-sidebar, .fkcart-panel'];
                    let sidebarActivated = false;
                    sidebars.forEach(selector => {
                        const sidebar = document.querySelector(selector);
                        if (sidebar && !sidebarActivated) {
                            console.log('Activating sidebar:', selector);
                            sidebar.classList.add('fkcart-active', 'active', 'fkcart-open');
                            sidebarActivated = true;
                        } else {
                            console.log('No sidebar found for selector:', selector);
                        }
                    });

                    if (checkVisibility()) {
                        console.log('Sidebar visible after initial attempt');
                        wasCartOpened = true;
                        resolve(true);
                        return;
                    }

                    setTimeout(() => {
                        if (checkVisibility()) {
                            console.log('Sidebar visible after delay');
                            wasCartOpened = true;
                            resolve(true);
                        } else if (wasCartManuallyClosed) {
                            console.log('Cart was manually closed, resolving');
                            resolve(true);
                        } else {
                            console.log('Sidebar not visible, resolving without alert');
                            resolve(wasCartOpened);
                        }
                    }, 1000);
                } catch (error) {
                    console.error('Error in openFunnelKitCart:', error);
                    resolve(wasCartOpened || wasCartManuallyClosed);
                }
            });
        }

        function getCartContents() {
            return new Promise((resolve) => {
                console.log('Fetching current cart contents');
                const ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=get_refreshed_fragments&_=' + new Date().getTime();
                console.log('Cart contents AJAX URL:', ajaxUrl);
                jQuery.get(ajaxUrl, function (response) {
                    console.log('Cart contents response:', response);
                    resolve(response);
                }).fail(function (jqXHR, textStatus, errorThrown) {
                    console.error('Failed to fetch cart contents:', textStatus, errorThrown);
                    resolve(null);
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            console.log('DOM loaded, initializing selectable boxes');
            
            // Send debug log to server
            if (typeof jQuery !== 'undefined') {
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'log_js_debug',
                    message: 'Selectable boxes JS initialized at: ' + window.location.href,
                    nonce: '<?php echo wp_create_nonce('log_js_debug'); ?>'
                });
            }
            const enrollBox = document.querySelector('.enroll-course');
            const courseBox = document.querySelector('.buy-course');
            const isMobile = window.innerWidth <= 767;

            // If only one box exists, ensure it's properly selected
            if (courseBox && !enrollBox) {
                console.log('Only course box exists, ensuring it is selected');
                
                // Function to apply selected state
                function applySelectedState() {
                    courseBox.classList.add('selected');
                    courseBox.classList.remove('no-button');
                    const circleContainer = courseBox.querySelector('.circle-container');
                    const circlecontainer = courseBox.querySelector('.circlecontainer');
                    if (circleContainer) circleContainer.style.display = 'none';
                    if (circlecontainer) circlecontainer.style.display = 'flex';
                    
                    // Make button visible
                    const button = courseBox.querySelector('.add-to-cart-button');
                    if (button) {
                        button.style.display = 'block';
                        button.style.pointerEvents = 'auto';
                    }
                }
                
                // Apply immediately
                applySelectedState();
                
                // Prevent clicks on the box itself
                courseBox.style.cursor = 'default';
                courseBox.onclick = function(e) {
                    if (!e.target.classList.contains('add-to-cart-button') && 
                        !e.target.closest('.add-to-cart-button')) {
                        e.preventDefault();
                        e.stopPropagation();
                        applySelectedState();
                    }
                };
                
                // Force maintain selected state continuously
                setInterval(applySelectedState, 50);
                
                // Also use MutationObserver to detect class changes
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                            if (!courseBox.classList.contains('selected')) {
                                applySelectedState();
                            }
                        }
                    });
                });
                
                observer.observe(courseBox, {
                    attributes: true,
                    attributeFilter: ['class']
                });
            } else if (enrollBox && !courseBox) {
                console.log('Only enroll box exists, ensuring it is selected');
                // Ensure the box has selected class and proper visual state
                if (!enrollBox.classList.contains('selected')) {
                    enrollBox.classList.add('selected');
                }
                enrollBox.classList.remove('no-button');
                const circleContainer = enrollBox.querySelector('.circle-container');
                const circlecontainer = enrollBox.querySelector('.circlecontainer');
                const startDates = enrollBox.querySelector('.start-dates');
                if (circleContainer) circleContainer.style.display = 'none';
                if (circlecontainer) circlecontainer.style.display = 'flex';
                if (startDates) startDates.style.display = 'block';
            } else if (courseBox && enrollBox) {
                // Both boxes exist, use the default selection logic
                if (courseBox.classList.contains('selected')) {
                    console.log('Course box already selected by default');
                    selectBox(courseBox, 'box1');
                } else if (enrollBox.classList.contains('selected')) {
                    console.log('Enroll box already selected by default');
                    selectBox(enrollBox, 'box2');
                } else {
                    // No default selection from PHP, select course box
                    console.log('Selecting course box by default');
                    selectBox(courseBox, 'box1');
                }
            }

            // Select first available date (not sold out)
            const firstAvailableDateBtn = document.querySelector('.enroll-course .date-btn:not(.sold-out)');
            if (firstAvailableDateBtn) {
                firstAvailableDateBtn.classList.add('selected');
                selectedDate = firstAvailableDateBtn.getAttribute('data-date') || firstAvailableDateBtn.textContent.trim();
                console.log('Default selected date:', selectedDate);
            } else {
                // All dates are sold out
                const enrollButton = document.querySelector('.enroll-course .add-to-cart-button');
                if (enrollButton) {
                    enrollButton.disabled = true;
                    const buttonText = enrollButton.querySelector('.button-text');
                    if (buttonText) buttonText.textContent = 'Sold Out';
                }
            }

            document.querySelectorAll('.date-btn').forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (this.disabled || this.classList.contains('sold-out')) {
                        console.log('Date is sold out, cannot select');
                        return;
                    }
                    document.querySelectorAll('.date-btn').forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    selectedDate = this.getAttribute('data-date') || this.textContent.trim().replace(' (Sold Out)', '');
                    console.log('Updated selected date:', selectedDate);
                });
            });

            document.querySelectorAll('.add-to-cart-button').forEach(button => {
                console.log('Attaching listener to button:', button);
                button.addEventListener('click', async function (e) {
                    console.log('=== ADD TO CART BUTTON CLICKED (MAIN) ===');
                    console.log('Button element:', this);
                    console.log('Button HTML:', this.outerHTML);
                    console.log('Parent container:', this.closest('.box')?.className);
                    console.log('Is in popup?:', this.closest('#popup') !== null);
                    
                    // Send click log to server
                    if (typeof jQuery !== 'undefined') {
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'log_js_debug',
                            message: 'MAIN BUTTON CLICKED! Product: ' + this.getAttribute('data-product-id')
                        });
                    }
                    
                    e.preventDefault();
                    const productId = this.getAttribute('data-product-id');
                    const isBuyButton = this.closest('.buy-course') !== null;
                    
                    console.log('Product ID:', productId);
                    console.log('Is Buy button:', isBuyButton);
            
                    if (!productId || productId === '0') {
                        console.error('Invalid product ID');
                        alert('Error: Invalid product. Please try again.');
                        return;
                    }
            
                    const isEnrollButton = this.closest('.enroll-course') !== null;
                    console.log('Is enroll button:', isEnrollButton);
                    console.log('Selected date:', selectedDate);
                    console.log('Window.selectedDate:', window.selectedDate);
                    
                    if (isEnrollButton && !selectedDate) {
                        console.error('No start date selected for enroll course');
                        alert('Please select a start date before adding to cart.');
                        return;
                    }
            
                    // Show loader
                    console.log('Adding loading class to button');
                    this.classList.add('loading');
            
                    const addToCart = (productId, startDate = null) => {
                        return new Promise((resolve, reject) => {
                            const data = {
                                action: 'woocommerce_add_to_cart',
                                product_id: productId,
                                quantity: 1,
                                security: '<?php echo wp_create_nonce('woocommerce_add_to_cart'); ?>'
                            };
            
                            if (startDate) {
                                data.start_date = startDate;
                            }
                            
                            console.log('=== AJAX REQUEST TO ADD TO CART ===');
                            console.log('Request URL:', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>');
                            console.log('Request data:', data);
            
                            jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', data, function (response) {
                                console.log('=== AJAX RESPONSE RECEIVED ===');
                                console.log('Full response:', response);
                                console.log('Response type:', typeof response);
                                console.log('Has error?:', response?.error);
                                console.log('Has fragments?:', response?.fragments);
                                console.log('Has cart_hash?:', response?.cart_hash);
                                
                                // Check if WooCommerce returned an error but with a product URL (common for products needing options)
                                if (response && response.error && response.product_url) {
                                    console.log('Error with product URL detected, using fallback method');
                                    // Use direct cart URL method as fallback
                                    const cartUrl = '<?php echo esc_url(wc_get_cart_url()); ?>?add-to-cart=' + productId;
                                    console.log('Fallback cart URL:', cartUrl);
                                    
                                    // Add via direct URL then refresh fragments
                                    jQuery.get(cartUrl, function() {
                                        console.log('Fallback method succeeded');
                                        jQuery(document.body).trigger('wc_fragment_refresh');
                                        resolve({fragments: {}, cart_hash: 'direct-add'});
                                    }).fail(function(jqXHR, textStatus, errorThrown) {
                                        console.error('Fallback method failed:', textStatus, errorThrown);
                                        reject(new Error('Failed to add product to cart.'));
                                    });
                                } else if (response && response.fragments && response.cart_hash) {
                                    console.log('Success: Product added to cart normally');
                                    resolve(response);
                                } else {
                                    console.error('Unexpected response format:', response);
                                    reject(new Error('Failed to add product to cart.'));
                                }
                            }).fail(function (jqXHR, textStatus, errorThrown) {
                                console.error('=== AJAX REQUEST FAILED ===');
                                console.error('Status:', jqXHR.status);
                                console.error('Response text:', jqXHR.responseText);
                                console.error('Text status:', textStatus);
                                console.error('Error thrown:', errorThrown);
                                reject(new Error('Error communicating with the server: ' + textStatus));
                            });
                        });
                    };
            
                    const addProduct = async () => {
                        try {
                            console.log('=== STARTING ADD PRODUCT PROCESS ===');
                            const cartContents = await getCartContents();
                            console.log('Current cart contents before adding:', cartContents);
            
                            console.log('Calling addToCart with:', {
                                productId: productId,
                                startDate: isEnrollButton ? selectedDate : null
                            });
                            
                            const response = await addToCart(productId, isEnrollButton ? selectedDate : null);
                            
                            console.log('Add to cart response received:', response);
                            
                            // Handle both normal and direct-add responses
                            if (response.cart_hash === 'direct-add') {
                                console.log('Using direct-add method, triggering fragment refresh');
                                jQuery(document.body).trigger('wc_fragment_refresh');
                                // Wait a bit for the cart to update
                                await new Promise(resolve => setTimeout(resolve, 500));
                            } else {
                                console.log('Normal add to cart, triggering events');
                                jQuery(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash]);
                                jQuery(document.body).trigger('wc_fragment_refresh');
                            }
            
                            setTimeout(() => {
                                console.log('Forcing delayed cart refresh after 1 second');
                                jQuery(document.body).trigger('wc_fragment_refresh');
                                jQuery(document).trigger('fkcart_open_cart');
                            }, 1000);
            
                            const updatedCartContents = await getCartContents();
                            console.log('Cart contents after adding product:', updatedCartContents);
            
                            console.log('Attempting to open FunnelKit Cart...');
                            const cartOpened = await openFunnelKitCart();
                            console.log('Cart opened result:', cartOpened);
                            
                            if (!cartOpened && !wasCartOpened && !wasCartManuallyClosed) {
                                console.warn('⚠️ Cart failed to open automatically');
                                alert('The cart may not have updated. Please check the cart manually.');
                            } else {
                                console.log('✅ Product successfully added to cart!');
                            }
                        } catch (error) {
                            console.error('❌ Error in addProduct:', error);
                            console.error('Error stack:', error.stack);
                            alert('Error adding product to cart: ' + error.message);
                        } finally {
                            // Hide loader
                            console.log('Removing loading class from button');
                            button.classList.remove('loading');
                        }
                    };
            
                    addProduct();
                });
            });

            jQuery(document).on('click', '.wfacp_mb_mini_cart_sec_accordion', function (e) {
                console.log('Order Summary toggle clicked');
                try {
                    const $this = jQuery(this);
                    const content = $this.next('.wfacp_mb_mini_cart_sec_accordion_content');
                    if (content.length) {
                        console.log('Order Summary content found, toggling display');
                        content.toggle();
                        jQuery(document.body).trigger('wc_fragment_refresh');
                        console.log('wc_fragment_refresh triggered for order summary');
                    } else {
                        console.warn('Order Summary content not found');
                    }
                } catch (error) {
                    console.error('Error toggling Order Summary:', error);
                    alert('Error loading order summary. Please refresh the page and try again.');
                }
            });

            document.addEventListener('click', function (e) {
                if (e.target.closest('.fkcart-close, .fkcart-cart-close, .cart-close, .fkcart-close-btn, .fkcart-panel-close, [data-fkcart-close], .close-cart')) {
                    console.log('Cart sidebar close button clicked');
                    wasCartManuallyClosed = true;
                }
            });

            document.querySelectorAll('.fkcart-cart-toggle, .cart-toggle').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    console.log('Manual cart toggle clicked, forcing refresh');
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    jQuery(document.body).trigger('wc_update_cart');
                });
            });

            const countdownElement = document.getElementById('countdown-timer');
            if (countdownElement && countdownElement.dataset.launchDate) {
                console.log('Initializing countdown timer with launch date:', countdownElement.dataset.launchDate);
                const launchDate = new Date(countdownElement.dataset.launchDate).getTime();
                const updateCountdown = () => {
                    const now = new Date().getTime();
                    const timeDiff = launchDate - now;
                    if (timeDiff <= 0) {
                        console.log('Countdown ended, displaying Launched!');
                        countdownElement.innerHTML = '<span class="launch-soon">Launched!</span>';
                        return;
                    }
                    const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);
                    const timeUnits = [
                        { unit: 'days', value: days },
                        { unit: 'hours', value: hours },
                        { unit: 'minutes', value: minutes },
                        { unit: 'seconds', value: seconds }
                    ];
                    timeUnits.forEach(({ unit, value }) => {
                        const element = countdownElement.querySelector(`.time-unit[data-unit="${unit}"] .time-value`);
                        if (element) {
                            element.textContent = `${value.toString().padStart(2, '0')}`;
                        }
                    });
                };
                updateCountdown();
                setInterval(updateCountdown, 1000);
            }
            
            // Function to attach event listeners to dynamically loaded content (for popups)
            function attachCartListenersToButtons() {
                const buttons = document.querySelectorAll('.add-to-cart-button');
                if (buttons.length > 0) {
                    console.log('=== CHECKING ADD-TO-CART BUTTONS ===');
                    console.log('Total buttons found:', buttons.length);
                }
                
                buttons.forEach((button, index) => {
                    // Check if this button already has our listener attached
                    if (!button.dataset.listenerAttached) {
                        console.log(`Button #${index} needs listener:`, {
                            element: button,
                            productId: button.getAttribute('data-product-id'),
                            text: button.querySelector('.button-text')?.textContent,
                            isInPopup: button.closest('#popup') !== null,
                            parentClass: button.closest('.box')?.className
                        });
                        button.dataset.listenerAttached = 'true';
                        
                        button.addEventListener('click', async function (e) {
                            console.log('=== DYNAMICALLY ATTACHED BUTTON CLICKED ===');
                            console.log('Button element:', this);
                            console.log('Is in popup?:', this.closest('#popup') !== null);
                            
                            // Send log to server
                            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                                action: 'log_js_debug',
                                message: 'Button clicked! Product ID: ' + this.getAttribute('data-product-id') + ', Is popup: ' + (this.closest('#popup') !== null)
                            });
                            
                            e.preventDefault();
                            const productId = this.getAttribute('data-product-id');
                            const isEnrollButton = this.closest('.enroll-course') !== null;
                            
                            console.log('Product ID:', productId);
                            console.log('Is enroll button:', isEnrollButton);
                            
                            if (!productId || productId === '0') {
                                console.error('Invalid product ID');
                                alert('Error: Invalid product. Please try again.');
                                return;
                            }
                            
                            // Check for selected date (use window.selectedDate for popup compatibility)
                            const currentSelectedDate = window.selectedDate || selectedDate;
                            console.log('Current selected date:', currentSelectedDate);
                            
                            if (isEnrollButton && !currentSelectedDate) {
                                console.error('No start date selected');
                                alert('Please select a start date before adding to cart.');
                                return;
                            }
                            
                            // Show loader
                            this.classList.add('loading');
                            
                            // Use AJAX to add to cart
                            const data = {
                                action: 'woocommerce_add_to_cart',
                                product_id: productId,
                                quantity: 1,
                                security: '<?php echo wp_create_nonce('woocommerce_add_to_cart'); ?>'
                            };
                            
                            if (isEnrollButton && currentSelectedDate) {
                                data.start_date = currentSelectedDate;
                            }
                            
                            console.log('Sending AJAX request with data:', data);
                            
                            jQuery.post('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', data, (response) => {
                                console.log('AJAX response:', response);
                                
                                if (response && response.fragments) {
                                    jQuery(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash]);
                                    jQuery(document.body).trigger('wc_fragment_refresh');
                                    
                                    // Open cart
                                    setTimeout(() => {
                                        jQuery(document).trigger('fkcart_open_cart');
                                        // Close popup if exists
                                        const popup = document.getElementById('popup');
                                        if (popup && popup.style.display !== 'none') {
                                            if (typeof closePopup === 'function') {
                                                closePopup();
                                            } else {
                                                popup.style.display = 'none';
                                                const overlay = document.getElementById('overlay');
                                                if (overlay) overlay.style.display = 'none';
                                            }
                                        }
                                    }, 500);
                                    
                                    console.log('✅ Product added to cart successfully!');
                                } else if (response && response.error && response.product_url) {
                                    // Fallback method
                                    const cartUrl = '<?php echo esc_url(wc_get_cart_url()); ?>?add-to-cart=' + productId;
                                    jQuery.get(cartUrl, function() {
                                        jQuery(document.body).trigger('wc_fragment_refresh');
                                        jQuery(document).trigger('fkcart_open_cart');
                                    });
                                } else {
                                    console.error('Failed to add to cart:', response);
                                    alert('Error adding product to cart. Please try again.');
                                }
                            }).fail((jqXHR, textStatus) => {
                                console.error('AJAX failed:', textStatus);
                                alert('Error communicating with server. Please try again.');
                            }).always(() => {
                                // Hide loader
                                this.classList.remove('loading');
                            });
                        });
                    }
                });
            }
            
            // Initial check for buttons
            console.log('=== SELECTABLE BOXES PLUGIN INITIALIZED ===');
            
            // Send initialization log to server
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                action: 'log_js_debug', 
                message: 'Plugin initialized. Total add-to-cart buttons found: ' + document.querySelectorAll('.add-to-cart-button').length
            });
            
            attachCartListenersToButtons();
            
            // Check for dynamically loaded content periodically
            setInterval(() => {
                console.log('Periodic check for new buttons...');
                attachCartListenersToButtons();
            }, 2000);
            
            // Also check when DOM changes (for popup scenarios)
            const observer = new MutationObserver((mutations) => {
                let hasNewNodes = false;
                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length > 0) {
                        // Check if any of the added nodes contain our elements
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeType === 1) { // Element node
                                if (node.id === 'popup' || node.querySelector?.('.add-to-cart-button')) {
                                    console.log('Detected new content with buttons or popup!');
                                    hasNewNodes = true;
                                }
                            }
                        });
                    }
                });
                if (hasNewNodes) {
                    setTimeout(() => {
                        console.log('DOM changed, checking for new buttons...');
                        attachCartListenersToButtons();
                    }, 100);
                }
            });
            
            // Observe the body for changes
            console.log('Starting DOM observer...');
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
            
            // Global function to handle popup opening (called by showPopup)
            window.onPopupOpen = function() {
                console.log('=== POPUP OPENED - ATTACHING LISTENERS ===');
                setTimeout(() => {
                    attachCartListenersToButtons();
                    
                    // Also check for date buttons in popup
                    const popupDateButtons = document.querySelectorAll('#popup .date-btn');
                    console.log('Date buttons in popup:', popupDateButtons.length);
                    
                    popupDateButtons.forEach(btn => {
                        if (!btn.dataset.dateListenerAttached) {
                            btn.dataset.dateListenerAttached = 'true';
                            btn.addEventListener('click', function(e) {
                                e.stopPropagation();
                                if (!this.disabled && !this.classList.contains('sold-out')) {
                                    document.querySelectorAll('#popup .date-btn').forEach(b => b.classList.remove('selected'));
                                    this.classList.add('selected');
                                    window.selectedDate = this.getAttribute('data-date') || this.textContent.trim();
                                    console.log('Popup date selected:', window.selectedDate);
                                }
                            });
                        }
                    });
                }, 500);
            };
            
            // Override showPopup if it exists
            if (typeof window.showPopup === 'function') {
                const originalShowPopup = window.showPopup;
                window.showPopup = function() {
                    console.log('ShowPopup intercepted!');
                    originalShowPopup.apply(this, arguments);
                    if (window.onPopupOpen) window.onPopupOpen();
                };
            }
        });
    </script>
    <?php
    return ob_get_clean();
}

// Add start date to cart item data
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id, $quantity) {
    error_log('=== CART ITEM DATA FILTER CALLED ===');
    error_log('Product ID: ' . $product_id);
    error_log('POST data: ' . print_r($_POST, true));
    
    if (isset($_POST['start_date']) && !empty($_POST['start_date'])) {
        $start_date = sanitize_text_field($_POST['start_date']);
        $cart_item_data['start_date'] = $start_date;
        error_log('Added start_date to cart item: ' . $start_date);
    } else {
        error_log('No start_date in POST data');
    }
    return $cart_item_data;
}, 10, 4);

// Save start date to order item meta
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    if (isset($values['start_date']) && !empty($values['start_date'])) {
        $item->add_meta_data('Start Date', $values['start_date'], true);
        error_log('Saved start_date to order item: ' . $values['start_date']);
    }
}, 10, 4);

// Display start date in admin order details
add_action('woocommerce_order_item_meta_end', function ($item_id, $item, $order, $plain_text) {
    $start_date = $item->get_meta('Start Date');
    if ($start_date) {
        if ($plain_text) {
            echo "Start Date: $start_date\n";
        } else {
            echo '<p><strong>Start Date:</strong> ' . esc_html($start_date) . '</p>';
        }
        error_log('Displayed start_date in admin order details: ' . $start_date);
    }
}, 10, 4);

// Add start date to customer order email
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain_text, $email) {
    foreach ($order->get_items() as $item_id => $item) {
        $start_date = $item->get_meta('Start Date');
        if ($start_date) {
            if ($plain_text) {
                echo "Start Date: $start_date\n";
            } else {
                echo '<p><strong>Start Date:</strong> ' . esc_html($start_date) . '</p>';
            }
            error_log('Added start_date to order email: ' . $start_date);
        }
    }
}, 10, 4);

add_shortcode('selectable_boxes', 'selectable_boxes_shortcode');

// Add emergency JavaScript to footer
add_action('wp_footer', function() {
    ?>
    <script type="text/javascript">
    console.log('=== FOOTER CART SCRIPT LOADED ===');
    
    // Keep the old function for backward compatibility but redirect to new one
    window.testAddToCart = function(productId) {
        console.log('testAddToCart redirecting to handleAddToCart');
        var button = event ? event.target.closest('button') : null;
        if (button) {
            return window.handleAddToCart(button, productId, true);
        }
        return false;
    };
    
    // Ensure handleAddToCart is available globally (fallback definition)
    if (typeof window.handleAddToCart === 'undefined') {
        window.handleAddToCart = function(button, productId, isEnroll) {
            console.log('Fallback handleAddToCart called');
            
            if (!button) {
                button = event.target.closest('button');
            }
            
            // Check jQuery
            if (typeof jQuery === 'undefined') {
                alert('Error: jQuery is not loaded. Please refresh the page.');
                return false;
            }
            
            // Get selected date if enroll
            var selectedDate = '';
            if (isEnroll) {
                var selectedDateBtn = document.querySelector('.enroll-course .date-btn.selected');
                if (!selectedDateBtn) {
                    selectedDateBtn = document.querySelector('.enroll-course .date-btn:not(.sold-out)');
                    if (selectedDateBtn) {
                        selectedDateBtn.classList.add('selected');
                    }
                }
                if (selectedDateBtn) {
                    selectedDate = selectedDateBtn.getAttribute('data-date') || selectedDateBtn.textContent.trim();
                }
                
                if (!selectedDate) {
                    alert('Please select a start date before adding to cart.');
                    return false;
                }
            }
            
            // Show loading
            if (button) {
                button.classList.add('loading');
            }
            
            // AJAX request
            var data = {
                action: 'woocommerce_add_to_cart',
                product_id: productId,
                quantity: 1
            };
            
            if (isEnroll && selectedDate) {
                data.start_date = selectedDate;
            }
            
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function(response) {
                if (response && response.fragments) {
                    jQuery(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash]);
                    jQuery(document.body).trigger('wc_fragment_refresh');
                    
                    setTimeout(function() {
                        jQuery(document).trigger('fkcart_open_cart');
                        jQuery('.fkcart-cart-toggle').trigger('click');
                    }, 500);
                } else {
                    alert('Error adding product to cart. Please try again.');
                }
            }).fail(function() {
                alert('Error communicating with server. Please try again.');
            }).always(function() {
                if (button) {
                    button.classList.remove('loading');
                }
            });
            
            return false;
        };
    }
    
    console.log('Real add to cart function defined:', typeof window.testAddToCart);
    </script>
    <?php
}, 999);

// Handle JavaScript debug logs via AJAX
add_action('wp_ajax_log_js_debug', 'handle_js_debug_log');
add_action('wp_ajax_nopriv_log_js_debug', 'handle_js_debug_log');

function handle_js_debug_log() {
    $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : 'No message';
    error_log('=== JS DEBUG LOG ===');
    error_log($message);
    wp_send_json_success('Logged');
}

// Handle AJAX add to cart with debug logs
add_action('wp_ajax_woocommerce_add_to_cart', 'handle_ajax_add_to_cart_debug');
add_action('wp_ajax_nopriv_woocommerce_add_to_cart', 'handle_ajax_add_to_cart_debug');

function handle_ajax_add_to_cart_debug() {
    error_log('=== AJAX ADD TO CART REQUEST RECEIVED ===');
    error_log('Request method: ' . $_SERVER['REQUEST_METHOD']);
    error_log('POST data: ' . print_r($_POST, true));
    error_log('Product ID from request: ' . (isset($_POST['product_id']) ? $_POST['product_id'] : 'Not set'));
    error_log('Start date from request: ' . (isset($_POST['start_date']) ? $_POST['start_date'] : 'Not set'));
    
    // Check if product ID is provided
    if (!isset($_POST['product_id']) || empty($_POST['product_id'])) {
        error_log('ERROR: No product ID provided');
        wp_send_json_error('No product ID provided');
        return;
    }
    
    $product_id = absint($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
    
    error_log('Processing product ID: ' . $product_id . ', Quantity: ' . $quantity);
    
    // Try to add to cart
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
    
    if ($cart_item_key) {
        error_log('SUCCESS: Product added to cart. Cart item key: ' . $cart_item_key);
        
        // Get cart fragments
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();
        
        $data = array(
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array(
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            )),
            'cart_hash' => WC()->cart->get_cart_hash(),
            'success' => true,
            'product_id' => $product_id
        );
        
        error_log('Sending success response with cart hash: ' . $data['cart_hash']);
        wp_send_json($data);
    } else {
        error_log('ERROR: Failed to add product to cart');
        wp_send_json_error('Failed to add product to cart');
    }
}

/**
 * Shortcode to display course product prices with sale price below regular price
 */
function course_price_shortcode($atts) {
    $atts = shortcode_atts(['course_id' => 0], $atts, 'course_price');
    $course_page_id = absint($atts['course_id']);

    // Use current post ID if not provided
    if (!$course_page_id && is_singular('course')) {
        $course_page_id = get_the_ID();
    }

    if (!$course_page_id || get_post_type($course_page_id) !== 'course') {
        error_log('course_price_shortcode: Invalid or missing course_page_id: ' . $course_page_id);
        
        // Fallback to current post if it's a course post
        global $post;
        if ($post && isset($post->ID)) {
            $course_price = get_field('field_681ccc6eb123a', $post->ID) ?: '749.99';
            $sale_price = get_field('field_689f3a6f5b266', $post->ID);
            
            // Build output with sale price if available
            if ($sale_price && $sale_price < $course_price) {
                return '<div class="course-price">' .
                       '<div class="woocommerce-Price-amount amount regular-price" style="font-size: 18px;"><del>$' . number_format((float)$course_price, 2) . ' USD</del></div>' .
                       '<div class="woocommerce-Price-amount amount sale-price" style="font-size: 30px;">$' . number_format((float)$sale_price, 2) . ' USD</div>' .
                       '</div>';
            }
            return '<div class="course-price"><div class="woocommerce-Price-amount amount regular-price" style="font-size: 30px;">$' . number_format((float)$course_price, 2) . ' USD</div></div>';
        }
        return '';
    }

    // Get prices from ACF fields
    $course_price = get_field('field_681ccc6eb123a', $course_page_id) ?: '749.99';
    $sale_price = get_field('field_689f3a6f5b266', $course_page_id);
    
    // Build output with sale price if available
    if ($sale_price && $sale_price < $course_price) {
        return '<div class="course-price">' .
               '<div class="woocommerce-Price-amount amount regular-price" style="font-size: 18px;"><del>$' . number_format((float)$course_price, 2) . ' USD</del></div>' .
               '<div class="woocommerce-Price-amount amount sale-price" style="font-size: 30px;">$' . number_format((float)$sale_price, 2) . ' USD</div>' .
               '</div>';
    }
    
    // If no sale price or sale price is not lower, show regular price only
    return '<div class="course-price"><div class="woocommerce-Price-amount amount regular-price" style="font-size: 30px;">$' . number_format((float)$course_price, 2) . ' USD</div></div>';
}
add_shortcode('course_price', 'course_price_shortcode');
?>
