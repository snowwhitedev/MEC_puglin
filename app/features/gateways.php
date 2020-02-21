<?php

/** no direct access **/
defined('MECEXEC') or die();

/**
 * Webnus MEC gateways class.
 *
 * @author Webnus <info@webnus.biz>
 */
class MEC_feature_gateways extends MEC_base
{
    /**
     * @var MEC_factory
     */
    public $factory;

    /**
     * @var MEC_main
     */
    public $main;

    /**
     * Constructor method
     *
     * @author Webnus <info@webnus.biz>
     */
    public function __construct()
    {
        // Import MEC Factory
        $this->factory = $this->getFactory();

        // Import MEC Main
        $this->main = $this->getMain();
    }

    /**
     * Initialize colors feature
     *
     * @author Webnus <info@webnus.biz>
     */
    public function init()
    {
        // PRO Version is required
        if (!$this->getPRO()) return false;

        $this->factory->action('mec_gateways', array($this, 'register_gateways'));
        $this->factory->action('wp_ajax_mec_do_transaction_free', array($this, 'do_free_booking'));
        $this->factory->action('wp_ajax_nopriv_mec_do_transaction_free', array($this, 'do_free_booking'));

        new MEC_gateway_pay_locally();
        new MEC_gateway_paypal_express();
        new MEC_gateway_paypal_credit_card();
        new MEC_gateway_stripe();
        new MEC_gateway_stripe_connect();
        new MEC_gateway_woocommerce();

        do_action('MEC_feature_gateways_init');
    }

    public function register_gateways($gateways = array())
    {
        $gateways['pay_locally'] = new MEC_gateway_pay_locally();
        $gateways['paypal_express'] = new MEC_gateway_paypal_express();
        $gateways['paypal_credit_card'] = new MEC_gateway_paypal_credit_card();
        $gateways['stripe'] = new MEC_gateway_stripe();
        $gateways['woocommerce'] = new MEC_gateway_woocommerce();
        $gateways['stripe_connect'] = new MEC_gateway_stripe_connect();

        return apply_filters('MEC_register_gateways', $gateways);
    }

    public function do_free_booking()
    {
        $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : '';

        // Verify that the nonce is valid.
        if (!wp_verify_nonce(sanitize_text_field($_POST['_wpnonce']), 'mec_transaction_form_' . $transaction_id)) {
            $this->main->response(
                array(
                    'success' => 0,
                    'code' => 'NONCE_IS_INVALID',
                    'message' => __(
                        'Request is invalid!',
                        'mec'
                    ),
                )
            );
        }

        $free_gateway = new MEC_gateway_free();
        $results = $free_gateway->do_transaction($transaction_id);

        $results['output'] = '<h4>' . __('Thanks for your booking.', 'mec') . '</h4>
        <div class="mec-event-book-message">
            <div class="' . ($results['success'] ? 'mec-success' : 'mec-error') . '">' . $results['message'] . '</div>
        </div>';

        $this->main->response($results);
    }
}

do_action('after_MEC_feature_gateways');

interface MEC_gateway_interface
{
    public function id();
    public function label();
    public function options_form();
    public function op_form();
    public function options($transaction_id);
    public function checkout_form($transaction_id, $params = array());
    public function enabled();
    public function op_enabled();
    public function comment();
    public function do_transaction();
    public function register_user($ticket);
}

class MEC_gateway extends MEC_base implements MEC_gateway_interface
{
    /**
     * @var MEC_main
     */
    public $main;

    /**
     * @var MEC_book
     */
    public $book;

    /**
     * @var MEC_factory
     */
    public $factory;

    public $settings;
    public $gateways_options;
    public $PT;
    public $id;
    public $options;

    public function __construct()
    {
        // MEC Main library
        $this->main = $this->getMain();

        // MEC Main library
        $this->book = $this->getBook();

        // Import MEC Factory
        $this->factory = $this->getFactory();

        // MEC settings
        $this->settings = $this->main->get_settings();

        // MEC gateways options
        $this->gateways_options = $this->main->get_gateways_options();

        // MEC Book Post Type Name
        $this->PT = $this->main->get_book_post_type();
    }

    public function id()
    {
        return $this->id;
    }

    public function label()
    {
        return __('Gateway', 'mec');
    }

    public function color()
    {
        return '#E7E9ED';
    }

    public function title()
    {
        return (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : $this->label();
    }

    public function options_form()
    { }

    public function op_form()
    { }

    public function options($transaction_id = NULL)
    {
        $options = isset($this->gateways_options[$this->id]) ? $this->gateways_options[$this->id] : array();
        return apply_filters('mec_gateway_options', $options, $this->id, $transaction_id);
    }

    public function checkout_form($transaction_id, $params = array())
    { }

    public function enabled()
    {
        return ((isset($this->options['status']) and $this->options['status']) ? true : false);
    }

    public function op_enabled()
    {
        return false;
    }

    public function comment()
    {
        return ((isset($this->options['comment']) and trim($this->options['comment'])) ? '<p class="mec-gateway-comment">' . __(stripslashes($this->options['comment']), 'mec') . '</p>' : '');
    }

    public function do_transaction($transaction_id = null)
    { }

    public function response($response)
    {
        $this->main->response($response);
    }

    public function register_user($attendee)
    {
        $name = isset($attendee['name']) ? $attendee['name'] : '';
        $email = isset($attendee['email']) ? $attendee['email'] : '';
        $reg = isset($attendee['reg']) ? $attendee['reg'] : array();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        $existed_user_id = $this->main->email_exists($email);

        // User already exist
        if ($existed_user_id !== false) return $existed_user_id;

        $password = wp_generate_password(12, true, true);
        $user_id = $this->main->register_user($email, $email, $password);

        // Update WordPress user first name and last name
        $ex = explode(' ', $name);
        $first_name = isset($ex[0]) ? $ex[0] : '';
        $last_name = isset($ex[1]) ? $ex[1] : '';

        $user = new stdClass();
        $user->ID = $user_id;
        $user->first_name = $first_name;
        $user->last_name = $last_name;

        wp_update_user($user);
        update_user_meta($user_id, 'mec_name', $name);
        update_user_meta($user_id, 'mec_reg', $reg);

        return $user_id;
    }

    public function get_request_string($vars)
    {
        $string = '';
        foreach ($vars as $var => $val) $string .= '&' . $var . '=' . urlencode(stripslashes($val));

        return $string;
    }

    public function decode_custom($encoded)
    {
        $base64 = urldecode($encoded);
        $json = base64_decode($base64);

        return json_decode($json, true);
    }

    public function get_paypal_response($request_str, $url)
    {
        $results = null;

        $api_url = $url;
        $parsed_url = parse_url($api_url);
        $fp = fsockopen('ssl://' . $parsed_url['host'], '443', $errNum, $errStr, 30);

        if (!$fp) {
            // @TODO log error here
            return '';
        } else {
            fputs($fp, 'POST ' . $parsed_url['path'] . " HTTP/1.1\r\n");
            fputs($fp, 'Host: ' . $parsed_url['host'] . "\r\n");
            fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
            fputs($fp, 'Content-length: ' . strlen($request_str) . "\r\n");
            fputs($fp, "Connection: close\r\n\r\n");
            fputs($fp, $request_str . "\r\n\r\n");

            $results = '';
            while (!feof($fp)) $results .= fgets($fp, 1024);

            fclose($fp);

            list($header, $body) = preg_split('/\R\R/', $results, 2);
            $results = $body;
        }

        return $results;
    }
}

class MEC_gateway_stripe extends MEC_gateway
{
    public $id = 5;
    public $options;

    public function __construct()
    {
        parent::__construct();

        // Gateway options
        $this->options = $this->options();

        $this->factory->action('init', array($this, 'include_api'));

        // Register actions
        $this->factory->action('wp_ajax_mec_do_transaction_stripe', array($this, 'do_transaction'));
        $this->factory->action('wp_ajax_nopriv_mec_do_transaction_stripe', array($this, 'do_transaction'));

        // Add Stripe JS Library
        if ($this->enabled() and !is_admin()) $this->factory->action('wp_enqueue_scripts', array($this, 'frontend_assets'));
    }

    public function frontend_assets()
    {
        $stripe_js = apply_filters('mec_gateways_stripe_js', true);
        if ($stripe_js) wp_enqueue_script('mec-stripe', 'https://js.stripe.com/v3/');
    }

    public function label()
    {
        return __('Stripe', 'mec');
    }

    public function color()
    {
        return '#FFCE56';
    }

    public function include_api()
    {
        if (class_exists('Stripe')) return;

        MEC::import('app.api.Stripe.autoload', false);
    }

    public function do_transaction($transaction_id = null)
    {
        if (!trim($transaction_id)) $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;

        // Verify that the nonce is valid.
        if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'mec_transaction_form_' . $transaction_id)) {
            $this->response(
                array(
                    'success' => 0,
                    'code' => 'NONCE_IS_INVALID',
                    'message' => __(
                        'Request is invalid!',
                        'mec'
                    ),
                )
            );
        }

        // Stripe Payment Details
        $payment_method_id = isset($_GET['payment_method_id']) ? sanitize_text_field($_GET['payment_method_id']) : '';
        $payment_intent_id = isset($_GET['payment_intent_id']) ? sanitize_text_field($_GET['payment_intent_id']) : '';

        // Pay
        $results = $this->pay($payment_method_id, $payment_intent_id, $transaction_id);

        // Payment is invalid
        if (!$results['success']) {
            $this->response(
                array(
                    'success' => 0,
                    'code' => 'INVALID_PAYMENT',
                    'message' => $results['message'],
                )
            );
        }

        // Payment requires more actions
        if ($results['success'] == 2) {
            $this->response(
                array(
                    'success' => 2,
                    'requires_action' => 1,
                    'code' => 'REQUIRES_ACTION',
                    'payment_intent_client_secret' => $results['payment_intent_client_secret'],
                )
            );
        }

        $transaction = $this->book->get_transaction($transaction_id);
        $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

        // Is there any attendee?
        if (!count($attendees)) {
            $this->response(
                array(
                    'success' => 0,
                    'code' => 'NO_TICKET',
                    'message' => __(
                        'There is no attendee for booking!',
                        'mec'
                    ),
                )
            );
        }

        $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
        $ex = explode(':', $attention_date);
        $date = trim($ex[0]);

        $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
        $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

        $ticket_ids = '';
        $attendees_info = array();

        foreach ($attendees as $attendee) {
            $ticket_ids .= $attendee['id'] . ',';
            if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
            else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
        }

        $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
        $user_id = $this->register_user($main_attendee);

        $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
        $book_id = $this->book->add(
            array(
                'post_author' => $user_id,
                'post_type' => $this->PT,
                'post_title' => $book_subject,
                'post_date' => $date,
                'attendees_info' => $attendees_info,
                'mec_attendees' => $attendees
            ),
            $transaction_id,
            $ticket_ids
        );

        update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_stripe');
        update_post_meta($book_id, 'mec_gateway_label', $this->label());
        
        // Fires after completely creating a new booking
        do_action('mec_booking_completed', $book_id);
        
        // var_dump("booking form");
        // var_dump($book_id);
        // exit;

        $redirect_to = '';
        if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);

        // Invoice Link
        $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
        $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

        $this->response(
            array(
                'success' => 1,
                'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
                'data' => array(
                    'book_id' => $book_id,
                    'redirect_to' => $redirect_to,
                    'invoice_link' => $invoice_link,
                ),
            )
        );
    }

    public function pay($payment_method_id, $payment_intent_id, $transaction_id)
    {
        $transaction = $this->book->get_transaction($transaction_id);

        // Get Options Compatible with Organizer Payment
        $options = $this->options($transaction_id);

        // Set Stripe Secret Key
        \Stripe\Stripe::setApiKey($options['secret_key']);

        // Get Status of Payment Intent
        if ($payment_intent_id) {
            $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            $intent->confirm();

            if ($intent->status == 'succeeded') return array('success' => 1);
            else if ($intent->status == 'requires_confirmation') return array('success' => 0, 'message' => __('Your payment needs to get confirmed!', 'mec'));
            else return array('success' => 0, 'message' => __('Unknown Error!', 'mec'));
        }

        try {
            $intent = \Stripe\PaymentIntent::create(
                array(
                    'payment_method' => $payment_method_id,
                    'amount' => (isset($transaction['price']) ? ((int) ($transaction['price'] * 100)) : 0),
                    'currency' => $this->main->get_currency_code(),
                    'description' => sprintf(__('MEC Transaction ID: %s', 'mec'), $transaction_id),
                    'confirmation_method' => 'manual',
                    'confirm' => true,
                )
            );
        } catch (Exception $e) {
            return array(
                'success' => 0,
                'message' => $e->getMessage(),
            );
        }

        if ($intent->status == 'requires_action' and $intent->next_action->type == 'use_stripe_sdk') {
            # Tell the client to handle the action
            return array(
                'success' => 2,
                'requires_action' => true,
                'payment_intent_client_secret' => $intent->client_secret
            );
        } elseif ($intent->status == 'succeeded') return array('success' => 1);
        else return array('success' => 0, 'message' => __('Unknown Error!', 'mec'));
    }

    public function checkout_form($transaction_id, $params = array())
    {
        // Get Options Compatible with Organizer Payment
        $options = $this->options($transaction_id);
        ?>
        <script type="text/javascript">
            var stripe = Stripe("<?php echo (isset($options['publishable_key']) ? $options['publishable_key'] : ''); ?>");
            var elements = stripe.elements();
            var style = {
                base: {
                    color: '#32325d',
                    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                    fontSmoothing: 'antialiased',
                    fontSize: '16px',
                    minHeight: '40px',
                    '::placeholder': {
                        color: '#aab7c4'
                    },
                },
                invalid: {
                    color: '#fa755a',
                    iconColor: '#fa755a'
                }
            };

            var card = elements.create('card');
            card.mount('#mec_card_element_stripe_<?php echo $transaction_id; ?>');

            // Validation
            card.addEventListener('change', function(event) {
                // Show the Error
                if (event.error) jQuery('#mec_do_transaction_stripe_message<?php echo $transaction_id; ?>').text(event.error.message).addClass('mec-error').show();
                // Hide the Message
                else jQuery("#mec_do_transaction_stripe_message<?php echo $transaction_id; ?>").removeClass("mec-success mec-error").hide();
            });

            jQuery('#mec_do_transaction_stripe_form<?php echo $transaction_id; ?>').on('submit', function(e) {
                // Prevent the form from submitting
                e.preventDefault();
                
                var form = jQuery(this);
                var transaction_id = '<?php echo $transaction_id; ?>';

                // No pressing the buy now button more than once
                form.find('button').prop('disabled', true);

                // Hide the Message
                jQuery("#mec_do_transaction_stripe_message" + transaction_id).removeClass("mec-success mec-error").hide();

                // Add loading Class to the button
                jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").addClass("loading");

                var payer_name = jQuery("#mec_name_stripe_" + transaction_id).val();
                stripe.createPaymentMethod('card', card, {
                    billing_details: {
                        name: payer_name
                    }
                }).then(function(result) {
                    if (result.error) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                        // Show the user what they did wrong
                        jQuery('#mec_do_transaction_stripe_message' + transaction_id).text(result.error.message).addClass('mec-error').show();

                        // Make the submit clickable again
                        form.find('button').prop('disabled', false);
                    } else {
                        // Make the submit clickable again
                        form.find('button').prop('disabled', false);

                        // Hide the Message
                        jQuery("#mec_do_transaction_stripe_message" + transaction_id).removeClass("mec-success mec-error").hide();

                        // Set Payment Method ID
                        jQuery("#mec_do_transaction_stripe_payment_method_id" + transaction_id).val(result.paymentMethod.id);

                        var data = form.serialize();
                        jQuery.ajax({
                            type: "GET",
                            url: "<?php echo admin_url('admin-ajax.php', NULL); ?>",
                            data: data,
                            dataType: "JSON",
                            success: function(data) {
                                if (data.success === 1) {
                                    // Remove the loading Class from the button
                                    jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                    jQuery("#mec_do_transaction_stripe_form" + transaction_id).hide();
                                    jQuery(".mec-book-form-gateway-label").remove();
                                    jQuery("#mec_book_form_coupon").hide();

                                    jQuery("#mec_do_transaction_stripe_message" + transaction_id).addClass("mec-success").html(data.message).show();

                                    // Show Invoice Link
                                    if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                        jQuery("#mec_do_transaction_stripe_message" + transaction_id).append(' <a class="mec-invoice-download" target="_blank" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                                    }
                                    
                                    //Show Mec Invoice 
                                    jQuery("#mec-customer-invoice-" + transaction_id).css("display", "block");

                                    // Redirect to thank you page
                                    if (typeof data.data.redirect_to !== "undefined" && data.data.redirect_to !== "") {
                                        setTimeout(function() {
                                            window.location.href = data.data.redirect_to;
                                        }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                                    }
                                } else if (data.requires_action) {
                                    stripe.handleCardAction(data.payment_intent_client_secret).then(function(result) {
                                        if (result.error) {
                                            // Show the user what they did wrong
                                            jQuery('#mec_do_transaction_stripe_message' + transaction_id).text(result.error.message).addClass('mec-error').show();

                                            // Remove the loading Class from the button
                                            jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                            // Make the submit clickable again
                                            form.find('button').prop('disabled', false);
                                        } else {
                                            // Set Payment Intent ID
                                            jQuery("#mec_do_transaction_stripe_payment_intent_id" + transaction_id).val(result.paymentIntent.id);

                                            var data = form.serialize();
                                            jQuery.ajax({
                                                type: "GET",
                                                url: "<?php echo admin_url('admin-ajax.php', NULL); ?>",
                                                data: data,
                                                dataType: "JSON",
                                                success: function(data) {
                                                    if (data.success === 1) {
                                                        // Remove the loading Class from the button
                                                        jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                                        jQuery("#mec_do_transaction_stripe_form" + transaction_id).hide();
                                                        jQuery(".mec-book-form-gateway-label").remove();
                                                        jQuery("#mec_book_form_coupon").hide();

                                                        jQuery("#mec_do_transaction_stripe_message" + transaction_id).addClass("mec-success").html(data.message).show();

                                                        // Show Invoice Link
                                                        if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                                            jQuery("#mec_do_transaction_stripe_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                                                        }

                                                        // Redirect to thank you page
                                                        if (typeof data.data.redirect_to !== "undefined" && data.data.redirect_to !== "") {
                                                            setTimeout(function() {
                                                                window.location.href = data.data.redirect_to;
                                                            }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                                                        }
                                                    } else {
                                                        // Remove the loading Class from the button
                                                        jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                                        jQuery("#mec_do_transaction_stripe_message" + transaction_id).addClass("mec-error").html(data.message).show();
                                                    }
                                                },
                                                error: function(jqXHR, textStatus, errorThrown) {
                                                    // Remove the loading Class from the button
                                                    jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");
                                                }
                                            });
                                        }
                                    });
                                } else {
                                    // Remove the loading Class from the button
                                    jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                    jQuery("#mec_do_transaction_stripe_message" + transaction_id).addClass("mec-error").html(data.message).show();
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                // Remove the loading Class from the button
                                jQuery("#mec_do_transaction_stripe_form" + transaction_id + " button[type=submit]").removeClass("loading");
                            }
                        });
                    }
                });
            });
        </script>
        <form id="mec_do_transaction_stripe_form<?php echo $transaction_id; ?>">
            <div class="mec-form-row">
                <label for="mec_name_stripe_<?php echo $transaction_id; ?>">
                    <?php _e('Name', 'mec'); ?>
                </label>
                <style>
                    .mec-name-stripe{
                        border:1px solid black !important;
                    }
                </style>
                <input id="mec_name_stripe_<?php echo $transaction_id; ?>" class="mec-name-stripe" type="text" />
            </div>
            <div class="mec-form-row">
                <label for="mec_card_element_stripe_<?php echo $transaction_id; ?>">
                    <?php _e('Credit or debit card', 'mec'); ?>
                </label>
                <div id="mec_card_element_stripe_<?php echo $transaction_id; ?>">
                </div>
            </div>
            <div class="mec-form-row">
                <input type="hidden" name="action" value="mec_do_transaction_stripe" />
                <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
                <input type="hidden" name="gateway_id" value="<?php echo $this->id(); ?>" />
                <input type="hidden" name="payment_method_id" value="" id="mec_do_transaction_stripe_payment_method_id<?php echo $transaction_id; ?>" />
                <input type="hidden" name="payment_intent_id" value="" id="mec_do_transaction_stripe_payment_intent_id<?php echo $transaction_id; ?>" />
                <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
                <button type="button" id="mec_book_form_prev"><?php echo __('Prev', 'mec'); ?></button>
                <button type="submit"><?php echo __('Pay', 'mec'); ?></button>
            </div>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_stripe_message<?php echo $transaction_id; ?>"></div>
    <?php
        }

        public function options_form()
        {
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle();" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                            if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                echo 'checked="checked"';
                                                                                                                                                                                                            }
                                                                                                                                                                                                            ?> /> <?php _e('Stripe', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>
										">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_secret_key"><?php _e('Secret Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_secret_key" name="mec[gateways][<?php echo $this->id(); ?>][secret_key]" value="<?php echo isset($this->options['secret_key']) ? $this->options['secret_key'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_publishable_key"><?php _e('Publishable Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_publishable_key" name="mec[gateways][<?php echo $this->id(); ?>][publishable_key]" value="<?php echo isset($this->options['publishable_key']) ? $this->options['publishable_key'] : ''; ?>" />
                </div>
            </div>
        </div>
    <?php
        }

        public function op_enabled()
        {
            return true;
        }

        public function op_form($options = array())
        {
            ?>
        <h4><?php echo $this->label(); ?></h4>
        <div class="mec-gateway-options-form">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_secret_key"><?php _e('Secret Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_secret_key" name="mec[op][<?php echo $this->id(); ?>][secret_key]" value="<?php echo isset($options['secret_key']) ? $options['secret_key'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_publishable_key"><?php _e('Publishable Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_publishable_key" name="mec[op][<?php echo $this->id(); ?>][publishable_key]" value="<?php echo isset($options['publishable_key']) ? $options['publishable_key'] : ''; ?>" />
                </div>
            </div>
        </div>
    <?php
        }
    }

    class MEC_gateway_stripe_connect extends MEC_gateway
    {
        public $id = 7;
        public $options;

        public function __construct()
        {
            parent::__construct();

            // Gateway options
            $this->options = $this->options();

            $this->factory->action('init', array($this, 'include_api'));
            $this->factory->action('init', array($this, 'authenticate'));

            // Register actions
            $this->factory->action('wp_ajax_mec_check_stripe_connection', array($this, 'check_connection'));
            $this->factory->action('wp_ajax_nopriv_mec_check_stripe_connection', array($this, 'check_connection'));

            $this->factory->action('wp_ajax_mec_do_transaction_stripe_connect', array($this, 'do_transaction'));
            $this->factory->action('wp_ajax_nopriv_mec_do_transaction_stripe_connect', array($this, 'do_transaction'));

            // Add Stripe JS Library
            if ($this->enabled() and !is_admin()) $this->factory->action('wp_enqueue_scripts', array($this, 'frontend_assets'));
        }

        public function frontend_assets()
        {
            $stripe_js = apply_filters('mec_gateways_stripe_js', true);
            if ($stripe_js) wp_enqueue_script('mec-stripe', 'https://js.stripe.com/v3/');
        }

        public function label()
        {
            return __('Stripe Connect', 'mec');
        }

        public function color()
        {
            return '#FFCE56';
        }

        public function include_api()
        {
            if (class_exists('Stripe')) return;

            MEC::import('app.api.Stripe.autoload', false);
        }

        /**
         * It run after come back from Stripe
         */
        public function authenticate()
        {
            // Is it a request to autheticate?
            $called = (isset($_GET['mec-stripe-connect']) and $_GET['mec-stripe-connect'] == 1) ? true : false;
            if (!$called) return;

            $code = (isset($_GET['code']) and trim($_GET['code'])) ? $_GET['code'] : NULL;
            if (!$code) return;

            $current_user_id = get_current_user_id();
            if (!$current_user_id) return;

            // Call Stripe API to validate the request
            $post = array(
                'client_secret' => $this->options['secret_key'],
                'code' => $code,
                'grant_type' => 'authorization_code'
            );

            $ch = curl_init('https://connect.stripe.com/oauth/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

            $JSON = curl_exec($ch);
            curl_close($ch);

            // Get Stripe Account ID
            $response = json_decode($JSON);
            $stripe_user_id = isset($response->stripe_user_id) ? $response->stripe_user_id : NULL;

            // Stripe Account ID not found!
            if (!$stripe_user_id) return;

            // Save User ID
            update_user_meta($current_user_id, 'mec_stripe_id', $stripe_user_id);

            // Redirect
            $redirect_to = (isset($this->options['redirection_page']) and trim($this->options['redirection_page'])) ? get_permalink($this->options['redirection_page']) : get_home_url();

            wp_redirect($redirect_to);
            exit;
        }

        public function do_transaction($transaction_id = null)
        {
            if (!trim($transaction_id)) $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;

            // Verify that the nonce is valid.
            if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'mec_transaction_form_' . $transaction_id)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NONCE_IS_INVALID',
                        'message' => __(
                            'Request is invalid!',
                            'mec'
                        ),
                    )
                );
            }

            // Stripe Payment Details
            $payment_method_id = isset($_GET['payment_method_id']) ? sanitize_text_field($_GET['payment_method_id']) : '';
            $payment_intent_id = isset($_GET['payment_intent_id']) ? sanitize_text_field($_GET['payment_intent_id']) : '';

            // Pay by Stripe Token
            $results = $this->pay($payment_method_id, $payment_intent_id, $transaction_id);

            // Payment is invalid
            if (!$results['success']) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'INVALID_PAYMENT',
                        'message' => $results['message'],
                    )
                );
            }

            // Payment requires more actions
            if ($results['success'] == 2) {
                $this->response(
                    array(
                        'success' => 2,
                        'requires_action' => 1,
                        'code' => 'REQUIRES_ACTION',
                        'payment_intent_client_secret' => $results['payment_intent_client_secret'],
                    )
                );
            }

            $transaction = $this->book->get_transaction($transaction_id);
            $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

            // Is there any attendee?
            if (!count($attendees)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NO_TICKET',
                        'message' => __(
                            'There is no attendee for booking!',
                            'mec'
                        ),
                    )
                );
            }

            $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
            $ex = explode(':', $attention_date);
            $date = trim($ex[0]);

            $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
            $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

            $ticket_ids = '';
            $attendees_info = array();

            foreach ($attendees as $attendee) {
                $ticket_ids .= $attendee['id'] . ',';
                if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
                else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
            }

            $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
            $user_id = $this->register_user($main_attendee);

            $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
            $book_id = $this->book->add(
                array(
                    'post_author' => $user_id,
                    'post_type' => $this->PT,
                    'post_title' => $book_subject,
                    'post_date' => $date,
                    'attendees_info' => $attendees_info,
                    'mec_attendees' => $attendees
                ),
                $transaction_id,
                $ticket_ids
            );

            update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_stripe');
            update_post_meta($book_id, 'mec_gateway_label', $this->label());

            // Fires after completely creating a new booking
            do_action('mec_booking_completed', $book_id);

            $redirect_to = '';
            if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);

            // Invoice Link
            $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
            $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

            $this->response(
                array(
                    'success' => 1,
                    'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
                    'data' => array(
                        'book_id' => $book_id,
                        'redirect_to' => $redirect_to,
                        'invoice_link' => $invoice_link,
                    ),
                )
            );
        }

        public function pay($payment_method_id, $payment_intent_id, $transaction_id)
        {
            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = isset($transaction['event_id']) ? $transaction['event_id'] : NULL;
            $event = get_post($event_id);

            $author_id = $event->post_author;
            $stripe_user_id = get_user_meta($author_id, 'mec_stripe_id', true);

            if ($stripe_user_id) $charge_options = array('stripe_account' => $stripe_user_id);
            else $charge_options = NULL;

            // Set Stripe Secret Key
            \Stripe\Stripe::setApiKey($this->options['secret_key']);

            // Get Status of Payment Intent
            if ($payment_intent_id) {
                $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id, $charge_options);

                $intent->confirm();

                if ($intent->status == 'succeeded') return array('success' => 1);
                else if ($intent->status == 'requires_confirmation') return array('success' => 0, 'message' => __('Your payment needs to get confirmed!', 'mec'));
                else return array('success' => 0, 'message' => __('Unknown Error!', 'mec'));
            }

            try {
                if ($stripe_user_id) {
                    $application_fee_amount = $this->get_fee_amount($transaction);

                    $charge = array(
                        'payment_method' => $payment_method_id,
                        'amount' => (isset($transaction['price']) ? ((int) ($transaction['price'] * 100)) : 0),
                        'currency' => $this->main->get_currency_code(),
                        'description' => sprintf(__('MEC Transaction ID: %s', 'mec'), $transaction_id),
                        'application_fee_amount' => (int) ($application_fee_amount * 100),
                        'confirmation_method' => 'manual',
                        'confirm' => true,
                    );
                } else {
                    $charge = array(
                        'payment_method' => $payment_method_id,
                        'amount' => (isset($transaction['price']) ? ((int) ($transaction['price'] * 100)) : 0),
                        'currency' => $this->main->get_currency_code(),
                        'description' => sprintf(__('MEC Transaction ID: %s', 'mec'), $transaction_id),
                        'confirmation_method' => 'manual',
                        'confirm' => true,
                    );
                }

                $intent = \Stripe\PaymentIntent::create($charge, $charge_options);
            } catch (Exception $e) {
                return array(
                    'success' => 0,
                    'message' => $e->getMessage(),
                );
            }

            if ($intent->status == 'requires_action' and $intent->next_action->type == 'use_stripe_sdk') {
                return array(
                    'success' => 2,
                    'requires_action' => true,
                    'payment_intent_client_secret' => $intent->client_secret
                );
            } elseif ($intent->status == 'succeeded') return array('success' => 1);
            else return array('success' => 0, 'message' => __('Unknown Error!', 'mec'));
        }

        public function get_fee_amount($transaction)
        {
            $fee = isset($this->options['fee']) ? $this->options['fee'] : 0;
            $fee_type = isset($this->options['fee_type']) ? $this->options['fee_type'] : 'amount';
            $fee_per = isset($this->options['fee_per']) ? $this->options['fee_per'] : 'ticket';

            if ($fee_type == 'amount') {
                if ($fee_per == 'ticket') {
                    $amount = $fee * count($transaction['tickets']);
                }
                // Booking
                else {
                    $amount = $fee;
                }
            }
            // Percent
            else {
                if ($fee_per == 'ticket') {
                    $tickets_price = 0;
                    foreach ($transaction['price_details']['details'] as $p) {
                        if (isset($p['type']) and $p['type'] == 'tickets') {
                            $tickets_price = $p['amount'];
                            break;
                        }
                    }

                    $amount = ($fee * $tickets_price) / 100;
                }
                // Booking
                else {
                    $amount = ($fee * $transaction['price']) / 100;
                }
            }

            return $amount;
        }

        public function checkout_form($transaction_id, $params = array())
        {
            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = isset($transaction['event_id']) ? $transaction['event_id'] : NULL;

            $event = get_post($event_id);

            $author_id = $event->post_author;
            $stripe_user_id = get_user_meta($author_id, 'mec_stripe_id', true);
            ?>
        <script type="text/javascript">
            var stripe;

            <?php if ($stripe_user_id) : ?>
                stripe = Stripe("<?php echo (isset($this->options['publishable_key']) ? $this->options['publishable_key'] : ''); ?>", {
                    stripeAccount: "<?php echo $stripe_user_id; ?>"
                });
            <?php else : ?>
                stripe = Stripe("<?php echo (isset($this->options['publishable_key']) ? $this->options['publishable_key'] : ''); ?>");
            <?php endif; ?>

            var elements = stripe.elements();
            var style = {
                base: {
                    color: '#32325d',
                    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
                    fontSmoothing: 'antialiased',
                    fontSize: '16px',
                    minHeight: '40px',
                    '::placeholder': {
                        color: '#aab7c4'
                    },
                },
                invalid: {
                    color: '#fa755a',
                    iconColor: '#fa755a'
                }
            };

            var card = elements.create('card');
            card.mount('#mec_card_element_stripe_connect_<?php echo $transaction_id; ?>');

            // Validation
            card.addEventListener('change', function(event) {
                // Show the Error
                if (event.error) jQuery('#mec_do_transaction_stripe_connect_message<?php echo $transaction_id; ?>').text(event.error.message).addClass('mec-error').show();
                // Hide the Message
                else jQuery("#mec_do_transaction_stripe_connect_message<?php echo $transaction_id; ?>").removeClass("mec-success mec-error").hide();
            });

            jQuery('#mec_do_transaction_stripe_connect_form<?php echo $transaction_id; ?>').on('submit', function(e) {
                // Prevent the form from submitting
                e.preventDefault();

                var form = jQuery(this);
                var transaction_id = '<?php echo $transaction_id; ?>';

                // No pressing the buy now button more than once
                form.find('button').prop('disabled', true);

                // Add loading Class to the button
                jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").addClass("loading");

                // Hide the Message
                jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).removeClass("mec-success mec-error").hide();

                var payer_name = jQuery("#mec_name_stripe_connect_" + transaction_id).val();
                stripe.createPaymentMethod('card', card, {
                    billing_details: {
                        name: payer_name
                    }
                }).then(function(result) {
                    if (result.error) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                        // Show the user what they did wrong
                        jQuery('#mec_do_transaction_stripe_connect_message' + transaction_id).text(result.error.message).addClass('mec-error').show();

                        // Make the submit clickable again
                        form.find('button').prop('disabled', false);
                    } else {
                        // Make the submit clickable again
                        form.find('button').prop('disabled', false);

                        // Hide the Message
                        jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).removeClass("mec-success mec-error").hide();

                        // Set Payment Method ID
                        jQuery("#mec_do_transaction_stripe_connect_payment_method_id" + transaction_id).val(result.paymentMethod.id);

                        var data = form.serialize();
                        jQuery.ajax({
                            type: "GET",
                            url: "<?php echo admin_url('admin-ajax.php', NULL); ?>",
                            data: data,
                            dataType: "JSON",
                            success: function(data) {
                                if (data.success === 1) {
                                    // Remove the loading Class from the button
                                    jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                    jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id).hide();
                                    jQuery(".mec-book-form-gateway-label").remove();
                                    jQuery("#mec_book_form_coupon").hide();

                                    jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).addClass("mec-success").html(data.message).show();

                                    // Show Invoice Link
                                    if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                        jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                                    }

                                    // Redirect to thank you page
                                    if (typeof data.data.redirect_to !== "undefined" && data.data.redirect_to !== "") {
                                        setTimeout(function() {
                                            window.location.href = data.data.redirect_to;
                                        }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                                    }
                                } else if (data.requires_action) {
                                    stripe.handleCardAction(data.payment_intent_client_secret).then(function(result) {
                                        if (result.error) {
                                            // Show the user what they did wrong
                                            jQuery('#mec_do_transaction_stripe_connect_message' + transaction_id).text(result.error.message).addClass('mec-error').show();

                                            // Remove the loading Class from the button
                                            jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                            // Make the submit clickable again
                                            form.find('button').prop('disabled', false);
                                        } else {
                                            // Set Payment Intent ID
                                            jQuery("#mec_do_transaction_stripe_connect_payment_intent_id" + transaction_id).val(result.paymentIntent.id);

                                            var data = form.serialize();
                                            jQuery.ajax({
                                                type: "GET",
                                                url: "<?php echo admin_url('admin-ajax.php', NULL); ?>",
                                                data: data,
                                                dataType: "JSON",
                                                success: function(data) {
                                                    if (data.success === 1) {
                                                        // Remove the loading Class from the button
                                                        jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                                        jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id).hide();
                                                        jQuery(".mec-book-form-gateway-label").remove();
                                                        jQuery("#mec_book_form_coupon").hide();

                                                        jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).addClass("mec-success").html(data.message).show();

                                                        // Show Invoice Link
                                                        if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                                            jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                                                        }

                                                        // Redirect to thank you page
                                                        if (typeof data.data.redirect_to !== "undefined" && data.data.redirect_to !== "") {
                                                            setTimeout(function() {
                                                                window.location.href = data.data.redirect_to;
                                                            }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                                                        }
                                                    } else {
                                                        // Remove the loading Class from the button
                                                        jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                                        jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).addClass("mec-error").html(data.message).show();
                                                    }
                                                },
                                                error: function(jqXHR, textStatus, errorThrown) {
                                                    // Remove the loading Class from the button
                                                    jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");
                                                }
                                            });
                                        }
                                    });
                                } else {
                                    // Remove the loading Class from the button
                                    jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");

                                    jQuery("#mec_do_transaction_stripe_connect_message" + transaction_id).addClass("mec-error").html(data.message).show();
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                // Remove the loading Class from the button
                                jQuery("#mec_do_transaction_stripe_connect_form" + transaction_id + " button[type=submit]").removeClass("loading");
                            }
                        });
                    }
                });
            });
        </script>
        <form id="mec_do_transaction_stripe_connect_form<?php echo $transaction_id; ?>">
            <div class="mec-form-row">
                <label for="mec_name_stripe_connect_<?php echo $transaction_id; ?>">
                    <?php _e('Name', 'mec'); ?>
                </label>
                <input id="mec_name_stripe_connect_<?php echo $transaction_id; ?>" type="text" />
            </div>
            <div class="mec-form-row">
                <label for="mec_card_element_stripe_connect_<?php echo $transaction_id; ?>">
                    <?php _e('Credit or debit card', 'mec'); ?>
                </label>
                <div id="mec_card_element_stripe_connect_<?php echo $transaction_id; ?>">
                </div>
            </div>
            <div class="mec-form-row">
                <input type="hidden" name="action" value="mec_do_transaction_stripe_connect" />
                <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
                <input type="hidden" name="gateway_id" value="<?php echo $this->id(); ?>" />
                <input type="hidden" name="payment_method_id" value="" id="mec_do_transaction_stripe_connect_payment_method_id<?php echo $transaction_id; ?>" />
                <input type="hidden" name="payment_intent_id" value="" id="mec_do_transaction_stripe_connect_payment_intent_id<?php echo $transaction_id; ?>" />
                <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
                <button type="submit"><?php echo __('Pay', 'mec'); ?></button>
            </div>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_stripe_connect_message<?php echo $transaction_id; ?>" role="alert"></div>
    <?php
        }

        public function options_form()
        {
            $pages = get_pages();
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle(); if(jQuery(this).is(':checked')) jQuery('#mec_gateways_op_status').prop('checked', 'checked');" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                                                                                                                        if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                                                                                                            echo 'checked="checked"';
                                                                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                                                                        ?> /> <?php _e('Stripe Connect', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>">
            <p><?php _e("Using this gateway, booking fee pays to the organizer account directly but you can get your fee in your Stripe account.", 'mec'); ?></p>
            <p><?php _e("If organizer connect his / her account then it will be the only enabled gateway for organizer events even if other gateways are enabled. Organizer Payment Module must be enabled to use this!", 'mec'); ?></p>
            <p><?php echo sprintf(__("You should set %s as Redirect URI in your stripe dashboard.", 'mec'), '<code>' . rtrim(get_home_url(), '/') . '/?mec-stripe-connect=1</code>'); ?></p>
            <br>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_organizer_comment"><?php _e('Comment for Organizer', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>organizer_comment" name="mec[gateways][<?php echo $this->id(); ?>][organizer_comment]"><?php echo (isset($this->options['organizer_comment']) and trim($this->options['organizer_comment'])) ? stripslashes($this->options['organizer_comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_client_id"><?php _e('Client ID', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_client_id" name="mec[gateways][<?php echo $this->id(); ?>][client_id]" value="<?php echo isset($this->options['client_id']) ? $this->options['client_id'] : ''; ?>">
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_secret_key"><?php _e('Secret Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_secret_key" name="mec[gateways][<?php echo $this->id(); ?>][secret_key]" value="<?php echo isset($this->options['secret_key']) ? $this->options['secret_key'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_publishable_key"><?php _e('Publishable Key', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_publishable_key" name="mec[gateways][<?php echo $this->id(); ?>][publishable_key]" value="<?php echo isset($this->options['publishable_key']) ? $this->options['publishable_key'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_fee"><?php _e('Your Fee', 'mec'); ?></label>
                <div class="mec-col-3">
                    <input type="number" id="mec_gateways<?php echo $this->id(); ?>_fee" name="mec[gateways][<?php echo $this->id(); ?>][fee]" value="<?php echo isset($this->options['fee']) ? $this->options['fee'] : 10; ?>">
                </div>
                <div class="mec-col-2">
                    <select id="mec_gateways<?php echo $this->id(); ?>_fee_type" name="mec[gateways][<?php echo $this->id(); ?>][fee_type]" title="<?php esc_attr_e('Fee Type', 'mec'); ?>">
                        <option value="amount" <?php echo ((isset($this->options['fee_type']) and $this->options['fee_type'] == 'amount') ? 'selected="selected"' : ''); ?>><?php _e('Amount', 'mec'); ?></option>
                        <option value="percent" <?php echo ((isset($this->options['fee_type']) and $this->options['fee_type'] == 'percent') ? 'selected="selected"' : ''); ?>><?php _e('Percent', 'mec'); ?></option>
                    </select>
                </div>
                <div class="mec-col-2">
                    <select id="mec_gateways<?php echo $this->id(); ?>_fee_per" name="mec[gateways][<?php echo $this->id(); ?>][fee_per]" title="<?php esc_attr_e('Per', 'mec'); ?>">
                        <option value="booking" <?php echo ((isset($this->options['fee_per']) and $this->options['fee_per'] == 'booking') ? 'selected="selected"' : ''); ?>><?php _e('Booking', 'mec'); ?></option>
                        <option value="ticket" <?php echo ((isset($this->options['fee_per']) and $this->options['fee_per'] == 'ticket') ? 'selected="selected"' : ''); ?>><?php _e('Ticket', 'mec'); ?></option>
                    </select>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_redirection_page"><?php _e('Redirection Page', 'mec'); ?></label>
                <div class="mec-col-4">
                    <select id="mec_gateways<?php echo $this->id(); ?>_redirection_page" name="mec[gateways][<?php echo $this->id(); ?>][redirection_page]">
                        <option value="">-----</option>
                        <?php foreach ($pages as $page) : if (!trim($page->post_title)) continue; ?>
                            <option value="<?php echo $page->ID; ?>" <?php echo ((isset($this->options['redirection_page']) and $this->options['redirection_page'] == $page->ID) ? 'selected="selected"' : ''); ?>><?php echo $page->post_title; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Redirection Page', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('Users will redirect to this page after connecting to your Stripe account. You can create a page to thank them. If you leave it empty then users will redirect to home page!', 'mec'); ?></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
        </div>
    <?php
        }

        public function op_enabled()
        {
            return true;
        }

        public function op_form($options = array())
        {
            $client_id = isset($this->options['client_id']) ? trim($this->options['client_id']) : NULL;
            $secret_key = isset($this->options['secret_key']) ? trim($this->options['secret_key']) : NULL;

            if (!$client_id or !$secret_key) return '';

            global $post;
            $strip_account_id = get_user_meta($post->post_author, 'mec_stripe_id', true);
            ?>
        <h4><?php echo $this->label(); ?></h4>
        <div class="mec-gateway-options-form">

            <?php if (isset($this->options['organizer_comment']) and trim($this->options['organizer_comment'])) : ?>
                <p><?php echo $this->options['organizer_comment']; ?></p>
            <?php endif; ?>

            <div class="mec-form-row">
                <div class="mec-col-12">

                    <?php if (!$strip_account_id) : ?>
                        <a id="mec_gateway_options_form_stripe_connection_button" class="button button-primary" onclick="mec_stripe_connection_checker();" href="https://connect.stripe.com/oauth/authorize?response_type=code&client_id=<?php echo $client_id; ?>&scope=read_write" target="_blank"><?php _e('Connect Your Account', 'mec'); ?></a>
                    <?php endif; ?>

                    <div id="mec_gateway_options_form_stripe_connection_success" class="<?php echo $strip_account_id ? '' : 'mec-util-hidden'; ?>">
                        <p class="mec-success"><?php _e("You're connected to our account successfully and you will receive payments in your stripe account directly after deducting the fees.", 'mec'); ?></p>
                    </div>

                </div>
            </div>
        </div>
        <script>
            function mec_stripe_connection_checker() {
                jQuery.ajax({
                    type: "GET",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: "action=mec_check_stripe_connection",
                    dataType: "JSON",
                    success: function(data) {
                        if (data.success === 1) {
                            jQuery("#mec_gateway_options_form_stripe_connection_button").hide();

                            jQuery("#mec_gateway_options_form_stripe_connection_success").removeClass("mec-util-hidden");
                        } else {
                            setTimeout(function() {
                                mec_stripe_connection_checker();
                            }, 10000);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {}
                });
            }
        </script>
    <?php
        }

        public function check_connection()
        {
            $success = 2;
            $message = __('Waiting for getting response from gateway.', 'mec');

            $current_user_id = get_current_user_id();
            $stripe_user_id = get_user_meta($current_user_id, 'mec_stripe_id', true);

            if ($stripe_user_id) {
                $success = 1;
                $message = __('User get connected sucessfully!', 'mec');
            }

            $this->response(
                array(
                    'success' => $success,
                    'message' => $message,
                )
            );
        }
    }

    do_action('after_MEC_gateway');

    class MEC_gateway_pay_locally extends MEC_gateway
    {
        public $id = 1;
        public $options;

        public function __construct()
        {
            parent::__construct();

            // Gateway options
            $this->options = $this->options();

            // Register actions
            $this->factory->action('wp_ajax_mec_do_transaction_pay_locally', array($this, 'do_transaction'));
            $this->factory->action('wp_ajax_nopriv_mec_do_transaction_pay_locally', array($this, 'do_transaction'));
        }

        public function label()
        {
            return __('Pay Locally', 'mec');
        }

        public function color()
        {
            return '#FF00FF';
        }

        public function options_form()
        {
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle();" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                            if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                echo 'checked="checked"';
                                                                                                                                                                                                            }
                                                                                                                                                                                                            ?> /> <?php _e('Pay Locally', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>
										">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
        </div>
    <?php
        }

        public function checkout_form($transaction_id, $params = array())
        {
            ?>
        <script type="text/javascript">
            jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?>").on("submit", function(event) {
                event.preventDefault();
                jQuery(this).find('button').attr('disabled', true);
                // Add loading Class to the button
                jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?> button[type=submit]").addClass("loading");
                jQuery("#mec_do_transaction_pay_locally_message<?php echo $transaction_id; ?>").removeClass("mec-success mec-error").hide();

                var data = jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?>").serialize();
                jQuery.ajax({
                    type: "GET",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: data,
                    dataType: "JSON",
                    success: function(data) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?> button[type=submit]").removeClass("loading");

                        jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?>").hide();
                        jQuery(".mec-book-form-gateway-label").remove();

                        if (data.success) {
                            jQuery("#mec_book_form_coupon").hide();
                            jQuery("#mec_do_transaction_pay_locally_message<?php echo $transaction_id; ?>").addClass("mec-success").html(data.message).show();

                            // Show Invoice Link
                            if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                jQuery("#mec_do_transaction_pay_locally_message<?php echo $transaction_id; ?>").append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                            }

                            // Redirect to thank you page
                            if (typeof data.data.redirect_to != "undefined" && data.data.redirect_to != "") {
                                setTimeout(function() {
                                    window.location.href = data.data.redirect_to;
                                }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                            }
                            jQuery(this).find('button').removeAttr('disabled');
                        } else {
                            jQuery("#mec_do_transaction_pay_locally_message<?php echo $transaction_id; ?>").addClass("mec-error").html(data.message).show();
                        }

                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?> button[type=submit]").removeClass("loading");
                    }
                });
            });
        </script>
        <form id="mec_do_transaction_pay_locally_form<?php echo $transaction_id; ?>">
            <input type="hidden" name="action" value="mec_do_transaction_pay_locally" />
            <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
            <input type="hidden" name="gateway_id" value="<?php echo $this->id(); ?>" />
            <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
            <button type="submit"><?php _e('Submit', 'mec'); ?></button>
            <?php do_action('mec_booking_checkout_form_before_end', $transaction_id); ?>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_pay_locally_message<?php echo $transaction_id; ?>"></div>
    <?php
        }

        public function do_transaction($transaction_id = null)
        {
            if (!trim($transaction_id)) {
                $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;
            }

            // Verify that the nonce is valid.
            if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'mec_transaction_form_' . $transaction_id)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NONCE_IS_INVALID',
                        'message' => __(
                            'Request is invalid!',
                            'mec'
                        ),
                    )
                );
            }

            $transaction = $this->book->get_transaction($transaction_id);
            $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

            $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
            $ex = explode(':', $attention_date);
            $date = trim($ex[0]);

            // Is there any attendee?
            if (!count($attendees)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NO_TICKET',
                        'message' => __(
                            'There is no attendee for booking!',
                            'mec'
                        ),
                    )
                );
            }

            $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
            $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

            $ticket_ids = '';
            $attendees_info = array();

            foreach ($attendees as $attendee) {
                $ticket_ids .= $attendee['id'] . ',';
                if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
                else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
            }

            $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
            $user_id = $this->register_user($main_attendee);

            $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
            $book_id = $this->book->add(
                array(
                    'post_author' => $user_id,
                    'post_type' => $this->PT,
                    'post_title' => $book_subject,
                    'post_date' => $date,
                    'attendees_info' => $attendees_info,
                    'mec_attendees' => $attendees
                ),
                $transaction_id,
                $ticket_ids
            );

            update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_pay_locally');
            update_post_meta($book_id, 'mec_gateway_label', $this->label());

            // Fires after completely creating a new booking
            do_action('mec_booking_completed', $book_id);

            $redirect_to = '';
            if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) {
                $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);
            }

            // Invoice Link
            $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
            $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

            $this->response(
                array(
                    'success' => 1,
                    'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
                    'data' => array(
                        'book_id' => $book_id,
                        'redirect_to' => $redirect_to,
                        'invoice_link' => $invoice_link,
                    ),
                )
            );
        }
    }

    class MEC_gateway_paypal_express extends MEC_gateway
    {
        public $id = 2;
        public $options;

        public function __construct()
        {
            parent::__construct();

            // Gateway options
            $this->options = $this->options();

            // Register actions
            $this->factory->action('wp_ajax_mec_check_transaction_paypal_express', array($this, 'check_transaction'));
            $this->factory->action('wp_ajax_nopriv_mec_check_transaction_paypal_express', array($this, 'check_transaction'));
        }

        public function label()
        {
            return __('PayPal Express', 'mec');
        }

        public function color()
        {
            return '#191970';
        }

        public function op_enabled()
        {
            return true;
        }

        public function op_form($options = array())
        {
            ?>
        <h4><?php echo $this->label(); ?></h4>
        <div class="mec-gateway-options-form">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_account"><?php _e('Business Account', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_account" name="mec[op][<?php echo $this->id(); ?>][account]" value="<?php echo isset($options['account']) ? $options['account'] : ''; ?>" />
                    <span class="mec-tooltip">
                        <div class="box top">
                            <h5 class="title"><?php _e('Business Account', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('Normally PayPal Email.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
        </div>
    <?php
        }

        public function options_form()
        {
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle();" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                            if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                echo 'checked="checked"';
                                                                                                                                                                                                            }
                                                                                                                                                                                                            ?> /> <?php _e('PayPal Express', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>
										">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_account"><?php _e('Business Account', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_account" name="mec[gateways][<?php echo $this->id(); ?>][account]" value="<?php echo isset($this->options['account']) ? $this->options['account'] : ''; ?>" />
                    <span class="mec-tooltip">
                        <div class="box top">
                            <h5 class="title"><?php _e('Business Account', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('Normally PayPal Email.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_mode"><?php _e('Mode', 'mec'); ?></label>
                <div class="mec-col-4">
                    <select id="mec_gateways<?php echo $this->id(); ?>_mode" name="mec[gateways][<?php echo $this->id(); ?>][mode]">
                        <option value="live" <?php echo (isset($this->options['mode']) and $this->options['mode'] == 'live') ? 'selected="selected"' : ''; ?>><?php _e('Live', 'mec'); ?></option>
                        <option value="sandbox" <?php echo (isset($this->options['mode']) and $this->options['mode'] == 'sandbox') ? 'selected="selected"' : ''; ?>><?php _e('Sandbox', 'mec'); ?></option>
                    </select>
                </div>
            </div>
        </div>
    <?php
        }

        public function get_api_url()
        {
            $live = 'https://www.paypal.com/cgi-bin/webscr';
            $sandbox = 'https://www.sandbox.paypal.com/cgi-bin/webscr';

            if ($this->options['mode'] == 'live') $url = $live;
            else $url = $sandbox;

            return $url;
        }

        public function get_cancel_url($event_id)
        {
            return trim(get_permalink($event_id), '/') . '/gateway-cancel/';
        }

        public function get_notify_url()
        {
            return $this->main->URL('mec') . 'app/features/gateways/paypal_ipn.php';
        }

        public function get_return_url($event_id)
        {
            return trim(get_permalink($event_id), '/') . '/gateway-return/';
        }

        public function checkout_form($transaction_id, $params = array())
        {
            // Get Options Compatible with Organizer Payment
            $options = $this->options($transaction_id);

            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = isset($transaction['event_id']) ? $transaction['event_id'] : 0;
            $tickets_count = isset($transaction['tickets']) ? count($transaction['tickets']) : 1;
            ?>
        <script type="text/javascript">
            function mec_paypal_express_pay_checker(transaction_id) {
                // Add loading Class to the button
                jQuery("#mec_do_transaction_paypal_express_form" + transaction_id + " button[type=submit]").addClass("loading");
                jQuery("#mec_do_transaction_paypal_express_message" + transaction_id).removeClass("mec-success mec-error").hide();

                jQuery.ajax({
                    type: "GET",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: "action=mec_check_transaction_paypal_express&transaction_id=" + transaction_id,
                    dataType: "JSON",
                    success: function(data) {
                        if (data.success == 1) {
                            // Remove the loading Class from the button
                            jQuery("#mec_do_transaction_paypal_express_form" + transaction_id + " button[type=submit]").removeClass("loading");

                            jQuery("#mec_do_transaction_paypal_express_form" + transaction_id).hide();
                            jQuery(".mec-book-form-gateway-label").remove();
                            jQuery("#mec_book_form_coupon").hide();

                            jQuery("#mec_do_transaction_paypal_express_message" + transaction_id).addClass("mec-success").html(data.message).show();

                            // Show Invoice Link
                            if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                jQuery("#mec_do_transaction_paypal_express_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                            }

                            // Redirect to thank you page
                            if (typeof data.data.redirect_to != "undefined" && data.data.redirect_to != "") {
                                setTimeout(function() {
                                    window.location.href = data.data.redirect_to;
                                }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                            }
                        } else if (data.success == 0) {
                            // Remove the loading Class from the button
                            jQuery("#mec_do_transaction_paypal_express_form" + transaction_id + " button[type=submit]").removeClass("loading");

                            jQuery("#mec_do_transaction_paypal_express_message" + transaction_id).addClass("mec-error").html(data.message).show();
                        } else {
                            setTimeout(function() {
                                mec_paypal_express_pay_checker(transaction_id)
                            }, 10000);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_paypal_express_form" + transaction_id + " button[type=submit]").removeClass("loading");
                    }
                });
            }
        </script>
        <form id="mec_do_transaction_paypal_express_form<?php echo $transaction_id; ?>" action="<?php echo $this->get_api_url(); ?>" method="post" target="_blank" onsubmit="mec_paypal_express_pay_checker('<?php echo $transaction_id; ?>');">
            <input type="hidden" name="cmd" value="_xclick" />
            <input type="hidden" name="rm" value="2" />
            <input type="hidden" name="business" value="<?php echo (isset($options['account']) ? $options['account'] : null); ?>" />
            <input type="hidden" name="item_name" value="<?php echo esc_attr(get_the_title($event_id)); ?>" />
            <input type="hidden" name="item_number" value="<?php echo $tickets_count; ?>" />
            <input type="hidden" name="amount" value="<?php echo (isset($transaction['price']) ? $transaction['price'] : 0); ?>" />
            <input type="hidden" name="currency_code" value="<?php echo $this->main->get_currency_code(); ?>" />
            <input type="hidden" name="cancel_return" value="<?php echo $this->get_cancel_url($event_id); ?>" />
            <input type="hidden" name="notify_url" value="<?php echo $this->get_notify_url(); ?>" />
            <input type="hidden" name="return" value="<?php echo $this->get_return_url($event_id); ?>" />
            <input type="hidden" name="custom" value="<?php echo base64_encode(json_encode(array('transaction_id' => $transaction_id))); ?>" />
            <button type="submit"><?php _e('Pay', 'mec'); ?></button>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_paypal_express_message<?php echo $transaction_id; ?>"></div>
    <?php
        }

        public function do_transaction($transaction_id = null)
        {
            $transaction = $this->book->get_transaction($transaction_id);
            $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

            $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
            $ex = explode(':', $attention_date);
            $date = trim($ex[0]);

            // Is there any attendee?
            if (!count($attendees)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NO_TICKET',
                        'message' => __(
                            'There is no attendee for booking!',
                            'mec'
                        ),
                    )
                );
            }

            $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
            $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

            $ticket_ids = '';
            $attendees_info = array();

            foreach ($attendees as $attendee) {
                $ticket_ids .= $attendee['id'] . ',';
                if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
                else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
            }

            $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
            $user_id = $this->register_user($main_attendee);

            $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
            $book_id = $this->book->add(
                array(
                    'post_author' => $user_id,
                    'post_type' => $this->PT,
                    'post_title' => $book_subject,
                    'post_date' => $date,
                    'attendees_info' => $attendees_info,
                    'mec_attendees' => $attendees
                ),
                $transaction_id,
                $ticket_ids
            );

            update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_paypal_express');
            update_post_meta($book_id, 'mec_gateway_label', $this->label());

            // Fires after completely creating a new booking
            do_action('mec_booking_completed', $book_id);
        }

        public function validate_express_payment($vars)
        {
            // Check if Paypal is disabled
            if (!$this->enabled()) {
                return false;
            }

            $custom = $this->decode_custom($vars['custom']);
            $transaction_id = $custom['transaction_id'];

            $transaction = $this->book->get_transaction($transaction_id);

            $request_str = $this->get_request_string($vars) . '&cmd=_notify-validate';
            $response_str = $this->get_paypal_response($request_str, $this->get_api_url());

            if (strpos($response_str, 'VERIFIED') !== false) $status = 1;
            else $status = 0;

            $amount = $vars['mc_gross'];

            // Compare paid amount with transaction amount
            $valid = ($amount >= $transaction['price'] and $status) ? true : false;
            if ($valid) {
                // Mark it as done
                $transaction['done'] = 1;
                $this->book->update_transaction($transaction_id, $transaction);

                $this->do_transaction($transaction_id);
                return true;
            } else {
                // Mark it as done
                $transaction['done'] = 0;
                $this->book->update_transaction($transaction_id, $transaction);

                return false;
            }
        }

        public function check_transaction($transaction_id = null)
        {
            if (!trim($transaction_id)) $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;

            $transaction = $this->book->get_transaction($transaction_id);

            $success = 2;
            $message = __('Waiting for getting response from gateway.', 'mec');
            $data = array();

            if (isset($transaction['done']) and $transaction['done'] == 1) {
                $success = 1;
                $message = $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec'));

                if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) $data['redirect_to'] = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);

                // Invoice Link
                $data['invoice_link'] = $this->book->get_invoice_link($transaction_id);
            } elseif (isset($transaction['done']) and $transaction['done'] == 0) {
                $success = 0;
                $message = __('Payment was invalid! Booking failed.', 'mec');
            }

            $this->response(
                array(
                    'success' => $success,
                    'message' => $message,
                    'data' => $data,
                )
            );
        }
    }

    class MEC_gateway_paypal_credit_card extends MEC_gateway
    {
        public $id = 3;
        public $options;

        public function __construct()
        {
            parent::__construct();

            // Gateway options
            $this->options = $this->options();

            // Register actions
            $this->factory->action('wp_ajax_mec_do_transaction_paypal_credit_card', array($this, 'do_transaction'));
            $this->factory->action('wp_ajax_nopriv_mec_do_transaction_paypal_credit_card', array($this, 'do_transaction'));
        }

        public function label()
        {
            return __('PayPal Credit Card', 'mec');
        }

        public function color()
        {
            return '#36A2EB';
        }

        public function get_api_url()
        {
            $live = 'https://api-3t.paypal.com/nvp';
            $sandbox = 'https://api-3t.sandbox.paypal.com/nvp';

            if ($this->options['mode'] == 'live') {
                $url = $live;
            } else {
                $url = $sandbox;
            }

            return $url;
        }

        public function op_enabled()
        {
            return true;
        }

        public function op_form($options = array())
        {
            ?>
        <h4><?php echo $this->label(); ?></h4>
        <div class="mec-gateway-options-form">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_api_username"><?php _e('API Username', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_api_username" name="mec[op][<?php echo $this->id(); ?>][api_username]" value="<?php echo isset($options['api_username']) ? $options['api_username'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_api_password"><?php _e('API Password', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_api_password" name="mec[op][<?php echo $this->id(); ?>][api_password]" value="<?php echo isset($options['api_password']) ? $options['api_password'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_op<?php echo $this->id(); ?>_api_signature"><?php _e('API Signature', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_op<?php echo $this->id(); ?>_api_signature" name="mec[op][<?php echo $this->id(); ?>][api_signature]" value="<?php echo isset($options['api_signature']) ? $options['api_signature'] : ''; ?>" />
                </div>
            </div>
        </div>
    <?php
        }

        public function options_form()
        {
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle();" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                            if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                echo 'checked="checked"';
                                                                                                                                                                                                            }
                                                                                                                                                                                                            ?> /> <?php _e('PayPal Credit Card', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>
										">
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                <div class="mec-col-4">
                    <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                    <span class="mec-tooltip">
                        <div class="box top">
                            <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                            <div class="content">
                                <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/booking/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                            </div>
                        </div>
                        <i title="" class="dashicons-before dashicons-editor-help"></i>
                    </span>
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_api_username"><?php _e('API Username', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_api_username" name="mec[gateways][<?php echo $this->id(); ?>][api_username]" value="<?php echo isset($this->options['api_username']) ? $this->options['api_username'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_api_password"><?php _e('API Password', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_api_password" name="mec[gateways][<?php echo $this->id(); ?>][api_password]" value="<?php echo isset($this->options['api_password']) ? $this->options['api_password'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_api_signature"><?php _e('API Signature', 'mec'); ?></label>
                <div class="mec-col-4">
                    <input type="text" id="mec_gateways<?php echo $this->id(); ?>_api_signature" name="mec[gateways][<?php echo $this->id(); ?>][api_signature]" value="<?php echo isset($this->options['api_signature']) ? $this->options['api_signature'] : ''; ?>" />
                </div>
            </div>
            <div class="mec-form-row">
                <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_mode"><?php _e('Mode', 'mec'); ?></label>
                <div class="mec-col-4">
                    <select id="mec_gateways<?php echo $this->id(); ?>_mode" name="mec[gateways][<?php echo $this->id(); ?>][mode]">
                        <option value="live" <?php echo (isset($this->options['mode']) and $this->options['mode'] == 'live') ? 'selected="selected"' : ''; ?>><?php _e('Live', 'mec'); ?></option>
                        <option value="sandbox" <?php echo (isset($this->options['mode']) and $this->options['mode'] == 'sandbox') ? 'selected="selected"' : ''; ?>><?php _e('Sandbox', 'mec'); ?></option>
                    </select>
                </div>
            </div>
        </div>
    <?php
        }

        public function checkout_form($transaction_id, $params = array())
        {
            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = isset($transaction['event_id']) ? $transaction['event_id'] : 0;
            ?>
        <script type="text/javascript">
            function mec_paypal_credit_card_send_request(transaction_id) {
                // Add loading Class to the button
                jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id + " button[type=submit]").addClass("loading");
                jQuery("#mec_do_transaction_paypal_credit_card_message" + transaction_id).removeClass("mec-success mec-error").hide();

                var data = jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id).serialize();
                jQuery.ajax({
                    type: "GET",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: data,
                    dataType: "JSON",
                    success: function(data) {
                        if (data.success == 1) {
                            // Remove the loading Class from the button
                            jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id + " button[type=submit]").removeClass("loading");

                            jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id).hide();
                            jQuery(".mec-book-form-gateway-label").remove();
                            jQuery("#mec_book_form_coupon").hide();

                            jQuery("#mec_do_transaction_paypal_credit_card_message" + transaction_id).addClass("mec-success").html(data.message).show();

                            // Show Invoice Link
                            if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                jQuery("#mec_do_transaction_paypal_credit_card_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                            }

                            // Redirect to thank you page
                            if (typeof data.data.redirect_to != "undefined" && data.data.redirect_to != "") {
                                setTimeout(function() {
                                    window.location.href = data.data.redirect_to;
                                }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                            }
                        } else {
                            // Remove the loading Class from the button
                            jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id + " button[type=submit]").removeClass("loading");

                            jQuery("#mec_do_transaction_paypal_credit_card_message" + transaction_id).addClass("mec-error").html(data.message).show();
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_paypal_credit_card_form" + transaction_id + " button[type=submit]").removeClass("loading");
                    }
                });
            }
        </script>
        <form id="mec_do_transaction_paypal_credit_card_form<?php echo $transaction_id; ?>" onsubmit="mec_paypal_credit_card_send_request('<?php echo $transaction_id; ?>'); return false;">
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_first_name"><?php echo __('First name', 'mec'); ?></label>
                <input type="text" name="first_name" id="mec_paypal_credit_card_first_name" />
            </div>
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_last_name"><?php echo __('Last name', 'mec'); ?></label>
                <input type="text" name="last_name" id="mec_paypal_credit_card_last_name" />
            </div>
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_card_type"><?php echo __('Card Type', 'mec'); ?></label>
                <select name="card_type" id="mec_paypal_credit_card_card_type">
                    <option value="Visa"><?php echo __('Visa', 'mec'); ?></option>
                    <option value="MasterCard"><?php echo __('MasterCard', 'mec'); ?></option>
                    <option value="Discover"><?php echo __('Discover', 'mec'); ?></option>
                    <option value="Amex"><?php echo __('American Express', 'mec'); ?></option>
                </select>
            </div>
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_cc_number"><?php echo __('CC Number', 'mec'); ?></label>
                <input type="text" name="cc_number" id="mec_paypal_credit_card_cc_number" />
            </div>
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_expiration_date_month"><?php echo __('Expiration Date', 'mec'); ?></label>
                <select name="expiration_date_month" id="mec_paypal_credit_card_expiration_date_month">
                    <?php foreach (array('01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12') as $month) : ?>
                        <option value="<?php echo $month; ?>"><?php echo $month; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="expiration_date_year" id="mec_paypal_credit_card_expiration_date_year">
                    <?php
                            for (
                                $i = 0;
                                $i <= 10;
                                $i++
                            ) :
                                $y = date('Y', strtotime('+' . $i . ' years'));
                                ?>
                        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="mec-form-row">
                <label for="mec_paypal_credit_card_cvv2"><?php echo __('CVV2', 'mec'); ?></label>
                <input type="text" name="cvv2" id="mec_paypal_credit_card_cvv2" />
            </div>
            <div class="mec-form-row">
                <input type="hidden" name="action" value="mec_do_transaction_paypal_credit_card" />
                <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
                <input type="hidden" name="gateway_id" value="<?php echo $this->id(); ?>" />
                <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
                <button type="submit"><?php echo __('Pay', 'mec'); ?></button>
            </div>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_paypal_credit_card_message<?php echo $transaction_id; ?>"></div>
    <?php
        }

        public function do_transaction($transaction_id = null)
        {
            if (!trim($transaction_id)) {
                $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;
            }

            // Verify that the nonce is valid.
            if (!wp_verify_nonce(sanitize_text_field($_GET['_wpnonce']), 'mec_transaction_form_' . $transaction_id)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NONCE_IS_INVALID',
                        'message' => __(
                            'Request is invalid!',
                            'mec'
                        ),
                    )
                );
            }

            $transaction = $this->book->get_transaction($transaction_id);
            $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

            $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
            $ex = explode(':', $attention_date);
            $date = trim($ex[0]);

            // Is there any attendee?
            if (!count($attendees)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NO_TICKET',
                        'message' => __(
                            'There is no attendee for booking!',
                            'mec'
                        ),
                    )
                );
            }

            $validate = $this->validate($_GET);
            if (!$validate) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'PAYMENT_IS_INVALID',
                        'message' => __(
                            'Payment is invalid.',
                            'mec'
                        ),
                    )
                );
            }

            $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
            $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

            $ticket_ids = '';
            $attendees_info = array();

            foreach ($attendees as $attendee) {
                $ticket_ids .= $attendee['id'] . ',';
                if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
                else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
            }

            $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
            $user_id = $this->register_user($main_attendee);

            $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
            $book_id = $this->book->add(
                array(
                    'post_author' => $user_id,
                    'post_type' => $this->PT,
                    'post_title' => $book_subject,
                    'post_date' => $date,
                    'attendees_info' => $attendees_info,
                    'mec_attendees' => $attendees
                ),
                $transaction_id,
                $ticket_ids
            );

            update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_paypal_credit_card');
            update_post_meta($book_id, 'mec_gateway_label', $this->label());

            // Fires after completely creating a new booking
            do_action('mec_booking_completed', $book_id);

            $redirect_to = '';
            if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) {
                $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);
            }

            // Invoice Link
            $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
            $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

            $this->response(
                array(
                    'success' => 1,
                    'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
                    'data' => array(
                        'book_id' => $book_id,
                        'redirect_to' => $redirect_to,
                        'invoice_link' => $invoice_link,
                    ),
                )
            );
        }

        public function validate($vars = array())
        {
            $card_type = (isset($vars['card_type']) ? sanitize_text_field($vars['card_type']) : null);
            $cc_number = (isset($vars['cc_number']) ? sanitize_text_field($vars['cc_number']) : null);
            $cvv2 = (isset($vars['cvv2']) ? sanitize_text_field($vars['cvv2']) : null);
            $first_name = (isset($vars['first_name']) ? sanitize_text_field($vars['first_name']) : null);
            $last_name = (isset($vars['last_name']) ? sanitize_text_field($vars['last_name']) : null);
            $exp_date_month = (isset($vars['expiration_date_month']) ? sanitize_text_field($vars['expiration_date_month']) : null);
            $exp_date_year = (isset($vars['expiration_date_year']) ? sanitize_text_field($vars['expiration_date_year']) : null);

            // Check Card details
            if (!$card_type or !$cc_number or !$cvv2) {
                return false;
            }

            $transaction_id = isset($vars['transaction_id']) ? sanitize_text_field($vars['transaction_id']) : 0;

            // Get Options Compatible with Organizer Payment
            $options = $this->options($transaction_id);

            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = isset($transaction['event_id']) ? $transaction['event_id'] : 0;

            $expdate = $exp_date_month . $exp_date_year;
            $params = array(
                'METHOD' => 'DoDirectPayment',
                'USER' => (isset($options['api_username']) ? $options['api_username'] : null),
                'PWD' => (isset($options['api_password']) ? $options['api_password'] : null),
                'SIGNATURE' => (isset($options['api_signature']) ? $options['api_signature'] : null),
                'VERSION' => 90.0,
                'CREDITCARDTYPE' => $card_type,
                'ACCT' => $cc_number,
                'EXPDATE' => $expdate,
                'CVV2' => $cvv2,
                'FIRSTNAME' => $first_name,
                'LASTNAME' => $last_name,
                'AMT' => (isset($transaction['price']) ? $transaction['price'] : 0),
                'CURRENCYCODE' => $this->main->get_currency_code(),
                'DESC' => get_the_title($event_id),
            );

            $request_str = $this->get_request_string($params);
            $response_str = $this->get_paypal_response($request_str, $this->get_api_url());

            $results = $this->normalize_NVP($response_str);

            $status = strpos(strtolower($results['ACK']), 'success') !== false ? 1 : 0;
            $amount = $results['AMT'];

            // Compare paid amount with transaction amount
            $valid = ($amount >= $transaction['price'] and $status) ? true : false;

            return $valid;
        }

        public function normalize_NVP($nvp_string)
        {
            $Array = array();
            while (strlen($nvp_string)) {
                // name
                $keypos = strpos($nvp_string, '=');
                $keyval = substr($nvp_string, 0, $keypos);

                // value
                $valuepos = strpos($nvp_string, '&') ? strpos($nvp_string, '&') : strlen($nvp_string);
                $valval = substr($nvp_string, $keypos + 1, $valuepos - $keypos - 1);

                // decoding the respose
                $Array[$keyval] = urldecode($valval);
                $nvp_string = substr($nvp_string, $valuepos + 1, strlen($nvp_string));
            }

            return $Array;
        }
    }

    class MEC_gateway_woocommerce extends MEC_gateway
    {

        public $id = 6;
        public $options;

        public function __construct()
        {
            parent::__construct();

            // Gateway options
            $this->options = $this->options();

            // Register actions
            $this->factory->action('wp_ajax_mec_create_order_woocommerce', array($this, 'create_order'));
            $this->factory->action('wp_ajax_nopriv_mec_create_order_woocommerce', array($this, 'create_order'));

            $this->factory->action('wp_ajax_mec_check_transaction_woocommerce', array($this, 'check_transaction'));
            $this->factory->action('wp_ajax_nopriv_mec_check_transaction_woocommerce', array($this, 'check_transaction'));

            $this->factory->action('woocommerce_order_status_completed', array($this, 'after_order_completed'), 10, 1);
            $this->factory->action('woocommerce_thankyou', array($this, 'after_payment'), 10, 1);

            $this->factory->action('woocommerce_order_status_cancelled', array($this, 'after_order_cancellation'), 10, 1);
        }

        public function label()
        {
            return __('Pay by WooCommerce', 'mec');
        }

        public function color()
        {
            return '#FF6384';
        }

        public function enabled()
        {
            return ((isset($this->options['status']) and $this->options['status']) ? (function_exists('wc_create_order') ? true : false) : false);
        }

        public function create_order()
        {
            $transaction_id = isset($_POST['transaction_id']) ? sanitize_text_field($_POST['transaction_id']) : 0;

            // Verify that the nonce is valid.
            if (!wp_verify_nonce($_POST['_wpnonce'], 'mec_transaction_form_' . $transaction_id)) {
                $this->response(
                    array(
                        'success' => 0,
                        'code' => 'NONCE_IS_INVALID',
                        'message' => __(
                            'Request is invalid!',
                            'mec'
                        ),
                    )
                );
            }

            $transaction = $this->book->get_transaction($transaction_id);
            $event_id = $transaction['event_id'];

            // Now we create the order
            $order = wc_create_order(
                array(
                    'customer_id' => get_current_user_id(),
                )
            );

            // Set Transaction ID into the Order Meta Data, We will use it after WC checkout
            update_post_meta($order->get_id(), '_mec_transaction_id', $transaction_id);

            $attendee_name = isset($transaction['tickets']) ? $transaction['tickets'][0]['name'] : '';
            $ex = explode(' ', $attendee_name);

            $fname = $lname = '';
            // Update Order Billing First Name and Last Name
            if (trim($ex[0])) {
                $fname = $ex[0];
                update_post_meta($order->get_id(), '_billing_first_name', $fname);
            }
            if (trim($ex[1])) {
                $lname = implode(' ', array_slice($ex, 1));
                update_post_meta($order->get_id(), '_billing_last_name', $lname);
            }

            $order->set_address(
                [
                    'first_name' => $fname,
                    'last_name' => $lname,
                    'email' => $transaction['tickets'][0]['email'],
                ],
                'shipping'
            );
            $order->set_address(
                [
                    'first_name' => $fname,
                    'last_name' => $lname,
                    'email' => $transaction['tickets'][0]['email'],
                ],
                'billing'
            );

            $fee = new stdClass();
            $fee->name = sprintf(__('Booking fee for %s', 'mec'), get_the_title($event_id));
            $fee->taxable = 0;
            $fee->tax_class = 0;
            $fee->amount = (isset($transaction['price']) ? $transaction['price'] : 0);
            $fee->tax = null;
            $fee->tax_data = null;

            $order->add_fee($fee);
            $order->calculate_totals();

            $url = $order->get_checkout_payment_url();
            $this->response(
                array(
                    'success' => 1,
                    'message' => __('Your order is created. Please proceed with checkout.', 'mec'),
                    'data' => array(
                        'url' => $url,
                        'id' => $order->get_id(),
                    ),
                )
            );
        }

        public function do_transaction($transaction_id = null, $order_id = null)
        {
            $transaction = $this->book->get_transaction($transaction_id);
            $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

            // Is there any attendee?
            if (!count($attendees)) {
                return array(
                    'success' => 0,
                    'code' => 'NO_TICKET',
                    'message' => __('There is no attendee for booking!', 'mec'),
                );
            }

            $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
            $ex = explode(':', $attention_date);
            $date = trim($ex[0]);

            $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
            $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

            $ticket_ids = '';
            $attendees_info = array();

            foreach ($attendees as $attendee) {
                $ticket_ids .= $attendee['id'] . ',';
                if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
                else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
            }

            $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
            $user_id = $this->register_user($main_attendee);

            $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
            $book_id = $this->book->add(
                array(
                    'post_author' => $user_id,
                    'post_type' => $this->PT,
                    'post_title' => $book_subject,
                    'post_date' => $date,
                    'attendees_info' => $attendees_info,
                    'mec_attendees' => $attendees
                ),
                $transaction_id,
                $ticket_ids
            );

            update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_woocommerce');
            update_post_meta($book_id, 'mec_gateway_label', $this->label());

            // Fires after completely creating a new booking
            do_action('mec_booking_completed', $book_id);

            // Update WC Order client
            if ($order_id) {
                $customer = get_post_meta($order_id, '_customer_user', true);
                if ($customer != $user_id) {
                    $user_info = get_userdata($user_id);

                    update_post_meta($order_id, '_customer_user', $user_id);
                    update_post_meta($order_id, '_billing_email', $user_info->email);
                }
            }

            $redirect_to = '';
            if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) {
                $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);
            }

            // Invoice Link
            $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
            $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

            return array(
                'success' => 1,
                'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
                'data' => array(
                    'book_id' => $book_id,
                    'redirect_to' => $redirect_to,
                    'invoice_link' => $invoice_link,
                ),
            );
        }

        public function checkout_form($transaction_id, $params = array())
        {
            ?>
        <script type="text/javascript">
            jQuery('#mec_do_transaction_woocommerce_form<?php echo $transaction_id; ?>').on('submit', function(e) {
                // Prevent the form from submitting
                e.preventDefault();

                var transaction_id = '<?php echo $transaction_id; ?>';

                // Add loading Class to the button
                jQuery("#mec_do_transaction_woocommerce_form" + transaction_id + " button[type=submit]").addClass("loading");
                jQuery("#mec_do_transaction_woocommerce_message" + transaction_id).removeClass("mec-success mec-error").hide();

                var data = jQuery("#mec_do_transaction_woocommerce_form" + transaction_id).serialize();
                jQuery.ajax({
                    type: "POST",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: data,
                    dataType: "JSON",
                    success: function(data) {
                        if (data.success == 1) {
                            window.location.href = data.data.url;
                        } else {
                            // Remove the loading Class from the button
                            jQuery("#mec_do_transaction_woocommerce_form" + transaction_id + " button[type=submit]").removeClass("loading");

                            jQuery("#mec_do_transaction_woocommerce_message" + transaction_id).addClass("mec-error").html(data.message).show();
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // Remove the loading Class from the button
                        jQuery("#mec_do_transaction_woocommerce_form" + transaction_id + " button[type=submit]").removeClass("loading");
                    }
                });
            });

            function mec_woocommerce_pay_checker(transaction_id) {
                jQuery.ajax({
                    type: "GET",
                    url: "<?php echo admin_url('admin-ajax.php', null); ?>",
                    data: "action=mec_check_transaction_woocommerce&transaction_id=" + transaction_id,
                    dataType: "JSON",
                    success: function(data) {
                        if (data.success == 1) {
                            jQuery("#mec_do_transaction_woocommerce_message" + transaction_id).addClass("mec-success").html(data.message).show();
                            jQuery("#mec_do_transaction_woocommerce_checkout" + transaction_id).hide();

                            // Show Invoice Link
                            if (typeof data.data.invoice_link !== "undefined" && data.data.invoice_link != "") {
                                jQuery("#mec_do_transaction_woocommerce_message" + transaction_id).append(' <a class="mec-invoice-download" href="' + data.data.invoice_link + '"><?php echo esc_js(__('Download Invoice', 'mec')); ?></a>');
                            }

                            // Redirect to thank you page
                            if (typeof data.data.redirect_to != "undefined" && data.data.redirect_to != "") {
                                setTimeout(function() {
                                    window.location.href = data.data.redirect_to;
                                }, <?php echo ((isset($this->settings['booking_thankyou_page_time']) and trim($this->settings['booking_thankyou_page_time']) != '') ? (int) $this->settings['booking_thankyou_page_time'] : 2000); ?>);
                            }
                        } else if (data.success == 0) {
                            jQuery("#mec_do_transaction_woocommerce_message" + transaction_id).addClass("mec-error").html(data.message).show();
                        } else {
                            setTimeout(function() {
                                mec_woocommerce_pay_checker(transaction_id)
                            }, 10000);
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        setTimeout(function() {
                            mec_woocommerce_pay_checker(transaction_id)
                        }, 10000);
                    }
                });
            }
        </script>
        <form id="mec_do_transaction_woocommerce_form<?php echo $transaction_id; ?>">
            <div class="mec-form-row">
                <input type="hidden" name="action" value="mec_create_order_woocommerce" />
                <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
                <input type="hidden" name="gateway_id" value="<?php echo $this->id(); ?>" />
                <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
                <button type="submit"><?php echo __('Checkout', 'mec'); ?></button>
            </div>
        </form>
        <div class="mec-gateway-message mec-util-hidden" id="mec_do_transaction_woocommerce_message<?php echo $transaction_id; ?>"></div>
        <div class="mec-util-hidden" id="mec_do_transaction_woocommerce_checkout<?php echo $transaction_id; ?>">
            <a class="mec-woo-booking-checkout" target="_blank"><?php _e('Checkout', 'mec'); ?></a>
        </div>
    <?php
        }

        public function options_form()
        {
            ?>
        <div class="mec-form-row">
            <label>
                <input type="hidden" name="mec[gateways][<?php echo $this->id(); ?>][status]" value="0" />
                <input onchange="jQuery('#mec_gateways<?php echo $this->id(); ?>_container_toggle').toggle();" value="1" type="checkbox" name="mec[gateways][<?php echo $this->id(); ?>][status]" <?php
                                                                                                                                                                                                            if (isset($this->options['status']) and $this->options['status']) {
                                                                                                                                                                                                                echo 'checked="checked"';
                                                                                                                                                                                                            }
                                                                                                                                                                                                            ?> /> <?php _e('Pay by WooCommerce', 'mec'); ?>
            </label>
        </div>
        <div id="mec_gateways<?php echo $this->id(); ?>_container_toggle" class="mec-gateway-options-form
										<?php
                                                if ((isset($this->options['status']) and !$this->options['status']) or !isset($this->options['status'])) {
                                                    echo 'mec-util-hidden';
                                                }
                                                ?>
		">
            <?php if (!function_exists('wc_create_order')) : ?>
                <p class="mec-error"><?php _e('WooCommerce must be installed and activated first.', 'mec'); ?></p>
            <?php else : ?>
                <div class="mec-form-row">
                    <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_title"><?php _e('Title', 'mec'); ?></label>
                    <div class="mec-col-4">
                        <input type="text" id="mec_gateways<?php echo $this->id(); ?>_title" name="mec[gateways][<?php echo $this->id(); ?>][title]" value="<?php echo (isset($this->options['title']) and trim($this->options['title'])) ? $this->options['title'] : ''; ?>" placeholder="<?php echo $this->label(); ?>" />
                    </div>
                </div>
                <div class="mec-form-row">
                    <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_comment"><?php _e('Comment', 'mec'); ?></label>
                    <div class="mec-col-4">
                        <textarea id="mec_gateways<?php echo $this->id(); ?>_comment" name="mec[gateways][<?php echo $this->id(); ?>][comment]"><?php echo (isset($this->options['comment']) and trim($this->options['comment'])) ? stripslashes($this->options['comment']) : ''; ?></textarea>
                        <span class="mec-tooltip">
                            <div class="box top">
                                <h5 class="title"><?php _e('Comment', 'mec'); ?></h5>
                                <div class="content">
                                    <p><?php esc_attr_e('HTML allowed.', 'mec'); ?><a href="https://webnus.net/dox/modern-events-calendar/woocommerce/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                                </div>
                            </div>
                            <i title="" class="dashicons-before dashicons-editor-help"></i>
                        </span>
                    </div>
                </div>
                <div class="mec-form-row">
                    <label class="mec-col-3" for="mec_gateways<?php echo $this->id(); ?>_auto_order_complete"><?php _e('Automatically complete WC orders', 'mec'); ?></label>
                    <div class="mec-col-4">
                        <select id="mec_gateways<?php echo $this->id(); ?>_auto_order_complete" name="mec[gateways][<?php echo $this->id(); ?>][auto_order_complete]">
                            <option value="1" <?php echo ((isset($this->options['auto_order_complete']) and $this->options['auto_order_complete'] == '1') ? 'selected="selected"' : ''); ?>><?php _e('Enabled', 'mec'); ?></option>
                            <option value="0" <?php echo ((isset($this->options['auto_order_complete']) and $this->options['auto_order_complete'] == '0') ? 'selected="selected"' : ''); ?>><?php _e('Disabled', 'mec'); ?></option>
                        </select>
                        <span class="mec-tooltip">
                            <div class="box top">
                                <h5 class="title"><?php _e('Auto WC orders', 'mec'); ?></h5>
                                <div class="content">
                                    <p><?php esc_attr_e('It applies only to the orders that are related to MEC.', 'mec'); ?>
                                        <a href="https://webnus.net/dox/modern-events-calendar/woocommerce/" target="_blank"><?php _e('Read More', 'mec'); ?></a></p>
                                </div>
                            </div>
                            <i title="" class="dashicons-before dashicons-editor-help"></i>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php
    }

    public function check_transaction($transaction_id = null)
    {
        if (!trim($transaction_id)) {
            $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field($_GET['transaction_id']) : 0;
        }

        $transaction = $this->book->get_transaction($transaction_id);

        $success = 2;
        $message = __('Waiting for getting response from gateway.', 'mec');
        $data = array();

        if (isset($transaction['done']) and $transaction['done'] == 1) {
            $success = 1;
            $message = $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec'));

            if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) {
                $data['redirect_to'] = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);
            }
        } elseif (isset($transaction['done']) and $transaction['done'] == 0) {
            $success = 0;
            $message = __('Payment was invalid! Booking failed.', 'mec');
        }

        $this->response(
            array(
                'success' => $success,
                'message' => $message,
                'data' => $data,
            )
        );
    }

    public function after_payment($order_id)
    {
        if (!$order_id) {
            return;
        }

        // Auto Order Complete is not enabled
        if (!isset($this->options['auto_order_complete']) or (isset($this->options['auto_order_complete']) and !$this->options['auto_order_complete'])) {
            return;
        }

        $transaction_id = get_post_meta($order_id, '_mec_transaction_id', true);
        if (!$transaction_id) {
            return;
        }

        $order = wc_get_order($order_id);
        $order->update_status('completed');
    }

    public function after_order_completed($order_id)
    {
        $transaction_id = get_post_meta($order_id, '_mec_transaction_id', true);
        if (!$transaction_id) {
            return;
        }

        // Mark it as done
        $transaction = $this->book->get_transaction($transaction_id);
        $transaction['done'] = 1;

        $this->book->update_transaction($transaction_id, $transaction);

        // Do MEC Transaction
        $this->do_transaction($transaction_id, $order_id);
    }

    public function after_order_cancellation($order_id)
    {
        $transaction_id = get_post_meta($order_id, '_mec_transaction_id', true);
        if (!$transaction_id) {
            return;
        }

        // Mark bookings as Canceled
        $bookings = $this->book->get_bookings_by_transaction_id($transaction_id);
        foreach ($bookings as $booking) {
            $this->book->cancel($booking->ID);
            $this->book->reject($booking->ID);
        }
    }
}

class MEC_gateway_free extends MEC_gateway
{

    public $id = 4;
    public $options;

    public function __construct()
    {
        parent::__construct();

        // Gateway options
        $this->options = $this->options();
    }

    public function label()
    {
        return __('Free', 'mec');
    }

    public function color()
    {
        return '#4BC0C0';
    }

    public function do_transaction($transaction_id = null)
    {
        $transaction = $this->book->get_transaction($transaction_id);
        $attendees = isset($transaction['tickets']) ? $transaction['tickets'] : array();

        $price = isset($transaction['price']) ? $transaction['price'] : 0;

        // Booking is not free!
        if ($price) {
            return array(
                'success' => 0,
                'code' => 'NOT_FREE',
                'message' => __('This booking is not free!', 'mec'),
            );
        }

        $attention_date = isset($transaction['date']) ? $transaction['date'] : '';
        $ex = explode(':', $attention_date);
        $date = trim($ex[0]);

        // Is there any attendee?
        if (!count($attendees)) {
            return array(
                'success' => 0,
                'code' => 'NO_TICKET',
                'message' => __('There is no attendee for booking!', 'mec'),
            );
        }

        $main_attendee = isset($attendees[0]) ? $attendees[0] : array();
        $name = isset($main_attendee['name']) ? $main_attendee['name'] : '';

        $ticket_ids = '';
        $attendees_info = array();

        foreach ($attendees as $attendee) {
            $ticket_ids .= $attendee['id'] . ',';
            if (!array_key_exists($attendee['email'], $attendees_info)) $attendees_info[$attendee['email']] = array('count' => $attendee['count']);
            else $attendees_info[$attendee['email']]['count'] = ($attendees_info[$attendee['email']]['count'] + $attendee['count']);
        }

        $ticket_ids = ',' . trim($ticket_ids, ', ') . ',';
        $user_id = $this->register_user($main_attendee);

        $book_subject = $name . ' - ' . get_userdata($user_id)->user_email;
        $book_id = $this->book->add(
            array(
                'post_author' => $user_id,
                'post_type' => $this->PT,
                'post_title' => $book_subject,
                'post_date' => $date,
                'attendees_info' => $attendees_info,
                'mec_attendees' => $attendees
            ),
            $transaction_id,
            $ticket_ids
        );

        update_post_meta($book_id, 'mec_gateway', 'MEC_gateway_free');
        update_post_meta($book_id, 'mec_gateway_label', $this->label());

        // Fires after completely creating a new booking
        do_action('mec_booking_completed', $book_id);

        $redirect_to = '';
        if (isset($this->settings['booking_thankyou_page']) and trim($this->settings['booking_thankyou_page'])) {
            $redirect_to = $this->book->get_thankyou_page($this->settings['booking_thankyou_page'], $transaction_id);
        }

        // Invoice Link
        $mec_confirmed = get_post_meta($book_id, 'mec_confirmed', true);
        $invoice_link = (!$mec_confirmed) ? '' : $this->book->get_invoice_link($transaction_id);

        return array(
            'success' => 1,
            'message' => $this->main->m('book_success_message', __('Thanks for your booking. Your tickets booked, booking verification might be needed, please check your email.', 'mec')),
            'data' => array(
                'book_id' => $book_id,
                'redirect_to' => $redirect_to,
                'invoice_link' => $invoice_link,
            ),
        );
    }
}
