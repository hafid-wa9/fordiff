<?php
/**
 * Theme functions and definitions.
 */
add_filter( 'wt_import_csv_parser_keep_bom', '__return_false' );

add_action( 'template_redirect', 'define_default_payment_gateway' );
function define_default_payment_gateway(){
    if( is_checkout() && ! is_wc_endpoint_url() ) {
        $default_payment_id = 'qr_pay_gateway';

        WC()->session->set( 'chosen_payment_method', $default_payment_id );
    }
}

add_filter('woocommerce_coupon_code', 'preserve_coupon_case_on_save',999);
function preserve_coupon_case_on_save($code) {
    return $code;
}

add_filter('woocommerce_coupon_code', 'display_coupon_case_in_admin');
function display_coupon_case_in_admin($code) {
    global $wpdb;

    $coupon_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title = %s LIMIT 1;",
        $code
    ));

    if ($coupon_id) {
        return get_the_title($coupon_id);
    }
    return $code;
}

add_action('woocommerce_product_after_variable_attributes', 'add_member_price_field_to_variations', 10, 3);
function add_member_price_field_to_variations($loop, $variation_data, $variation) {
    // Get the current member price
    $member_price = get_post_meta($variation->ID, 'member_price', true);
    ?>
    <tr>
        <td>
            <label><?php _e('Member Price', 'woocommerce'); ?></label>
            <input type="number" step="0.01" name="member_price[<?php echo $loop; ?>]" value="<?php echo esc_attr($member_price); ?>" />
        </td>
    </tr>
    <?php
}

add_action('woocommerce_save_product_variation', 'save_member_price_field_for_variations', 10, 2);
function save_member_price_field_for_variations($variation_id, $i) {
    if (isset($_POST['member_price'][$i])) {
        update_post_meta($variation_id, 'member_price', sanitize_text_field($_POST['member_price'][$i]));
    }
}

// Add referral code field in cart
add_action('woocommerce_cart_coupon', 'add_referral_code_field');
function add_referral_code_field() {
    ?>
    <div class="coupon referral_code">
        <label for="referral_code" class="screen-reader-text"><?php _e('Referral Code', 'woocommerce'); ?></label>
        <input type="text" name="referral_code" class="input-text" id="referral_code" value="" placeholder="<?php _e('Referral code', 'woocommerce'); ?>" />
        <button type="submit" class="button" name="apply_referral" value="<?php esc_attr_e('Apply referral', 'woocommerce'); ?>"><?php _e('Apply referral', 'woocommerce'); ?></button>
    </div>
    <?php
}

// Add referral code checkbox in coupon edit page
add_action('woocommerce_coupon_options', 'add_referral_code_checkbox');
function add_referral_code_checkbox() {
    global $post;
    $is_referral_code = get_post_meta($post->ID, '_is_referral_code', true);
    ?>
    <div class="options_group">
        <p class="form-field">
            <label for="is_referral_code"><?php _e('Referral Code', 'woocommerce'); ?></label>
            <input type="checkbox" name="is_referral_code" id="is_referral_code" <?php checked($is_referral_code, 'yes'); ?> />
            <span class="description"><?php _e('Check this box if this is a referral code.', 'woocommerce'); ?></span>
        </p>
    </div>
    <?php
}

// Save referral code checkbox value
add_action('woocommerce_process_shop_coupon_meta', 'save_referral_code_checkbox');
function save_referral_code_checkbox($post_id) {
    $is_referral_code = isset($_POST['is_referral_code']) ? 'yes' : 'no';
    update_post_meta($post_id, '_is_referral_code', $is_referral_code);
}

// Validate coupon code
add_filter('woocommerce_coupon_is_valid', 'validate_coupon_code_field', 10, 2);
function validate_coupon_code_field($valid, $coupon) {
    if (get_post_meta($coupon->get_id(), '_is_referral_code', true) === 'yes') {
        wc_add_notice(__('This is a referral code. Please enter it in the referral code field.', 'woocommerce'), 'error');
        return false;
    }
    return $valid;
}

add_action('woocommerce_cart_updated', 'process_referral_code');
function process_referral_code() {
    if ((isset($_POST['apply_referral']) || isset($_POST['apply_referral_table'])) && (!empty($_POST['referral_code']) || !empty($_POST['referral_code_table']))) {
        if (WC()->session->get('referral_code_processed')) {
            return;
        }

        global $wpdb;
        $referral_code = '';

		if ( ! empty( $_POST['referral_code'] ) ) {
    		$referral_code = sanitize_text_field( $_POST['referral_code'] );
		} elseif ( ! empty( $_POST['referral_code_table'] ) ) {
    		$referral_code = sanitize_text_field( $_POST['referral_code_table'] );
		}

        // Query to check if the referral code exists with exact case
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}posts 
            WHERE post_type = 'shop_coupon' 
            AND post_status = 'publish' 
            AND post_title = %s 
            AND EXISTS (
                SELECT * FROM {$wpdb->prefix}postmeta 
                WHERE post_id = {$wpdb->prefix}posts.ID 
                AND meta_key = '_is_referral_code'
                AND meta_value = 'yes'
            )",
            $referral_code
        );

        $result = $wpdb->get_row($query);

        if ($result) {
            if ($result->post_title === $referral_code) {
                WC()->session->set('referral_code', $referral_code);
                wc_add_notice(__('Referral code applied successfully!', 'woocommerce'), 'success');
            } else {
                wc_add_notice(__('Invalid referral code. ', 'woocommerce'), 'error');
            }
        } else {
            wc_add_notice(__('Invalid referral code.', 'woocommerce'), 'error');
        }

        WC()->session->set('referral_code_processed', true);
    }
}

function add_referral_code_toggle_and_form() {
    ?>
    <!--<div class="woocommerce-form-referral-toggle">
         <?php wc_print_notice( apply_filters( 'woocommerce_checkout_coupon_message', esc_html__( 'Registered as a WA 9 member?', 'woocommerce' ) . ' <a href="#" class="showreferral">' . esc_html__( 'Click here to enter your referral code', 'woocommerce' ) . '</a>' ), 'notice' ); ?>
    </div>

    <form class="checkout_referral woocommerce-form-referral" method="post">
        <p style="display:none;"><?php esc_html_e('If you have a referral code, please apply it below.', 'woocommerce'); ?></p>

        <p class="form-row form-row-first">
            <label for="referral_code" class="screen-reader-text"><?php esc_html_e('Referral code:', 'woocommerce'); ?></label>
            <input type="text" name="referral_code" class="input-text" placeholder="<?php esc_attr_e('Referral code', 'woocommerce'); ?>" id="referral_code" value="" />
        </p>

        <p class="form-row form-row-last">
            <button type="submit" class="button" name="apply_referral" value="<?php esc_attr_e('Apply referral', 'woocommerce'); ?>"><?php esc_html_e('Apply referral', 'woocommerce'); ?></button>
        </p>

        <div class="clear"></div>
    </form>-->
    <?php
}
add_action('woocommerce_before_checkout_form', 'add_referral_code_toggle_and_form');

function enqueue_custom_scripts() {
    wp_enqueue_script('referral-code-script', get_stylesheet_directory_uri() . '/js/referral_code_checkout.js', array('jquery'), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_custom_scripts');

add_action('woocommerce_init', 'reset_referral_code_processed_flag');
function reset_referral_code_processed_flag() {
    if (WC()->session) {
        WC()->session->__unset('referral_code_processed');
    }
}


add_action('woocommerce_before_calculate_totals', 'apply_member_price');
function apply_member_price($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    if (WC()->session->get('referral_code')) {
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $variation_id = isset($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;

            // Check if it's a variable product and get the member price for the variation
            if ($variation_id) {
                $member_price = get_post_meta($variation_id, '_member_price', true);
				if (empty($member_price)) {
        $member_price = get_post_meta($product_id, 'member_price', true);
    }
            } else {
                $member_price = get_post_meta($product_id, 'member_price', true);
            }

            if (!empty($member_price)) {
                $cart_item['data']->set_price($member_price);
            }
        }
    }
}

add_action('woocommerce_cart_totals_before_order_total', 'display_applied_referral_code');
function display_applied_referral_code() {
    $referral_code = WC()->session->get('referral_code');
    $current_url = is_cart() ? wc_get_cart_url() : (is_checkout() ? wc_get_checkout_url() : home_url());

    if ($referral_code) {
        echo '<tr class="referral-code">
                <th>' . __('Referral Code Applied', 'woocommerce') . ': ' . esc_html($referral_code) . '</th>
                <td><a href="' . esc_url(add_query_arg(['remove_referral' => '1', 'redirect_to' => urlencode($current_url)], $current_url)) . '" class="remove-referral">' . __('[Remove]', 'woocommerce') . '</a></td>
              </tr>';
    }
}

add_action('woocommerce_review_order_before_order_total', 'code_form');
function code_form(){
    $referral_code = WC()->session->get('referral_code');
    $current_url = is_cart() ? wc_get_cart_url() : (is_checkout() ? wc_get_checkout_url() : home_url());
    if ($referral_code) {
        echo '<tr class="referral-code">
                <th>' . __('Referral Code Applied', 'woocommerce') . ': ' . esc_html($referral_code) . '</th>
                <td><a href="' . esc_url(add_query_arg(['remove_referral' => '1', 'redirect_to' => urlencode($current_url)], $current_url)) . '" class="remove-referral">' . __('[Remove]', 'woocommerce') . '</a></td>
              </tr>';
    }
    ?>
	<tr class="custom-form-row">
        <td colspan=2>
            <form id="checkout_coupon_table" method="post">
                <input type="text" name="coupon_code_table" class="input-text" placeholder="<?php esc_attr_e('Coupon code', 'woocommerce'); ?>" id="coupon_code_table" value="" />
                <button type="submit" class="button" name="apply_coupon_table" id="apply_coupon_table" value="<?php esc_attr_e('Apply Coupon', 'woocommerce'); ?>"><?php esc_html_e('Apply Coupon', 'woocommerce'); ?></button>
            </form>
        </td>
    </tr>
    <?php
    if (!$referral_code) {
    ?>
	<tr class="custom-form-row">
        <td colspan=2>
            <form id="checkout_referral_table" method="post">
                <input type="text" name="referral_code_table" class="input-text" placeholder="<?php esc_attr_e('Referral code', 'woocommerce'); ?>" id="referral_code_table" value="" />
                <button type="submit" class="button" name="apply_referral_table" id="apply_referral_table" value="<?php esc_attr_e('Apply Referral', 'woocommerce'); ?>"><?php esc_html_e('Apply referral', 'woocommerce'); ?></button>
            </form>
        </td>
    </tr>
    <?php
    }
}

add_action('wp', 'handle_custom_coupon_form_submission');
function handle_custom_coupon_form_submission() {
    if (isset($_POST['apply_coupon_table']) && isset($_POST['coupon_code_table'])) {
        $coupon_code = sanitize_text_field($_POST['coupon_code_table']);

        if (!empty($coupon_code)) {
            WC()->cart->apply_coupon($coupon_code);
        } else {
            wc_add_notice(__('Please enter a coupon code.', 'woocommerce'), 'error');
        }

        wp_redirect(wc_get_checkout_url());
        exit;
    }
}

add_action('wp_loaded', 'remove_referral_code');
function remove_referral_code() {
    if (isset($_GET['remove_referral']) && $_GET['remove_referral'] == '1') {
        WC()->session->set('referral_code', '');
        wc_add_notice(__('Referral code removed.', 'woocommerce'), 'success');

        // Redirect back to the specified URL or fallback to home page
        $redirect_url = isset($_GET['redirect_to']) ? esc_url_raw(urldecode($_GET['redirect_to'])) : home_url();
        wp_safe_redirect($redirect_url);
        exit;
    }
}


add_filter('woocommerce_get_price_html', 'display_member_price', 10, 2);

function display_member_price($price, $product) {
    if (!is_admin()) {
        $member_price = get_post_meta($product->get_id(), 'member_price', true);
        
        if (!empty($member_price)) {
            $regular_price = wc_price($product->get_regular_price());
            $member_price = wc_price($member_price);
            
			if ($product->is_type('variable')){
				$variations = $product->get_available_variations();
				if (!empty($variations)){
					$variation_id = $variations[0]['variation_id'];
            		$variation = wc_get_product($variation_id);
            		$regular_price = $variation->get_regular_price();
					$price = wc_price($regular_price) . '<ins>' . $member_price . ' <br><span style="font-size: smaller;">' . __('Member Price', 'woocommerce') . '</span></ins>';
				}
				
			}else if ($product->is_type('variation')){
				$price = $regular_price . '<br><br><ins>' . $member_price . '(<span style="font-size: smaller;">' . __('Member Price', 'woocommerce') . '</span>)</ins>';
			}else{
            	$price = $regular_price . '<ins>' . $member_price . ' <br><span style="font-size: smaller;">' . __('Member Price', 'woocommerce') . '</span></ins>';
			}
        }
    }
    return $price;
}

// Save referral code to the session when applied
// add_action('woocommerce_applied_coupon', 'save_referral_code_to_session');
// function save_referral_code_to_session($coupon_code) {
//     $coupon = new WC_Coupon($coupon_code);
    
//     // Check if the coupon is a referral code
//     if (get_post_meta($coupon->get_id(), '_is_referral_code', true)) {
//         WC()->session->set('referral_code', $coupon_code);
//     }
// }

// Save referral code to order meta
add_action('woocommerce_checkout_update_order_meta', 'save_referral_code_to_order_meta');
function save_referral_code_to_order_meta($order_id) {
    $referral_code = WC()->session->get('referral_code');
    
    if (!empty($referral_code)) {
        update_post_meta($order_id, '_applied_referral_code', $referral_code);
    }
}

add_filter('woocommerce_rest_prepare_shop_order_object', 'add_referral_code_to_order_response', 10, 3);
function add_referral_code_to_order_response($response, $order, $request) {
    // Get the referral code from the order meta
    $referral_code = get_post_meta($order->get_id(), '_applied_referral_code', true);

    // Add the referral code to the order response
    $response->data['referral_code'] = $referral_code;

    return $response;
}

// Display referral code on the order received page
add_action('woocommerce_thankyou', 'display_referral_code_on_thankyou_page');
function display_referral_code_on_thankyou_page($order_id) {
    $referral_code = get_post_meta($order_id, '_applied_referral_code', true);
	
    if (!empty($referral_code)) {
        echo '<p><strong>Referral Code Applied:</strong> ' . esc_html($referral_code) . '</p>';
    }
	WC()->session->__unset('referral_code');
}


function add_gtag_conversion_event() {
    if (is_wc_endpoint_url('order-received')) {
        ?>
        <script>
          gtag('event', 'ads_conversion_Purchase_1', {
            // Add event parameters here if needed
          });
        </script>
        <?php
    }
}
add_action('wp_head', 'add_gtag_conversion_event');

// Display referral code in the order meta box in the admin panel
add_action('woocommerce_admin_order_data_after_order_details', 'display_referral_code_in_admin_order_meta_box');
function display_referral_code_in_admin_order_meta_box($order) {
    $referral_code = get_post_meta($order->get_id(), '_applied_referral_code', true);
    
    if (!empty($referral_code)) {
        echo '<p><strong>' . __('Referral Code Applied', 'woocommerce') . ':</strong> ' . esc_html($referral_code) . '</p>';
    }
}

// Display referral code on the customer's order details page
add_action('woocommerce_order_details_after_order_table', 'display_referral_code_on_order_details_page');
function display_referral_code_on_order_details_page($order) {
    $referral_code = get_post_meta($order->get_id(), '_applied_referral_code', true);
    
    if (!empty($referral_code)) {
        echo '<p><strong>' . __('Referral Code Applied', 'woocommerce') . ':</strong> ' . esc_html($referral_code) . '</p>';
    }
}

function enqueue_custom_js() {
    wp_enqueue_script('hide-empty-categories', get_template_directory_uri() . '/js/hide-empty-categories.js', array('jquery'), null, true);
}
add_action('wp_enqueue_scripts', 'enqueue_custom_js');
