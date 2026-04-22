<?php

if (!defined('ABSPATH')) exit;

use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings as Options;
// use Apirone\SDK\Model\Settings\Currency;
use Apirone\SDK\Model\UserData;
use Apirone\SDK\Service\Db;
use Apirone\SDK\Service\Utils;
use Apirone\SDK\Service\Logger;
use Apirone\API\Http\Request;

/** @package  */
class WC_MCCP extends WC_Payment_Gateway
{
    public ?Options $options;
    private ?string $version = null;

    public function __construct()
    {
        $this->id = 'mccp';
        $this->title = __('Crypto currency payment', 'mccp');
        $this->description = __('Start accepting multi cryptocurrency payments', 'mccp');
        $this->method_title  = __('Multi Crypto Currency Payments', 'mccp');
        $this->method_description = __('Start accepting multi cryptocurrency payments', 'mccp');

        $this->init();

        add_action('woocommerce_receipt_mccp', array( $this, 'invoice_receipt' ));
        add_action('woocommerce_api_mccp_callback', array($this, 'callback_handler'));
            // add_action('woocommerce_api_mccp_check', array($this, 'render_handler'));

        add_action('woocommerce_update_options_payment_gateways_mccp', array($this, 'process_admin_options'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'show_invoice_info'));
    }

    public function init()
    {
        global $table_prefix;
        $this->init_settings();

        Logger::set(new \WC_Logger());
        Request::userAgent('wp-mccp/' . MCCP_VERSION);
        Db::handler($this->db_callback())->adapter('mysql')->prefix($table_prefix);

        $this->do_update();
        $this->get_options();

    }

    // Load existing or create new
    public function get_options()
    {
        if(isset($this->options)) {
            return $this->options;
        }
        $json = $this->get_option('options');
        if ($json) {
            $this->options = Options::fromJson($json);
        }
        else {
            $this->options = Options::init()->createAccount();
            $this->update_option('options', $this->options->toJson());
        }
        return $this->options;
    }

    public function get_option($key, $empty_value = null)
    {
        if(isset($this->options)) {
            $value = $this->options->$key;
            if ($value !== null)
                return is_bool($value) ? (($value) ? 'yes' : 'no') : $value;
        }

        return parent::get_option($key, $empty_value);
    }

    public function get_secret($renew = false) {
        $secret = $this->get_option('secret', false);

        if ( !$secret || $renew ) {
            $secret = md5(time());
            $this->options->secret($secret);
            $this->update_option('options', $this->options->toJson());
        }
        return $secret;
    }

    public function invoice_receipt($order_id)
    {
        $order = new WC_Order($order_id);
        // $invoice = Invoice::getOrderInvoices($order_id)[0] ?? null;
        $invoice = Invoice::get($order_id)[0] ?? null;
        $invoice_id = isset($_GET['invoice']) ? sanitize_text_field($_GET['invoice']) : null;

        if($invoice) {
            if ($invoice->invoice == $invoice_id) {
                echo 'Invoice::renderLoader()';
            }
            else {
                wp_redirect(add_query_arg(['invoice' => $invoice->invoice], $order->get_checkout_payment_url(true)));
                exit();
            }
            return;
        }
        ?>
        <h2><?php __('Oops! Something went wrong.', 'mccp'); ?></h2>
        <p><?php __('Please, try again or choose another payment method.', 'mccp'); ?></p>
        <p><a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="button pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a></p>
        <?php
    }


    public function invoice_create( $order, $coin) {

        $invoice = Invoice::fromFiatAmount($order->get_total(), get_woocommerce_currency(), $coin->abbr, $this->options->factor);
        // Set invoice secret & callback URL
        $id = md5($this->get_option('secret') . $order->get_order_key());
        
        // $callback_url = sprintf(site_url() . '?wc-api=mccp_callback&id=%s&v=%s', $id, $version);
        $invoice->callbackUrl(sprintf(site_url() . '?wc-api=mccp_callback&id=%s&v=%s', $id, $this->get_option('version')));
        $invoice->linkback($order->get_checkout_order_received_url());

        $userData = UserData::init();
        if ($this->options->merchant) {
            $userData->merchant($this->options->merchant);
            $userData->url(site_url());
        }
        $userData->setPrice($order->get_total() . ' ' . get_woocommerce_currency());

        $invoice->userData($userData);
        try {
            $invoice->create();
        }
        catch (Exception $e) {}

        return $invoice;
    }

    public function payment_fields()
    {
        $coins = $this->get_coins();

        // Get networks fees if parameter set
        if ($this->options->with_fee) {
            // TODO: Check how to not
            $order = (isset($_GET['pay_for_order'])) ? wc_get_order(get_query_var('order-pay', false)) : false;
            $total = ($order) ? $total = $order->get_total() : $total = WC()->cart->total;

            $account = $this->options->account;
            $factor = $this->options->factor;
            $amount = $total * $factor;

            $fiat = strtolower(get_woocommerce_currency());
            try {
                $estimations = Utils::estimate($account, $amount, $fiat, $coins, true, $factor);
            }
            catch (Exception $e) {
                $coins = [];
            }
        }
        if (empty($coins)) {
            _e('Cryptocurrency payment temporary unavailable. Choose other payment method.', 'mccp');
            return;
        }
        pa($estimations, '$estimations');

        ?>
        <select id="mccp_currency" name="mccp_currency">
        <?php
            foreach ( $coins as $coin ) : ?>
            <option value="<?php echo $coin; ?>">
                <?php echo Utils::getCoin($coin)->alias; ?>
                <?php
                    // echo esc_html(Utils::humanizeAmount(Utils::cur2min($amounts[$coin->abbr], $coin->unitsFactor), $coin));
                ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php
    }

    public function get_coins(): array
    {
        $coins = [];
        foreach ((array) $this->options->coins as $abbr) {
            $coin = Utils::getCoin($abbr);
            if ($coin->testnet && !$this->show_testnet()) {
                continue;
            }
            $coins[] = $abbr;
        }

        return $coins;
    }

    public function show_testnet()
    {
        $tc = $this->options->test_customer;
        $customer_email = (WC()->customer) ? WC()->customer->get_billing_email() : null;

        return ( ($tc !== null && $tc == $customer_email) || $tc == '*' || current_user_can('manage_options')) ? true : false;
    }

    /**
     * Payment process handler
     * 
     * @param int $order_id 
     * @return array 
     */
    public function process_payment($order_id)
    {
        $order = new WC_Order($order_id);
        $order->update_status('pending');
        $order->save();
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();

        $coin = $this->options->getCurrency(sanitize_text_field($_POST['mccp_currency']));
        $invoice = Invoice::getByOrder($order_id)[0] ?? null;

        // Invoice already exist
        if ($invoice) {
            $invoice->update();
            if (isset($_GET['pay_for_order'])) {
                $new = null;
                // Create new invoice if expired;
                if ($invoice->status == 'expired' && $order->get_status() === 'failed') {
                    $new = $this->invoice_create($order, $coin);
                }
                // Create new invoice if total or currency changed (invoice not expired)
                if (in_array($invoice->status, ['created', 'partpaid']) && $invoice->details->currency != $coin->abbr) {
                    $new = $this->invoice_create($order, $coin);
                }
                if ($new) {
                    $redirect = add_query_arg(['invoice' => $new->invoice], $order->get_checkout_payment_url(true));
                    return ['result' => 'success', 'redirect' => $redirect];
                }
            }

        }

        $invoice = $this->invoice_create($order, $coin);

        if ($invoice) {
            $redirect = add_query_arg(['invoice' => $invoice->invoice], $order->get_checkout_payment_url(true));
        }
        return ['result' => 'success', 'redirect' => $redirect];
    }

    public function process_admin_options()
    {
        $this->form_fields = $this->admin_fields();
        $post_data = $this->get_post_data();

        $options = $this->options_fields();

        $this->options->merchant($this->get_field_value('merchant', $options['merchant'], $post_data));
        $this->options->test_customer($this->get_field_value('test_customer', $options['test_customer'], $post_data));
        $this->options->timeout((int) $this->get_field_value('timeout', $options['timeout'], $post_data));
        $this->options->processing_fee($this->get_field_value('processing_fee', $options['processing_fee'], $post_data));
        $this->options->with_fee($this->get_field_value('with_fee', $options['with_fee'], $post_data) == 'yes' ? true : false);
        $this->options->factor((float) $this->get_field_value('factor', $options['factor'], $post_data));
        $this->options->logo($this->get_field_value('logo', $options['logo'], $post_data) == 'yes' ? true : false);
        $this->options->debug($this->get_field_value('debug', $options['debug'], $post_data) == 'yes' ? true : false);

        try {
            $networks = $this->options->networks;
            // set addresses
            $policy = $this->options->processing_fee;
            $networks_data = $this->get_field_value('networks', $options['networks'], $post_data);

            foreach ($networks as $item) {
                $this->options->currency($item->abbr)->address($networks_data[$item->abbr]);
                $this->options->currency($item->abbr)->policy($policy);
                if ($item->isNetwork()) {
                    if($item->tokens) {
                        $tokens = array_merge([$item], $item->tokens);
                        foreach ($tokens as $token) {
                            $this->options->currency($token->abbr)->policy($this->options->processing_fee);
                            $this->options->currency($token->abbr)->address($item->address);
                        }
                    }
                }
            }
            $this->options->saveNetworks();

            // Process errors
            $errors = false;
            foreach ($this->options->networks as $network) {
                if ($network->hasError()) {
                    $errors = true;
                    WC_Admin_Settings::add_error($network->alias . " not saved: " . $network->error);
                }
            }
            // Process coins
            if ($errors) {
                $this->options->loadCurrencies();
            }
            $coins = [];
            $tokens_data = $this->get_field_value('tokens', [], $post_data);
            foreach ($this->options->networks as $item) {
                if ($item->address && !$item->hasError()) {
                    if ($item->tokens) {
                        $tokens = array_merge([$item], $item->tokens);
                        foreach ($tokens as $token) {
                            if (array_key_exists($token->abbr, $tokens_data)) {
                                $coins[] = $token->abbr;
                            }
                        }
                    }
                    else {
                        $coins[] = $item->abbr;
                    }
                }
            }
        }
        catch(Exception $e) {
            WC_Admin_Settings::add_error(__('Settings not saved.', 'mccp'));
            return;
        }

        $this->options->coins($coins);

        $this->settings['enabled'] = $this->get_field_value('enabled', $this->form_fields['enabled'], $post_data);
        $this->settings['options'] = $this->options->toJson();

        update_option('woocommerce_mccp_settings', $this->settings);
    }
    
    public function admin_options()
    {
        global $wp_version;

        try {
            $wrapper = '<hr/><table class="form-table mccp-settings">%s</table>';
            $form_data = sprintf($wrapper, $this->generate_settings_html($this->admin_fields(), false));
        }
        catch (Exception $e) {
            wp_admin_notice(__("<strong>Could not connect to Apirone API. Please try later.</strong>", 'mccp'), ['type'=>'error']);
            $form_data = '';
        }

        ?>
            <h3><?php _e('Multi Crypto Currency Payment Gateway', 'mccp'); ?></h3>
            <div><?php _e('This plugin uses the Apirone crypto processing service.', 'mccp'); ?> <a href="https://apirone.com" target="_blank"><?php _e('Details'); ?></a></div>
                <?php echo $form_data; ?>
            <div>
                <hr/>
                <h3>Plugin Info:</h3>
                Version: <b><?php echo $this->get_option('version', 'n/a'); ?></b></br>
                Account: <b><?php echo $this->options->account; ?></b><br/>
                PHP version: <b><?php echo phpversion(); ?></b><br/>
                WordPress: <b><?php echo $wp_version; ?></b><br/>
                WooCommerce: <b><?php echo WC()->version; ?></b><br/>
                <?php _e('Apirone support: '); ?><a href="mailto:support@apirone.com">support@apirone.com</a>
                <hr/>
            </div>
        <?php
    }

    public function admin_fields()
    {
        return array(
            'enabled' => array(
                'title' => __('On/off', 'mccp'),
                'type' => 'checkbox',
                'label' => __('On', 'mccp'),
                'default' => 'no',
                'description' => __('Activate/deactivate MCCP gateway', 'mccp'),
                'desc_tip' => true,
            ),
            'options' => array(
                'type' => 'options',
                'description' => '',
                'default' => [],
            ),
        );
    }

    private function options_fields()
    {
        return array(
                'merchant' => array(
                'title' => __('Merchant name', 'mccp'),
                'type' => 'text',
                'default' => '',
                'description' => __('If set, adds the merchant to the invoice with the site URL.', 'mccp'),
                'desc_tip' => true,
            ),
            'networks' => array(
                'type' => 'networks',
                'description' => __('List of available cryptocurrencies and tokens processed by Apirone', 'mccp'),
                'desc_tip' => true,
                'default' => [],
            ),
            'test_customer' => array(
                'title' => __('Test currency customer', 'mccp'),
                'type' => 'text',
                'default' => '',
                'placeholder' => 'user@example.com',
                'description' => __('Enter an email of the customer to whom the test currencies will be shown.', 'mccp'),
                'desc_tip' => true,
            ),
            'timeout' => array(
                'title' => __('Payment timeout, sec.', 'mccp'),
                'type' => 'number',
                'default' => '1800',
                'description' => __('The period during which a customer shall pay. Set value in seconds', 'mccp'),
                'desc_tip' => true,
                'custom_attributes' => array('min' => 0,),
            ),
            'factor' => array(
                'title' => __('Price adjustment factor', 'mccp'),
                'type' => 'number',
                'default' => '1',
                'description' => __('If you want to add/subtract percent to/from the payment amount, use the following  price adjustment factor multiplied by the amount.<br />For example: <br />100% * 0.99 = 99% <br />100% * 1.01 = 101%', 'mccp'),
                'desc_tip' => true,
                'custom_attributes' => array('min' => 0.01, 'step' => '0.01', 'required' => 'required'),
            ),
            'processing_fee' => array(
                'title' => __('Processing fee plan', 'mccp'),
                'type' => 'select',
                'options' => [
                    'percentage' => __('Percentage'),
                    'fixed' => __('Fixed'),
                ],
                'default' => 'fixed',
            ),
            'with_fee' => array(
                'title' => __('Include fees', 'mccp'),
                'type' => 'checkbox',
                'label' => __('On', 'mccp'),
                'default' => 'no',
                'description' => __('Adds service and network fees to total. Final amount per coin is shown in selector.', 'mccp'),
                'desc_tip' => true,
            ),
            'logo' => array(
                'title' => __('Apirone logo', 'mccp'),
                'type' => 'checkbox',
                'label' => __('Display', 'mccp'),
                'default' => 'yes',
                'description' => __('Display Apirone logo on invoice page', 'mccp'),
                'desc_tip' => true,
            ),
            'debug' => array(
                'title' => __('Debug mode', 'mccp'),
                'type' => 'checkbox',
                'label' => __('On', 'mccp'),
                'default' => 'no',
                'description' => __('Write debug information into log file', 'mccp'),
                'desc_tip' => true,
            ),
        );
    }

    public function generate_options_html ($key, $data)
    {
        return $this->generate_settings_html($this->options_fields(), false);
    }

    /**
    * Generate currencies list options for admin page
    *
    * @param mixed $key 
    * @param mixed $data 
    * @return string|false 
    */
    public function generate_networks_html ($key, $data)
    {
        $networks = $this->options->networks;
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php _e('Networks & tokens', 'mccp'); ?><?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
            </th>
            <td class="forminp" id="mccp-order_states">
                <div class="table-wrapper">
                    <table class="form-table">
                        <tbody>
                        <?php
                        //foreach($this->options->networks as $network)
                        foreach($networks as $network) : ?>
                            <?php $tokens = $network->tokens; 
                                $blockchain = ($tokens) ? __(' Blockchain', 'mccp') : '';
                            ?>
                            <tr valign="middle" class="single_select_page">
                                <th scope="row" class="titledesc">
                                    <label for="mccp_<?php echo esc_html( $network->abbr ); ?>" class="currency-label">
                                        <span class="currency-icon <?php echo $network->abbr; ?>"></span>
                                        <span style="position:relative">
                                        <?php echo esc_html( $network->alias . $blockchain ); ?>
                                        <?php if ($network->isTestnet()) : ?>
                                        <?php echo wc_help_tip(__('Use this currency for test purposes only! This currency shown for admin and "test currency customer" (if set) is only on the front end of Woocommerce!')); ?>
                                        <?php endif; ?>
                                        </span>

                                    <?php echo wc_help_tip(sprintf(__('Enter valid address to activate <b>%s</b> blockchain', 'mccp'), $network->name)); ?>
                                    </label>
                                </th>
                                <td class="forminp">
                                    <input type="text" name="woocommerce_mccp_networks[<?php echo esc_html( $network->abbr ); ?>]" class="input-text regular-input<?php echo $network->hasError() ? ' error' : '' ;?>" value="<?php echo esc_html( $network->address ); ?>">
                                    <?php  if ($tokens) : ?>
                                        <div class="tokens_wrapper">
                                        <?php $tokens = array_merge([$network], $tokens); ?>
                                            <?php foreach ($tokens as $token) : ?>
                                            <div class="token_item">
                                                <span class="currency-icon <?php echo str_replace('@', '_', esc_html($token->abbr)); ?>"></span>
                                                <label for="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]">
                                                    <input type="checkbox" name="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]"
                                                    id="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]"
                                                    <?php if (in_array($token->abbr, $this->options->coins ?? [])) : ?>
                                                    checked="checked"
                                                    <?php endif; ?>>
                                                    <?php echo strtoupper($token->alias); ?>
                                                    <?php echo wc_help_tip(sprintf(__('Show/hide <b>%s</b> from currency selector', 'mccp'), $token->name)); ?>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>

        <?php
        return ob_get_clean();
    }

    public function validate_networks_field($k, $v)
    {
        return $v;
    }

    public function validate_tokens_field($k, $v)
    {
        return ($v == null) ? [] : $v;
    }

    public function show_invoice_info($order)
    {
        if (is_admin() && $order->payment_method == 'mccp') {
            echo '<h3>' . __('Payment details', 'mccp') . '</h3>';
            $invoices = Invoice::getByOrder($order->get_id());
            if ($invoices) {
                foreach ($invoices as $invoice) {
                    $currency = $this->options->getCurrency($invoice->details->currency);
                    echo '<hr />';
                    echo sprintf(__('<div>Address: <b>%s</b></div>', 'mccp'), $invoice->details->address);
                    echo sprintf(__('<div>Created: <b>%s</b></div>', 'mccp'), get_date_from_gmt($invoice->details->created, 'd.m.Y H:i:s'));
                    echo sprintf(__('<div>Amount: <b>%s %s</b></div>', 'mccp'), Utils::humanizeAmount($invoice->details->amount, $currency), strtoupper($invoice->details->currency));
                    echo sprintf(__('<div>Status: <b>%s</b></div>', 'mccp'), $invoice->status);
                    foreach ($invoice->details->history as $item) {
                        $status = $item->txid !== null ? ' <a class="address-link" href="' . Utils::getTransactionLink($currency, $item->txid) . '" target="_blank">' . $item->status . '</a>' : $item->status;
                        echo '<div>- <i>' . get_date_from_gmt($item->date, 'd.m.Y H:i:s') . '</i> <b>' . $status . '</b></div>';
                    }
                }
            }
            else {
                echo '<p>' . __('Invoice for this order not found', 'mccp') . '</p>';
            }
        }
        return;
    }

    public function callback_handler() 
    {
        $order_handler = static function($invoice) {
            WC_MCCP::order_status_update($invoice);
        };
        $current_secret = $this->get_secret();
        $callback_checker = static function($current_secret) {
            $secret = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : null;
            $data = file_get_contents('php://input');
            $data = ($data) ? json_decode(Utils::sanitize($data)) : new \stdClass;
            $invoice = $data->invoice ?? null; 
            if ($invoice) {
                $invoice = Invoice::get($invoice);
                $order = new WC_Order($invoice->order);

                // if ($secret == md5($instance->get_secret() . $order->get_order_key())) {
                if ($secret == md5($current_secret . $order->get_order_key())) {
                    return;
                }
            }

            $message = sprintf('Secret %s not valid for invoice %s', $secret, $invoice->invoice ? $invoice->invoice : 'is null');
            Utils::sendJson($message, 400);
            exit;
        };

        echo Invoice::callbackHandler($order_handler, $callback_checker);
        exit;
    }

    public static function order_status_update($invoice = null, $order = null)
    {
        if ($invoice == null) {
            return;
        }

        $order = ($order) ?? new WC_Order($invoice->order);

        $saved_status = $invoice->getMeta('order-status');
        $current_status = $order->get_status();
        $new_status = WC_MCCP::order_status_by_invoice($invoice);

        // Set status for new invoice
        if ($saved_status == null) {
            $order->update_status('wc-' . $new_status);
            // $invoice->setMeta('order-status', $new_status);
            $invoice->setMeta('order-status', $new_status);
        }

        if($saved_status == $current_status && $saved_status != $new_status) {
            $order->update_status('wc-' . $new_status);
            $invoice->{'order-status'}($new_status);
        }
        return;
    }

    public static function order_status_by_invoice($invoice)
    {
        $statuses = [
            'created'   => 'pending',
            'partpaid'  => 'pending',
            'paid'      => 'pending',
            'overpaid'  => 'pending',
            'completed' => 'processing',
            'expired'   => 'failed',
        ];

        return $statuses[$invoice->status];
    }

    public static function db_callback()
    {
        return static function($query) {
            global $wpdb;
            if (preg_match('/select/i', $query)) {
                $result = $wpdb->get_results($query, ARRAY_A);
            }
            else {
                $result = (bool) $wpdb->query($query, );
            }
            return $result;
        };
    }

    public function do_update()
    {
        $mccp_settings = get_option('woocommerce_mccp_settings', false);
        if (!$mccp_settings) {
            return;
        }

        $current_version = $mccp_settings['version'] ?? false;

        if(version_compare(MCCP_VERSION, $current_version, '=')) {
            return;
        }

        if ($current_version == false) {
            mccp_create_table();
        }

        // Up version above SDK 2.0  (mccp v3.0.0)
        if(version_compare($current_version, '3.0.0', '>=')) {
            $mccp_settings['version'] = MCCP_VERSION;
            update_option('woocommerce_mccp_settings', $mccp_settings);
            return;
        }

        $coins = [];

        // Migrate from SDK to SDK 2.0
        if(version_compare($current_version, '2.0.0', '>=') && version_compare($current_version, '3.0.0', '<')) {
            $options = Options::fromExistingAccount($mccp_settings['options']->account, $mccp_settings['options']->{'transfer-key'});

            $options->merchant($mccp_settings['options']->merchant ?? '');
            $options->timeout($mccp_settings['options']->timeout ?? 1800 );
            $options->factor($mccp_settings['options']->factor ?? 1);
            $options->logo($mccp_settings['options']->apirone_logo ?? true);
            $options->test_customer($mccp_settings['options']->extra->test_customer ?? '');
            $options->processing_fee($mccp_settings['options']->extra->processing_fee ?? 'percentage');

            foreach ($options->networks as $item) {
                if ($item->address == null) {
                    continue;
                }
                if (count($item->tokens) == 0) {
                    $coins[] = $item->abbr;
                    continue;
                }
                foreach ($item->tokens as $token) {
                    if (property_exists($mccp_settings['options']->extra, $token->abbr) && $mccp_settings['options']->extra->{$token->abbr} == 1) {
                        $coins[] = $token->abbr;
                    }
                }
            }
        }

        // Migrate from 1.x - before SDK
        if ( version_compare($current_version, '1.2.10', '<=') ) {
            $account = get_option('woocommerce_mccp_account', null);
            $options = ($account) ? Options::fromJson($account) : Options::init()->createAccount();

            $options->merchant($mccp_settings['merchant'] ?? '');
            $options->factor((float) $mccp_settings['factor'] ?? 1);
            $options->timeout((int) $mccp_settings['timeout'] ?? 1800);
            $options->logo($mccp_settings['apirone_logo'] ?? true);
            $options->debug($mccp_settings['debug'] ?? false);
            $options->test_customer($mccp_settings['test_customer'] ?? '');
            $options->processing_fee($mccp_settings['processing_fee'] ?? 'percentage');

            $currencies = is_array($mccp_settings['currencies']) ? $mccp_settings['currencies'] : [];
            foreach ($options->networks as $network) {
                if (array_key_exists($network->abbr, $currencies)) {
                    $network->policy('percentage');
                    $network->address($currencies[$network->abbr]->address);
                }
            }
            foreach ($currencies as $currency) {
                if (!empty($currency->address)) {
                    $coins[] = $currency->abbr;
                }
            }
        }

        $options->secret($mccp_settings['secret'] ?? get_option('woocommerce_mccp_secret'));
        $options->coins($coins);

        $mccp_new['enabled'] = $mccp_settings['enabled'];
        $mccp_new['options'] = $options->toJson();
        $mccp_new['version'] = MCCP_VERSION;

        update_option('woocommerce_mccp_settings', $mccp_new);

        delete_option('woocommerce_mccp_secret');
        delete_option('woocommerce_mccp_wallets');
        delete_option('woocommerce_mccp_account');


        if (version_compare($current_version, '1.1.0', '>=') && version_compare($current_version, '1.2.10', '<=')) {
            global $wpdb;

            if(!empty($wpdb->get_results("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = '" . DB_NAME . "' AND table_name = '" . $wpdb->prefix . "apirone_mccp';"))) {
                $wpdb->query("RENAME TABLE " . DB_NAME . "." . $wpdb->prefix. "apirone_mccp TO " . DB_NAME . "." . $wpdb->prefix. "apirone_invoice;");
                $wpdb->query("ALTER TABLE " . DB_NAME . "." . $wpdb->prefix. "apirone_invoice RENAME COLUMN `order_id` TO `order`;");
                $wpdb->query("ALTER TABLE " . DB_NAME . "." . $wpdb->prefix. "apirone_invoice ENGINE = InnoDB;");
                $wpdb->query("DROP TABLE IF EXISTS " . DB_NAME . "." . $wpdb->prefix . "apirone_mccp" );
            }

            $result = $wpdb->get_results($query = "SELECT id, details FROM " . $wpdb->prefix . "apirone_invoice", OBJECT);
            if ($result) {
                $converted = [];
                foreach ($result as $row) {
                    $details = json_decode($row->details);
                    if(property_exists($details->{'user-data'}, 'price') && gettype($details->{'user-data'}->price) == 'object') {
                        $details->{'user-data'}->price = $details->{'user-data'}->price->amount . ' ' . $details->{'user-data'}->price->currency;
                        $converted[] = ['id' => $row->id, 'details' => json_encode($details)];
                    }
                }
                if ($converted) {
                    foreach ($converted as $row) {
                        $wpdb->update( $wpdb->prefix . "apirone_invoice",  ['details' =>$row['details']], ['id' => $row['id']]);
                    }
                }
            }
        }

        $this->init_settings();
    }
}
