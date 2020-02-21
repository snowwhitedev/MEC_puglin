<?php

/** no direct access **/
defined('MECEXEC') or die();

$event_id = $event->ID;
$date_ex = explode(':', $date);

?>
<?php
$styling = $this->main->get_styling();
$color = "#40d9f1";
// if (isset($styling['color']) && $styling['color']) $color = $styling['color'];
// elseif (isset($styling['mec_colorskin'])) $color = $styling['mec_colorskin'];
// else $color = "#40d9f1";
?>

<style>
    .datepicker-days table {
        border: none;
    }

    .mec_customer_date_picker {
        font-size: inherit;
    }

    .active.day {
        background-color: <?php echo $color; ?> !important;
        border-color: <?php echo $color; ?> !important;
    }

    .datepicker {
        width: 100% !important;
    }

    td.day {
        position: relative;
    }

    .day {
        color: black;
    }

    .day:after {
        background-color: <?php echo $color; ?>;
        border-radius: 50%;
        display: block;
        content: '';
        width: 8px;
        height: 8px;
        left: 50%;
        margin: -4px 0 0 -4px;
        position: absolute;
        transition: all .25s ease
    }

    .disabled.day:after {
        background-color: white !important;
    }

    .mec-wrap .clear {
        visibility: visible;
    }
</style>
<form id="mec_book_form<?php echo $uniqueid; ?>">
    <h4><?php echo apply_filters('mec-attendees-title', __('Select Dates', 'mec')) ?></h4>
    <div>
        <ul class="mec-book-tickets-container">
            <div class="">
                <div id="datepicker"></div>
                <input type="hidden" id="mec_customer_date_picker" class="mec_customer_date_picker" name="book[customer_date]" readonly required>
            </div>

            <?php foreach ($tickets as $ticket_id => $count) :
                if (!$count) continue; ?>
                <input type="hidden" name="book[tickets][<?php echo $ticket_id; ?>]" value="<?php echo $count; ?>" />
            <?php endforeach; ?>

        </ul>
    </div>
    
    <input type="hidden" name="book[date]" value="<?php echo $date; ?>" />
    <input type="hidden" name="action" value="mec_book_form" />
    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>" />
    <input type="hidden" name="uniqueid" value="<?php echo $uniqueid; ?>" />
    <input type="hidden" name="step" id="step" value="12" />
    <input type="hidden" id="form_direction" name="form_direction" value="next" />
    <?php wp_nonce_field('mec_book_form_' . $event_id); ?>
    <button type="button" id="mec_book_form_prev"><?php _e('Prev', 'mec'); ?></button>
    <button type="submit"><?php _e('Next', 'mec'); ?></button>
</form>


<link href="<?php echo $this->main->asset('css/bootstrap.min.css'); ?>" rel="stylesheet">
<link href="<?php echo $this->main->asset('css/bootstrap-datepicker3.min.css'); ?>" rel="stylesheet">
<script src="<?php echo $this->main->asset('js/bootstrap-datepicker.min.js'); ?>"></script>

<script>
    jQuery(document).ready(function() {
        var start_date = "<?php echo $date_ex[0]; ?>";
        var end_date = "<?php echo $date_ex[1] ?>";
        var today = "<?php echo current_time('Y-m-d'); ?>";
        var pre_sel_dates = "<?php echo $customer_date;?>";
        
        if (today >= start_date)
            start_date = today;
        var enableDays = [];
       enabledDates = ['2019-12-28', '2019-12-27'];
 
        jQuery('#datepicker').datepicker({
            startDate: start_date,
            endDate: end_date,
            multidate: true,
            format: "yyyy-mm-dd",
            language: 'en',
            clearBtn: true,
        });
        if(pre_sel_dates.trim()){
            jQuery('#datepicker').datepicker("setDates", pre_sel_dates.split(","));
            jQuery('#mec_customer_date_picker').val(
                jQuery('#datepicker').datepicker('getFormattedDate')
            );
        }
        
        
        jQuery('#datepicker').on('changeDate', function() {
            jQuery('#mec_customer_date_picker').val(
                jQuery('#datepicker').datepicker('getFormattedDate')
            );
        });

        jQuery("#mec_book_form_prev").on("click", function(event) {
            event.preventDefault();
            jQuery("#form_direction").val("prev");
            <?php echo "mec_book_form_submit" . $uniqueid . "();"; ?>
        });
    });
</script>