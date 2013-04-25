<?php

/**
 * Description of BPMediaEncoding
 *
 * @author Joshua Abenazer <joshua.abenazer@rtcamp.com>
 */
class BPMediaEncoding {

    protected $api_url = 'http://api.rtcamp.com/';
    protected $sandbox_testing = 1;
    protected $merchant_id = 'SA8CC3GSCMB2U';

    public function __construct() {
        $this->api_key = bp_get_option('bp-media-encoding-api-key');
        if (is_admin()) {
            add_action(bp_core_admin_hook(), array($this, 'menu'));
            add_action('admin_init', array($this, 'encoding_settings'));
            add_filter('bp_media_add_sub_tabs', array($this, 'encoding_tab'), '', 2);
            if ($this->api_key)
                add_action('bp_media_before_default_admin_widgets', array($this, 'usage_widget'));
        }
        add_action('init', array($this, 'save_api_key'));
        add_filter('bp_media_add_admin_bar_item', array($this, 'admin_bar_menu'));
        if ($this->api_key) {
            $usage_info = bp_get_option('bp-media-encoding-usage');
            if ($usage_info) {
                if (isset($usage_info[$this->api_key]['status']) && $usage_info[$this->api_key]['status']) {
                    if (isset($usage_info[$this->api_key]['remaining']) && $usage_info[$this->api_key]['remaining'] > 0) {
                        if ($usage_info->remaining < 524288000 && !bp_get_option('bp-media-encoding-usage-limit-mail'))
                            $this->nearing_usage_limit($usage_info);
                        elseif ($usage_info->remaining > 524288000 && bp_get_option('bp-media-encoding-usage-limit-mail'))
                            bp_update_option('bp-media-encoding-usage-limit-mail', 0);
                        add_action('bp_init', array($this, 'handle_callback'), 20);
                        add_filter('bp_media_transcoder', array($this, 'enqueue'), 10, 2);
                        add_filter('bp_media_plupload_files_filter', array($this, 'allowed_types'));
                    }
                }
            }
        }
    }

    function enqueue($class, $type) {
        switch ($type) {
            case 'video':
            case 'audio':
                return 'BPMediaEncodingTranscoder';
            default:
                return $class;
        }
    }

    public function menu() {
        add_submenu_page('bp-media-settings', __('BuddyPress Media Audio/Video Encoding Service', 'buddypress-media'), __('Audio/Video Encoding', 'buddypress-media'), 'manage_options', 'bp-media-encoding', array($this, 'encoding_page'));
    }

    /**
     * Render the BuddyPress Media Encoding page
     */
    public function encoding_page() {
        global $bp_media_admin;
        $bp_media_admin->render_page('bp-media-encoding');
    }

    public function encoding_settings() {
        add_settings_section('bpm-encoding', __('Audio/Video Encoding Service', 'buddypress-media'), array($this, 'encoding_service_intro'), 'bp-media-encoding');
    }

    public function encoding_tab($tabs, $tab) {
        $idle_class = 'nav-tab';
        $active_class = 'nav-tab nav-tab-active';
        $encoding_tab = array(
            'href' => bp_get_admin_url(add_query_arg(array('page' => 'bp-media-encoding'), 'admin.php')),
            'title' => __('BuddyPress Media Audio/Video Encoding Service', 'buddypress-media'),
            'name' => __('Audio/Video Encoding', 'buddypress-media'),
            'class' => ($tab == 'bp-media-encoding') ? $active_class : $idle_class . ' last_tab'
        );
        $reordered_tabs = NULL;
        foreach ($tabs as $key => $tab) {
            if ($key == 1)
                $reordered_tabs[] = $encoding_tab;
            $reordered_tabs[] = $tab;
        }

        return $reordered_tabs;
    }

    public function admin_bar_menu($bp_media_admin_nav) {
        // Encoding Service
        $bp_media_admin_nav[] = array(
            'parent' => 'bp-media-menu',
            'id' => 'bp-media-encoding',
            'title' => __('Audio/Video Encoding', 'buddypress-media'),
            'href' => bp_get_admin_url(add_query_arg(array('page' => 'bp-media-encoding'), 'admin.php'))
        );
        return $bp_media_admin_nav;
    }

    public function is_valid_key($key) {
        $validate_url = trailingslashit($this->api_url) . 'api/validate/' . $key;
        $validation_page = wp_remote_get($validate_url, array('timeout' => 20));
        if (!is_wp_error($validation_page)) {
            $validation_info = json_decode($validation_page['body']);
            $status = $validation_info->status;
        } else {
            $status = false;
        }
        return $status;
    }

    public function update_usage($key) {
        $usage_url = trailingslashit($this->api_url) . 'api/usage/' . $key;
        $usage_page = wp_remote_get($usage_url, array('timeout' => 20));
        if (!is_wp_error($usage_page))
            $usage_info = json_decode($usage_page['body']);
        else
            $usage_info = NULL;
        bp_update_option('bp-media-encoding-usage', array($key => $usage_info));
        return $usage_info;
    }

    public function nearing_usage_limit($usage_details) {
        $subject = __('BuddyPress Media Encoding: Nearing quota limit.', 'buddypress-media');
        $message = __('Your are nearing the quota limit for your BuddyPress Media encoding service.
            Following are the details: 
        Used: %s
        Remaining: %s
        Total: %s', 'buddypress-media');
        $users = get_users(array('role' => 'administrator'));
        if ($users) {
            foreach ($users AS $user)
                ;
            $admin_email_ids[] = $user->user_email;
            wp_mail($admin_email_ids, $subject, sprintf($message, size_format($usage_details->used, 2), size_format($usage_details->remaining, 2), size_format($usage_details->total, 2)));
        }
        bp_update_option('bp-media-encoding-usage-limit-mail', 1);
    }

    public function save_api_key() {
        if (isset($_GET['apikey']) && is_admin() && isset($_GET['page']) && ($_GET['page'] == 'bp-media-encoding') && $this->is_valid_key($_GET['apikey'])) {
            bp_update_option('bp-media-encoding-api-key', $_GET['apikey']);
            $this->update_usage($_GET['apikey']);
            $return_page = add_query_arg(array('page' => 'bp-media-encoding'), (is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php')));
            wp_safe_redirect($return_page);
        }
    }

    public function allowed_types($types) {
        $types = array(); //Allow all types of file to be uploded
        return $types;
    }

    public function encoding_subscription_form($name = 'No Name', $price = '0') {
        $action = $this->sandbox_testing ? 'https://sandbox.paypal.com/cgi-bin/webscr' : 'https://paypal.com/cgi-bin/webscr';
        $return_page = add_query_arg(array('page' => 'bp-media-encoding'), (is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php')));
        $form = '<form method="post" action="' . $action . '" class="paypal-button" target="_top">
                        <input type="hidden" name="button" value="subscribe">
                        <input type="hidden" name="item_name" value="' . $name . '">

                        <input type="hidden" name="currency_code" value="USD">


                        <input type="hidden" name="a3" value="' . $price . '">
                        <input type="hidden" name="p3" value="1">
                        <input type="hidden" name="t3" value="M">

                        <input type="hidden" name="cmd" value="_xclick-subscriptions">

                        <!-- Merchant ID -->
                        <input type="hidden" name="business" value="' . $this->merchant_id . '">


                        <input type="hidden" name="custom" value="' . $return_page . '">

                        <!-- Flag to no shipping -->
                        <input type="hidden" name="no_shipping" value="1">

                        <!-- Flag to post payment return url -->
                        <input type="hidden" name="return" value="' . trailingslashit($this->api_url) . 'payment/process">


                        <!-- Flag to post payment data to given return url -->
                        <input type="hidden" name="rm" value="2">

                        <input type="hidden" name="src" value="1">
                        <input type="hidden" name="sra" value="1">

                        <button type="submit" class="button paypal-button large">' . __('Subscribe', 'buddypress-media') . '</button>
                    </form>';
        return $form;
    }

    public function usage_widget() {
        $usage_details = bp_get_option('bp-media-encoding-usage');
        $content = '';
        if ($usage_details && isset($usage_details[$this->api_key]['status']) && $usage_details[$this->api_key]['status']) {
            if (isset($usage_details[$this->api_key]['used']))
                $content .= '<p><span class="encoding-used"></span>' . __('Used', 'buddypress-media') . ': ' . (($used_size = size_format($usage_details[$this->api_key]['used'], 2)) ? $used_size : '0MB') . '</p>';
            if (isset($usage_details[$this->api_key]['remaining']))
                $content .= '<p><span class="encoding-remaining"></span>' . __('Remaining', 'buddypress-media') . ': ' . (($remaining_size = size_format($usage_details[$this->api_key]['remaining'], 2)) ? $remaining_size : '0MB') . '</p>';
            if (isset($usage_details[$this->api_key]['total']))
                $content .= '<p>' . __('Total', 'buddypress-media') . ': ' . size_format($usage_details[$this->api_key]['total'], 2) . '</p>';
            $usage = new rtProgress();
            $content .= $usage->progress_ui($usage->progress($usage_details[$this->api_key]['used'], $usage_details[$this->api_key]['total']), false);
            if ($usage_details[$this->api_key]['remaining'] <= 0)
                $content .= '<div class="error below-h2"><p>' . __('Your usage limit has been reached. Upgrade your plan.', 'buddypress-media') . '</p></div>';
        } else {
            $content .= '<div class="error below-h2"><p>' . __('Your API key is not valid or is expired.', 'buddypress-media') . '</p></div>';
        }
        new BPMediaAdminWidget('bp-media-encoding-usage', __('Encoding Usage', 'buddypress-media'), $content);
    }

    public function encoding_service_intro() {
//        $api_key = bp_get_option('bp-media-encoding-api-key');
//        if ( !$api_key )
//            echo '<div class="updated" id="bp-media-no-api-key"><p>'.__('You would need an API key to use this service.','buddypress-media').'</p></div>';
//        echo '<table class="form-table">
//                <tbody>
//                    <tr valign="top">
//                        <th scope="row">'.__('API Key','buddypress-media').'</th>
//                        <td><label for="bp-media-encoding-api-key"><input value="'.$api_key.'" name="bp-media-encoding-api-key" id="bp-media-encoding-api-key" type="text"></label></td>
//                    </tr>
//                </tbody>
//            </table>';
        ?>
        <p><?php _e('BuddyPress Media team has started offering an audio/video encoding service.', 'buddypress-media'); ?></p>
        <table  class="widefat fixed" cellspacing="0">
            <tbody>
                <!-- Results table headers -->
            <thead>
                <tr>
                    <th><?php _e('Feature\Plan', 'buddypress-media'); ?></th>
                    <th><?php _e('Free', 'buddypress-media'); ?></th>
                    <th><?php _e('Silver', 'buddypress-media'); ?></th>
                    <th><?php _e('Gold', 'buddypress-media'); ?></th>
                    <th><?php _e('Platinum', 'buddypress-media'); ?></th>
                </tr>
            </thead>
            <tr>
                <th><?php _e('File Size Limit', 'buddypress-media'); ?></th>
                <td>20MB</td>
                <td>2GB</td>
                <td>2GB</td>
                <td>2GB</td>
            </tr>
            <tr>
                <th><?php _e('Bandwidth (monthly)', 'buddypress-media'); ?></th>
                <td>10GB</td>
                <td>100GB</td>
                <td>1TB</td>
                <td>10TB</td>
            </tr>
            <tr>
                <th><?php _e('Overage Bandwidth', 'buddypress-media'); ?></th>
                <td><?php echo '<img src="' . admin_url('/images/no.png') . '" />'; ?></td>
                <td>$0.10 per GB</td>
                <td>$0.08 per GB</td>
                <td>$0.05 per GB</td>
            </tr>
            <tr>
                <th><?php _e('Amazon S3 Support', 'buddypress-media'); ?></th>
                <td><?php echo '<img src="' . admin_url('/images/no.png') . '" />'; ?></td>
                <td colspan="3" class="column-posts"><?php _e('Coming Soon', 'buddypress-media'); ?></td>
            </tr>
            <tr>
                <th><?php _e('HD Profile', 'buddypress-media'); ?></th>
                <td><?php echo '<img src="' . admin_url('/images/no.png') . '" />'; ?></td>
                <td colspan="3" class="column-posts"><?php _e('Coming Soon', 'buddypress-media'); ?></td>
            </tr>
            <tr>
                <th><?php _e('Pricing', 'buddypress-media'); ?></th>
                <td><?php _e('Free', 'buddypress-media'); ?></td>
                <td><?php _e('$9/month', 'buddypress-media'); ?></td>
                <td><?php _e('$99/month', 'buddypress-media'); ?></td>
                <td><?php _e('$999/month', 'buddypress-media'); ?></td>
            </tr>
            <tr>
                <th></th>
                <td>
                    <form method="post" action="<?php echo trailingslashit($this->api_url) . ''; ?>">
                        <button type="submit" class="button"><?php _e('Try Now', 'buddypress-media'); ?></button>
                    </form>
                </td>
                <td><?php echo $this->encoding_subscription_form('silver', 9.0) ?></td>
                <td><?php echo $this->encoding_subscription_form('gold', 99.0) ?></td>
                <td><?php echo $this->encoding_subscription_form('platinum', 999.0) ?></td>
            </tr>
        </tbody>
        </table><br /><?php
    }

    /**
     * Function to handle the callback request by the FFMPEG encoding server
     *
     * @since 1.0
     */
    public function handle_callback() {
        if (isset($_GET['job_id']) && isset($_GET['download_url'])) {
            global $wpdb;
            $query_string =
                    "SELECT $wpdb->postmeta.post_id
					FROM $wpdb->postmeta
					WHERE $wpdb->postmeta.meta_key = 'bp-media-encoding-job-id'
						AND $wpdb->postmeta.meta_value='" . $_GET['job_id'] . "' ";
            $result = $wpdb->get_results($query_string);
            if (is_array($result) && count($result) == 1) {
                $attachment_id = $result[0]->post_id;

                $new_wp_attached_file_pathinfo = pathinfo($_GET['download_url']);
                $file_bits = file_get_contents($_GET['download_url']);
                if ($file_bits) {
                    unlink(get_attached_file($attachment_id));
                    $upload_info = wp_upload_bits($new_wp_attached_file_pathinfo['basename'], null, $file_bits);
                    $this->update($wpdb->posts, array('guid' => $upload_info['url']), array('ID' => $attachment_id));
                    $old_wp_attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
                    $old_wp_attached_file_pathinfo = pathinfo($old_wp_attached_file);
                    update_post_meta($attachment_id, '_wp_attached_file', str_replace($old_wp_attached_file_pathinfo['basename'], $new_wp_attached_file_pathinfo['basename'], $old_wp_attached_file));
                } else {
                    error_log('Could not read file.', 'buddypress-media');
                }
            } else {
                error_log('Something went wrong.', 'buddypress-media');
            }

            $this->update_usage($this->api_key);

            die();
        }
    }

}
?>