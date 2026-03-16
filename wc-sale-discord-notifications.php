<?php
/**
 * Plugin Name: WC Sale Discord Notifications
 * Plugin URI: https://github.com/Cral-Cactus/wc-sale-discord-notifications
 * Description: Sends a notification to a Discord channel when a sale is made or order status is changed on WooCommerce.
 * Version: 3.1
 * Author: Cral_Cactus
 * Author URI: https://github.com/Cral-Cactus
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Tested up to: 6.9.4
 * WC requires at least: 8.5
 * WC tested up to: 10.6.1
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('before_woocommerce_init', function(){
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

class Sale_Discord_Notifications_Woo {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_color_picker'));
        add_action('woocommerce_thankyou', array($this, 'send_discord_notification'));
        add_action('woocommerce_order_status_changed', array($this, 'send_discord_notification_on_status_change'), 10, 4);
        add_action('woocommerce_order_status_pending', array($this, 'send_discord_notification_on_pending'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array($this, 'send_discord_notification_on_checkout_processed'), 10, 3);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_action_links'));
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Discord Notifications', 'wc-sale-discord-notifications'),
            __('Discord Notifications', 'wc-sale-discord-notifications'),
            'manage_options',
            'wc-sale-discord-notifications',
            array($this, 'notification_settings_page')
        );
    }

    public function register_settings() {
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_webhook_url', [
            'sanitize_callback' => function ($value) {
                return is_string($value) ? esc_url_raw(trim($value)) : '';
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_order_statuses', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                return array_map('sanitize_text_field', $value);
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_status_webhooks', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                $sanitized = [];
                foreach ($value as $k => $v) {
                    $sanitized[sanitize_text_field($k)] = esc_url_raw(trim((string) $v));
                }
                return $sanitized;
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_status_colors', [
            'sanitize_callback' => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                $sanitized = [];
                foreach ($value as $k => $v) {
                    $sanitized[sanitize_text_field($k)] = sanitize_hex_color($v) ?: '#ffffff';
                }
                return $sanitized;
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_disable_image', [
            'sanitize_callback' => function ($value) {
                return !empty($value) ? 1 : 0;
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_include_order_notes', [
            'sanitize_callback' => function ($value) {
                return !empty($value) ? 1 : 0;
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_info_fields', [
            'sanitize_callback' => function ($value) {
                $allowed = array('status', 'payment', 'product', 'product_meta', 'creation_date', 'billing', 'transaction_id', 'order_notes');
                $value = is_array($value) ? $value : array();
                return array_values(array_intersect($value, $allowed));
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_force_blocking', [
            'sanitize_callback' => function ($value) {
                return !empty($value) ? 1 : 0;
            },
        ]);
        register_setting('wc_sale_discord_notifications', 'wc_sale_discord_notify_initiating_payment', [
            'sanitize_callback' => function ($value) {
                return !empty($value) ? 1 : 0;
            },
        ]);

        add_settings_section(
            'wc_sale_discord_notifications_section',
            __('Discord Webhook Settings', 'wc-sale-discord-notifications'),
            null,
            'wc_sale_discord_notifications'
        );

        add_settings_field(
            'wc_sale_discord_webhook_url',
            __('Discord Webhook URL', 'wc-sale-discord-notifications'),
            array($this, 'discord_webhook_url_callback'),
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );

        add_settings_field(
            'wc_sale_discord_order_statuses',
            __('Order Status Notifications', 'wc-sale-discord-notifications'),
            array($this, 'discord_order_statuses_callback'),
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );

        add_settings_field(
            'wc_sale_discord_disable_image',
            __('Disable Product Image in Embed', 'wc-sale-discord-notifications'),
            function() {
                $disable_image = get_option('wc_sale_discord_disable_image');
                echo '<input type="checkbox" name="wc_sale_discord_disable_image" value="1"' . checked(1, $disable_image, false) . '/>';
            },
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );

        add_settings_field(
            'wc_sale_discord_notify_initiating_payment',
            __('Send notification for Initiating payments', 'wc-sale-discord-notifications'),
            function() {
                $enabled = (int) get_option('wc_sale_discord_notify_initiating_payment', 0);
                echo '<input type="checkbox" name="wc_sale_discord_notify_initiating_payment" value="1"' . checked(1, $enabled, false) . '/>';
                echo '<p class="description">' . esc_html__('Notify when a customer starts checkout (pending) before payment is completed. Uses a separate embed title from completed orders.', 'wc-sale-discord-notifications') . '</p>';
            },
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );

        add_settings_field(
            'wc_sale_discord_info_fields',
            __('Embed Fields', 'wc-sale-discord-notifications'),
            array($this, 'info_fields_callback'),
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );

        add_settings_field(
            'wc_sale_discord_force_blocking',
            __('Debug: Force blocking HTTP requests', 'wc-sale-discord-notifications'),
            function () {
                $force = (int) get_option('wc_sale_discord_force_blocking', 0);
                echo '<input type="hidden" name="wc_sale_discord_force_blocking" value="0" />';
                echo '<label><input type="checkbox" name="wc_sale_discord_force_blocking" value="1" ' . checked(1, $force, false) . '> ' . esc_html__('Enable for troubleshooting. May slow down checkout.', 'wc-sale-discord-notifications') . '</label>';
            },
            'wc_sale_discord_notifications',
            'wc_sale_discord_notifications_section'
        );
    }

    public function info_fields_callback() {
        $info_fields = get_option('wc_sale_discord_info_fields', array());
        $info_fields = maybe_unserialize($info_fields);
        if (!is_array($info_fields)) {
            $info_fields = array();
        }
        $available = array(
            'status'        => __('Status', 'wc-sale-discord-notifications'),
            'payment'       => __('Payment', 'wc-sale-discord-notifications'),
            'product'       => __('Product', 'wc-sale-discord-notifications'),
            'product_meta'  => __('Product Meta', 'wc-sale-discord-notifications'),
            'creation_date' => __('Creation Date', 'wc-sale-discord-notifications'),
            'billing'       => __('Billing Information', 'wc-sale-discord-notifications'),
            'transaction_id' => __('Transaction ID', 'wc-sale-discord-notifications'),
            'order_notes'   => __('Order Notes', 'wc-sale-discord-notifications'),
        );
        echo '<p class="description">' . esc_html__('Choose which fields to include in the Discord embed. Leave empty to include all.', 'wc-sale-discord-notifications') . '</p>';
        foreach ($available as $key => $label) {
            $checked = in_array($key, $info_fields) ? 'checked' : '';
            echo '<p style="margin: 5px 0;">';
            echo '<label><input type="checkbox" name="wc_sale_discord_info_fields[]" value="' . esc_attr($key) . '" ' . esc_attr($checked) . '> ' . esc_html($label) . '</label>';
            echo '</p>';
        }
    }

    public function discord_webhook_url_callback() {
        $webhook_url = get_option('wc_sale_discord_webhook_url');
        echo '<input type="text" name="wc_sale_discord_webhook_url" value="' . esc_attr($webhook_url) . '" size="50" />';
    }

    public function discord_order_statuses_callback() {
        if (!function_exists('wc_get_order_statuses')) {
            echo '<p class="description">' . esc_html__('WooCommerce must be active to display order statuses.', 'wc-sale-discord-notifications') . '</p>';
            return;
        }
        $order_statuses = wc_get_order_statuses();
        if (empty($order_statuses)) {
            echo '<p class="description">' . esc_html__('No order statuses available.', 'wc-sale-discord-notifications') . '</p>';
            return;
        }
        $selected_statuses = get_option('wc_sale_discord_order_statuses', []);
        $selected_statuses = maybe_unserialize($selected_statuses);
        if (!is_array($selected_statuses)) {
            $selected_statuses = [];
        }
        $status_webhooks = get_option('wc_sale_discord_status_webhooks', []);
        $status_webhooks = maybe_unserialize($status_webhooks);
        $status_colors = get_option('wc_sale_discord_status_colors', []);
        $status_colors = maybe_unserialize($status_colors);
        if (!is_array($status_webhooks)) {
            $status_webhooks = [];
        }
        if (!is_array($status_colors)) {
            $status_colors = [];
        }

        $default_colors = array(
            'wc-pending' => '#ffdc00',
            'wc-processing' => '#00e5ed',
            'wc-on-hold' => '#FFA500',
            'wc-completed' => '#00d660',
            'wc-cancelled' => '#d60000',
            'wc-refunded' => '#6800e0',
            'wc-failed' => '#111111'
        );

        foreach ($order_statuses as $status => $label) {
            $checked = in_array($status, $selected_statuses) ? 'checked' : '';
            $webhook = isset($status_webhooks[$status]) ? esc_attr($status_webhooks[$status]) : '';
            $color = isset($status_colors[$status]) ? esc_attr($status_colors[$status]) : (isset($default_colors[$status]) ? $default_colors[$status] : '#ffffff');

            echo '<p style="margin-bottom: 10px;">';
            echo '<label style="margin-right: 10px;">';
            echo '<input type="checkbox" name="wc_sale_discord_order_statuses[]" value="' . esc_attr($status) . '" ' . esc_attr($checked) . '>';
            echo ' ' . esc_html($label);
            echo '</label>';
            echo '<input type="text" class="webhook-input" style="margin-right: 10px" name="wc_sale_discord_status_webhooks[' . esc_attr($status) . ']" value="' . esc_attr($webhook) . '" placeholder="Webhook URL (optional)" size="50">';
            echo '<input type="text" name="wc_sale_discord_status_colors[' . esc_attr($status) . ']" value="' . esc_attr($color) . '" class="discord-embed-color-picker" />';
            echo '</p>';
        }
    }

    public function enqueue_color_picker($hook_suffix) {
        if (strpos($hook_suffix, 'wc-sale-discord-notifications') === false) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script(
            'wc_sale-color-picker-script',
            plugins_url('color-picker.js', __FILE__),
            array('wp-color-picker'),
            '1.0.0',
            true
        );
    }

    public function notification_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Discord Sale Notifications', 'wc-sale-discord-notifications'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_sale_discord_notifications');
                do_settings_sections('wc_sale_discord_notifications');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function send_discord_notification($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        // Only fire for pending when customer lands on thank you page (manual/slow payments)
        if ($order->get_status() === 'pending') {
            if (get_option('wc_sale_discord_notify_initiating_payment')) {
                $this->send_discord_notification_common($order_id, 'initiating');
            } else {
                $this->send_discord_notification_common($order_id, 'new');
            }
        }
    }

    public function send_discord_notification_on_status_change($order_id, $old_status, $new_status, $order) {
        $order_obj = is_object($order) ? $order : wc_get_order($order_id);

        // Pending + initiating option enabled: send 'initiating' (bypass selected_statuses)
        if ($new_status === 'pending' && get_option('wc_sale_discord_notify_initiating_payment')) {
            $this->maybe_send_initiating_notification($order_id, $order_obj);
            return;
        }

        $selected = get_option('wc_sale_discord_order_statuses', array());
        $selected = is_array($selected) ? $selected : (array) maybe_unserialize($selected);

        $target = 'wc-' . $new_status;
        if (!in_array($target, $selected, true)) {
            return;
        }

        // If we recently sent a 'new' for this target status, skip the immediate 'update'
        $recent_new_sent = $order_obj ? $order_obj->get_meta('_discord_sent_' . $target . '_new') : '';
        if ($recent_new_sent) {
            $sent_ts = strtotime($recent_new_sent);
            if ($sent_ts && (current_time('timestamp') - $sent_ts) < 120) {
                return;
            }
        }

        // First notification for a completed status becomes 'new'. Exclude _initiating so pending→processing sends "New Order".
        $any_sent = false;
        if ($order_obj) {
            $meta_data = $order_obj->get_meta_data();
            foreach ($meta_data as $meta) {
                $key = is_object($meta) && method_exists($meta, 'get_key') ? $meta->get_key() : (isset($meta->key) ? $meta->key : '');
                if (strpos($key, '_discord_sent_') === 0 && strpos($key, '_initiating') === false) {
                    $any_sent = true;
                    break;
                }
            }
        }

        $type = $any_sent ? 'update' : 'new';
        $this->send_discord_notification_common($order_id, $type);
    }

    /**
     * Handle orders created directly with pending status (admin, API) when woocommerce_order_status_changed may not fire.
     */
    public function send_discord_notification_on_pending($order_id, $order = null) {
        $this->maybe_send_initiating_notification($order_id, $order);
    }

    /**
     * Backup trigger: fires when checkout order is processed. Catches pending orders when status hooks
     * may not fire (e.g. block checkout, some payment gateways).
     */
    public function send_discord_notification_on_checkout_processed($order_id, $posted_data, $order) {
        $this->maybe_send_initiating_notification($order_id, $order);
    }

    /**
     * Send initiating payment notification if conditions are met. Deduplicated via 120s meta check.
     */
    private function maybe_send_initiating_notification($order_id, $order = null) {
        if (!get_option('wc_sale_discord_notify_initiating_payment')) {
            return;
        }
        $order_obj = is_object($order) ? $order : wc_get_order($order_id);
        if (!$order_obj || $order_obj->get_status() !== 'pending') {
            return;
        }
        $recent_initiating_sent = $order_obj->get_meta('_discord_sent_wc-pending_initiating');
        if ($recent_initiating_sent) {
            $sent_ts = strtotime($recent_initiating_sent);
            if ($sent_ts && (current_time('timestamp') - $sent_ts) < 120) {
                return;
            }
        }
        $this->send_discord_notification_common($order_id, 'initiating');
    }

    private function send_discord_notification_common($order_id, $type) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $order_status_key = 'wc-' . $order->get_status();
        $status_webhooks = maybe_unserialize(get_option('wc_sale_discord_status_webhooks', []));
        $status_colors = maybe_unserialize(get_option('wc_sale_discord_status_colors', []));
        if (!is_array($status_webhooks)) {
            $status_webhooks = [];
        }
        if (!is_array($status_colors)) {
            $status_colors = [];
        }

        $is_initiating = ($type === 'initiating');

        if (!$is_initiating) {
            $selected_statuses = get_option('wc_sale_discord_order_statuses', []);
            $selected_statuses = maybe_unserialize($selected_statuses);
            if (!is_array($selected_statuses)) {
                $selected_statuses = [];
            }
            if (!in_array($order_status_key, $selected_statuses)) {
                return;
            }
        }

        $webhook_url = !empty($status_webhooks[$order_status_key]) ? $status_webhooks[$order_status_key] : get_option('wc_sale_discord_webhook_url');
        $embed_color = !empty($status_colors[$order_status_key]) ? hexdec(substr($status_colors[$order_status_key], 1)) : hexdec(substr('#ffdc00', 1));

        if (!$webhook_url) {
            return;
        }

        $order_data = $order->get_data();
        $order_id = $order_data['id'];
        $order_status = ucwords(wc_get_order_status_name($order->get_status()));
        $order_total = (string) round((float) $order_data['total'], 0);
        $order_currency = $order_data['currency'];
        $order_date = $order_data['date_created'];
        $order_timestamp = $order_date->getTimestamp();
        $payment_method = $order_data['payment_method_title'];
        $transaction_id = $order_data['transaction_id'];
        $billing_first_name = $order_data['billing']['first_name'];
        $billing_last_name = $order_data['billing']['last_name'];
        $billing_email = $order_data['billing']['email'];
        
        $billing_discord = $order->get_meta('_billing_discord');

        $order_items = $order->get_items();
        $items_list = '';
        $first_product_image = '';

        foreach ($order_items as $item) {
            $product = $item->get_product();
            if ($first_product_image == '' && $product) {
                $first_product_image = wp_get_attachment_url($product->get_image_id());
            }

            $product_name = $item->get_name();
            $product_quantity = $item->get_quantity();
            $product_total = (string) round((float) $item->get_total(), 0);
            $items_list .= "{$product_quantity}x {$product_name} - {$product_total} {$order_currency}\n";
        }
        $items_list = rtrim($items_list, "\n");

        $order_edit_url = admin_url('post.php?post=' . $order_id . '&action=edit');

        $info_fields = get_option('wc_sale_discord_info_fields', array());
        $info_fields = maybe_unserialize($info_fields);
        if (!is_array($info_fields)) {
            $info_fields = array();
        }
        $use_info_fields = !empty($info_fields);
        $field_included = function ($key) use ($use_info_fields, $info_fields) {
            return !$use_info_fields || in_array($key, $info_fields, true);
        };

        $embed_title = ($type === 'initiating') ? '⏳ Initiating payment' : (($type === 'new') ? '🎉 New Order!' : '🪄 Order Update!');

        $embed_fields = [
            ['name' => 'Order ID', 'value' => "[#{$order_id}]({$order_edit_url})", 'inline' => false],
        ];

        if ($field_included('status')) {
            $embed_fields[] = ['name' => 'Status', 'value' => $order_status, 'inline' => false];
        }
        if ($field_included('payment')) {
            $embed_fields[] = ['name' => 'Payment', 'value' => "{$order_total} {$order_currency} - {$payment_method}", 'inline' => false];
        }
        if ($field_included('product')) {
            $embed_fields[] = ['name' => 'Product', 'value' => $items_list, 'inline' => false];
        }
        if ($field_included('product_meta')) {
            $product_meta_list = $this->get_product_meta_for_embed($order_items);
            if (!empty($product_meta_list)) {
                $embed_fields[] = ['name' => 'Product Meta', 'value' => $product_meta_list, 'inline' => false];
            }
        }
        if ($field_included('creation_date')) {
            $embed_fields[] = ['name' => 'Creation Date', 'value' => "<t:{$order_timestamp}:d> (<t:{$order_timestamp}:R>)", 'inline' => false];
        }
        if ($field_included('billing')) {
            $billing_value = "**Name** » {$billing_first_name} {$billing_last_name}\n**Email** » {$billing_email}";
            if (!empty($billing_discord)) {
                $billing_value .= "\n**Discord** » {$billing_discord}";
            }
            $embed_fields[] = ['name' => 'Billing Information', 'value' => $billing_value, 'inline' => true];
        }

        $embed = [
            'title'  => $embed_title,
            'fields' => $embed_fields,
            'color'  => $embed_color
        ];

        if ($field_included('transaction_id') && !empty($transaction_id)) {
            $embed['fields'][] = ['name' => 'Transaction ID', 'value' => $transaction_id, 'inline' => false];
        }

        if ($first_product_image && !get_option('wc_sale_discord_disable_image')) {
            $embed['image'] = ['url' => $first_product_image];
        }

        $include_order_notes = get_option('wc_sale_discord_include_order_notes') || $field_included('order_notes');
        if ($include_order_notes) {
            $order_notes = $this->get_order_notes_for_embed($order_id);
            if (!empty($order_notes)) {
                $embed['fields'][] = ['name' => 'Order Notes', 'value' => $order_notes, 'inline' => false];
            }
        }

        $this->send_to_discord($webhook_url, $embed);

        // Track sent notifications for double-notification failsafe
        if ($type === 'new' && $order) {
            $order->update_meta_data('_discord_sent_' . $order_status_key . '_new', current_time('mysql'));
            $order->save();
        }
        if ($type === 'initiating' && $order) {
            $order->update_meta_data('_discord_sent_wc-pending_initiating', current_time('mysql'));
            $order->save();
        }
    }

    /**
     * Get formatted order notes for Discord embed.
     * Discord embed field values have a 1024 character limit.
     *
     * @param int $order_id Order ID.
     * @return string Formatted order notes or empty string.
     */
    private function get_order_notes_for_embed(int $order_id): string {
        if (!function_exists('wc_get_order_notes')) {
            return '';
        }

        $notes = wc_get_order_notes([
            'order_id' => $order_id,
            'limit'    => 20,
            'orderby'  => 'date_created',
            'order'    => 'DESC',
        ]);

        if (empty($notes)) {
            return '';
        }

        $lines = [];
        $max_length = 950; // Leave room for truncation message
        $current_length = 0;

        foreach ($notes as $note) {
            $type = (is_object($note) && method_exists($note, 'get_type')) ? $note->get_type() : (isset($note->type) ? $note->type : 'internal');
            $type_label = ('customer' === $type) ? __('Customer', 'wc-sale-discord-notifications') : __('Internal', 'wc-sale-discord-notifications');
            $content = (is_object($note) && method_exists($note, 'get_content')) ? $note->get_content() : (isset($note->content) ? $note->content : '');
            $content = wp_strip_all_tags($content);

            $date_obj = (is_object($note) && method_exists($note, 'get_date_created')) ? $note->get_date_created() : (isset($note->date_created) ? $note->date_created : null);
            $date = $date_obj && method_exists($date_obj, 'format') ? $date_obj->format('Y-m-d H:i') : '';

            if (empty($content)) {
                continue;
            }

            $line = "**{$type_label}** ({$date}): {$content}";
            if ($current_length + strlen($line) + 2 > $max_length) {
                $lines[] = '…';
                break;
            }
            $lines[] = $line;
            $current_length += strlen($line) + 2;
        }

        return implode("\n", $lines);
    }

    /**
     * Get formatted product/item meta for Discord embed.
     *
     * @param array $order_items Order items from WC_Order::get_items().
     * @return string Formatted product meta or empty string.
     */
    private function get_product_meta_for_embed($order_items): string {
        $lines = [];
        foreach ($order_items as $item) {
            $meta_data = $item->get_formatted_meta_data('', true);
            if (empty($meta_data)) {
                continue;
            }
            $item_name = $item->get_name();
            foreach ($meta_data as $meta) {
                $key = isset($meta->display_key) ? $meta->display_key : '';
                $val = isset($meta->display_value) ? wp_strip_all_tags($meta->display_value) : '';
                if ($key !== '') {
                    $lines[] = "**{$item_name}**: {$key} » {$val}";
                }
            }
        }
        return implode("\n", $lines);
    }

    private function send_to_discord($webhook_url, $embed) {
        $data = wp_json_encode(['embeds' => [$embed]]);

        $args = [
            'body' => $data,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 60,
        ];

        if (get_option('wc_sale_discord_force_blocking')) {
            $args['blocking'] = true;
        }

        wp_remote_post($webhook_url, $args);
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-sale-discord-notifications')) . '">' . esc_html__('Settings', 'wc-sale-discord-notifications') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

new Sale_Discord_Notifications_Woo();

if (!function_exists('wc_sale_is_wc_order')) {
    /**
     * Check if the post is a WooCommerce order.
     *
     * @param int $post_id Post id.
     *
     * @return bool True if the post is a WooCommerce order, false otherwise.
     */
    function wc_sale_is_wc_order($post_id = 0) {
        $bool = false;
        if ('shop_order' === \Automattic\WooCommerce\Utilities\OrderUtil::get_order_type($post_id)) {
            $bool = true;
        }
        return $bool;
    }
}
?>