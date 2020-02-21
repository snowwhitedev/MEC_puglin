<?php

/** no direct access **/
defined('MECEXEC') or die();

$event_id = $event->ID;
$gateways = $this->main->get_gateways();

$active_gateways = array();
foreach ($gateways as $gateway) {
    if (!$gateway->enabled()) continue;
    $active_gateways[] = $gateway;

    // When Stripe Connect is enabled and organizer is connected then skip other gateways
    if ($gateway->id() == 7 and get_user_meta(get_post_field('post_author', $event_id), 'mec_stripe_id', true)) // Stripe Connect
    {
        $active_gateways = array($gateway);
        break;
    }
}

?>
<div id="mec_book_payment_form">
    <form>
        <h4><?php _e('Checkout', 'mec'); ?></h4>
    </form>
    <div class="mec-book-form-price">
        <!--<?php if (isset($price_details['details']) and is_array($price_details['details']) and count($price_details['details'])) : ?>
            <ul class="mec-book-price-details">
                <?php foreach ($price_details['details'] as $detail) : ?>
                    <li class="mec-book-price-detail mec-book-price-detail-type<?php echo $detail['type']; ?>">
                        <span class="mec-book-price-detail-description"><?php echo $detail['description']; ?></span>
                        <span class="mec-book-price-detail-amount"><?php echo $this->main->render_price($detail['amount']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>-->
        <?php 
            $customer_date_ex = explode(",", $customer_date);
            $total_days = count($customer_date_ex);
        ?>
        <form method="POST" action="<?php echo get_site_url();?>/tcpdf/run.php" id="invoice-form" target="_blank">
            <?php if (isset($price_details['tickets'])) :
                $inv_html = "";
                $inv_html .= '<table style="border:1px solid #dcd7ca; border-collapse: collapse; text-align:right; margin:0 auto;"><thead><tr>';
                $inv_html .= '<th style="border:1px solid #dcd7ca;">Tickets</th>' . '<th style="border:1px solid #dcd7ca;">Quantity</th>' . '<th style="border:1px solid #dcd7ca;">Days</th>' . '<th style="border:1px solid #dcd7ca;">Price</th>' . '<th style="border:1px solid #dcd7ca;">Total</th>';
                $inv_html .= '</tr ><thead><tbody>';
                $tickets_table = array();
                foreach ($price_details['tickets'] as $ticket){
                   
                    if(array_key_exists($ticket["ticket_id"], $tickets_table)){
                        $tickets_table[$ticket["ticket_id"]]["quantity"]++;
                        $tickets_table[$ticket["ticket_id"]]["total"] = round($tickets_table[$ticket["ticket_id"]]["quantity"] * $total_days * floatval($t_price), 2);
                    } else {
                        $t_name = isset($ticket['ticket_name']) ? $ticket['ticket_name'] : __("Unknown", "mec");
                        $t_price = isset($ticket['booked_price']) ? $ticket['booked_price'] : __("Free", "mec");
                        $tickets_table[$ticket["ticket_id"]] =  array(
                            "ticket_name" => $t_name,
                            "quantity" => 1,
                            "days"    => $total_days,
                            "price"   => $t_price,
                            "total"   => round($total_days * floatval($t_price), 2),
                        );

                   }
                }
                //making invoice table...
                $total_booking_price = 0;
                foreach($tickets_table as $index => $item){
                    $inv_html .= '<tr><td style="border:1px solid #dcd7ca;">';
                    $inv_html .= $item['ticket_name'];
                    $inv_html .= '</td><td style="border:1px solid #dcd7ca;">';
                    $inv_html .= $item['quantity'];
                    $inv_html .= '</td><td style="border:1px solid #dcd7ca;">';
                    $inv_html .= $item['days'];
                    $inv_html .= '</td><td style="border:1px solid #dcd7ca;">';
                    $inv_html .= $this->main->render_price($item['price']);
                    $inv_html .= '</td><td style="border:1px solid #dcd7ca;">';
                    $inv_html .= $this->main->render_price($item['total']);
                    $inv_html .= '</td>';
                    $inv_html .= '</tr>';
                    $total_booking_price += $item['total'];
                }
                $inv_html .= '<tr><td style="border:1px solid #dcd7ca;">&nbsp&nbsp</td><td style="border:1px solid #dcd7ca;">&nbsp&nbsp</td><td>&nbsp&nbsp</td><td style="border:1px solid #dcd7ca; color:#39c36e"><strong>Total</strong></td><td style="border:1px solid #dcd7ca;color:#39c36e;"><strong>';
                $inv_html .= $this->main->render_price($total_booking_price);
                $inv_html .= '</strong></td></tr>';
                $inv_html .= '</tbody></table>';
                echo $inv_html;

                //echo '<dv>'
                $payment_html = '<div><h5 style="margin-top:7px;"><strong>' . __("Payment", "mec") .'</strong></h5>';
                $payment_html .= '<div class="mec-row"><span><strong>'.__("Price: ", "mec").'</strong></span><span>'. $this->main->render_price($price_details['total']).'</span></div>';
                $payment_html .= '<div class="mec-row"><span><strong>'.__("Customer Dates: ", "mec").'</strong></span><span>'. $customer_date.'</span></div>';
                $payment_html .= '<div class="mec-row"><span><strong>'.__("Transaction ID: ", "mec").'</strong></span><span>'. $transaction_id .'</span></div>';
                $payment_html .= '</div>';
                $inv_html = $payment_html.$inv_html;
                echo "<input type='hidden' name='mec_invoice_pdf' value='".$inv_html."'/>";
            endif ?>
        </form>
    </div>
    <?php if (isset($this->settings['coupons_status']) and $this->settings['coupons_status']) : ?>
        <div class="mec-book-form-coupon">
            <form id="mec_book_form_coupon<?php echo $uniqueid; ?>" onsubmit="mec_book_apply_coupon<?php echo $uniqueid; ?>(); return false;">
                <input type="text" name="coupon" placeholder="<?php esc_attr_e('Discount Coupon', 'mec'); ?>" />
                <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
                <input type="hidden" name="action" value="mec_apply_coupon" />
                <?php wp_nonce_field('mec_apply_coupon_' . $transaction_id); ?>
                <button type="submit"><?php _e('Apply Coupon', 'mec'); ?></button>
            </form>
            <div class="mec-coupon-message mec-util-hidden"></div>
        </div>
    <?php endif; ?>
    <div class="mec-book-form-gateways">
        <?php foreach ($active_gateways as $gateway) : ?>
            <div class="mec-book-form-gateway-label">
                <label>
                    <?php if (count($active_gateways) > 1) : ?>
                        <input type="radio" name="book[gateway]" onchange="mec_gateway_selected(this.value);" value="<?php echo $gateway->id(); ?>" />
                    <?php endif; ?>
                    <?php echo $gateway->title(); ?>
                </label>
            </div>
        <?php endforeach; ?>

        <?php foreach ($active_gateways as $gateway) : ?>
            <div class="mec-book-form-gateway-checkout <?php echo (count($active_gateways) == 1 ? '' : 'mec-util-hidden'); ?>" id="mec_book_form_gateway_checkout<?php echo $gateway->id(); ?>">
                <?php echo $gateway->comment(); ?>
                <?php $gateway->checkout_form($transaction_id); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <form id="mec_book_form_free_booking<?php echo $uniqueid; ?>" class="mec-util-hidden" onsubmit="mec_book_free<?php echo $uniqueid; ?>(); return false;">
        <div class="mec-form-row">
            <input type="hidden" name="action" value="mec_do_transaction_free" />
            <input type="hidden" name="transaction_id" value="<?php echo $transaction_id; ?>" />
            <input type="hidden" name="gateway_id" value="4" />
            <input type="hidden" name="uniqueid" value="<?php echo $uniqueid; ?>" />
            <?php wp_nonce_field('mec_transaction_form_' . $transaction_id); ?>
            <button type="submit"><?php _e('Free Booking', 'mec'); ?></button>
        </div>
    </form>


    <form id="mec_book_form<?php echo $uniqueid; ?>">
        <?php foreach($prev_tickets as $ticket_id => $count):?>
            <input type="hidden" name="book[tickets][<?php echo $ticket_id;?>]" value="<?php echo $count;?>">
        <?php endforeach?>
        <input type="hidden" name="book[date]" value="<?php echo $date;?>"/>
        <input type="hidden" name="book[customer_date]" value="<?php echo $customer_date;?>"/>
        <input type="hidden" name="action" value="mec_book_form" />
        <input type="hidden" name="event_id" value="<?php echo $event_id; ?>" />
        <input type="hidden" name="form_direction" id="form_direction" />
        <input type="hidden" name="step" id="step" value="5" />
        <input type="hidden" name="shabbat" value="<?php echo $shabbat;?>">
        <?php wp_nonce_field('mec_book_form_' . $event_id); ?>
    </form>
</div>
<input type="hidden" id="shabbat_date_selectable" value="0"/>
<script>
    jQuery(document).ready(function($) {
        jQuery("#mec_book_form_prev").on("click", function(event) {
            event.preventDefault();
            jQuery("#form_direction").val("prev");
            <?php echo "mec_book_form_submit" . $uniqueid . "();"; ?>
        });
    });
</script>