<?php

if (!defined('ABSPATH')) exit;

use Apirone\API\Log\LoggerWrapper;
use Apirone\SDK\Invoice;
use Apirone\SDK\Model\Settings as Options;
use Apirone\SDK\Model\Settings\Currency;
use Apirone\SDK\Model\UserData;
use Apirone\SDK\Service\InvoiceDb;
use Apirone\SDK\Service\InvoiceQuery;
use Apirone\SDK\Service\Render;
use Apirone\SDK\Service\Utils;

/** @package  */
class WC_MCCP extends WC_Payment_Gateway
{
    public ?Options $options;
    
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
        add_action('woocommerce_api_mccp_check', array($this, 'render_handler'));

        add_action('woocommerce_update_options_payment_gateways_mccp', array($this, 'process_admin_options'));
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'show_invoice_info'));
        
        Invoice::dataUrl(site_url() . '/?wc-api=mccp_check');
    }

    public function init()
    {
        global $wpdb, $table_prefix;
        $this->init_settings();

        // Set logger
        $debug = $this->get_option('debug') == 'yes' ? true : false;
        LoggerWrapper::setLogger(new \WC_Logger(), $debug);

        $this->do_update();
        $this->get_options();

        Invoice::db($this->db_callback(), $table_prefix);
        Invoice::settings($this->options);
    }

    // Load existing og create new
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

    public function get_secret($renew = false) {
        $secret = $this->get_option('secret', false);

        if ( !$secret || $renew ) {
            $secret = md5(time());
            $this->update_option('secret', $secret);
        }
        return $secret;
    }

    public function invoice_receipt($order_id)
    {
        $order = new WC_Order($order_id);
        $invoice = Invoice::getOrderInvoices($order_id)[0] ?? null;
        $invoice_id = isset($_GET['invoice']) ? sanitize_text_field($_GET['invoice']) : null;

        if($invoice) {
            if ($invoice->invoice == $invoice_id) {
                echo Invoice::renderLoader();
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
        $invoice->order($order->get_id())->lifetime($this->options->getTimeout());
        
        // Set invoice secret & callback URL
        $id = md5($this->get_option('secret') . $order->get_order_key());
        
        // $callback_url = sprintf(site_url() . '?wc-api=mccp_callback&id=%s&v=%s', $id, $version);
        $invoice->callbackUrl(sprintf(site_url() . '?wc-api=mccp_callback&id=%s&v=%s', $id, $this->get_option('version')));
        $invoice->linkback($order->get_checkout_order_received_url());

        $userData = UserData::init();
        if ($this->options->merchant) {
            $userData->setMerchant($this->options->merchant);
        }
        $userData->setUrl(site_url());
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
        if (isset($_GET['pay_for_order'])) {
            $order    = wc_get_order(get_query_var('order-pay', false));
            $total = $order->get_total();
        }
        else {
            $total = WC()->cart->total;
        }

        $total = $total * $this->options->getFactor();
        $woo_currency = get_woocommerce_currency();

        $coins = $this->get_available_coins();
        if ( empty($coins)) {
            _e('Cryptocurrency payment temporary unavailable. Choose other payment method.', 'mccp');
            return;
        }
        $amounts = Utils::fiat2cryptos($total, strtolower($woo_currency), $coins);
        ?>
        <select id="mccp_currency" name="mccp_currency">
        <?php
            foreach ( $coins as $coin ) : ?>
            <option value="<?php echo esc_html($coin->abbr); ?>">
                <?php echo esc_html($coin->alias); ?>:
                <?php
                    echo esc_html(Utils::humanizeAmount(Utils::cur2min($amounts[$coin->abbr], $coin->unitsFactor), $coin));
                ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function get_available_coins(): array
    {
        $coins = [];
        $networks = $this->options->networks();
        $test_customer = $this->options->getExtra('test_customer');

        foreach ($networks as $network) {
            if ($network->getAddress() !== null && !$network->hasError()) {
                if ($network->isTestnet()) {
                    if ($test_customer == WC()->customer->get_billing_email() || $test_customer == '*' || current_user_can('manage_options')) {
                        $coins[$network->abbr] = $network;
                    }
                }
                $tokens = $network->getTokens($this->options->getCurrencies());
                if ($tokens) {
                    $tokens = array_merge([$network], $tokens);
                    foreach ($tokens as $token) {
                        if ($this->options->getExtra($token->abbr) == true) {
                            $coins[$token->abbr] = $token;
                        }
                    }
                }
                else {
                    $coins[$network->abbr] = $network;
                }
            }
        }
        return $coins;
    }
    public function get_option($key, $empty_value = null)
    {
        if(isset($this->options)) {
            $property = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));

            if (property_exists($this->options, $property)) {
                $method = 'get' . ucfirst($property);
                $value = $this->options->$method();
                return is_bool($value) ? (($value) ? 'yes' : 'no') : $value;
            }

            $extra = $this->options->getExtra($key);
            if ($extra) {
                return $extra;
            }
        }

        return parent::get_option($key, $empty_value);
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
        $invoice = Invoice::getOrderInvoices($order_id)[0] ?? null;

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
        parent::process_admin_options();
		
        $post_data = $this->get_post_data();
        $options = $this->options_fields();

        $this->options->setMerchant($this->get_field_value('merchant', $options['merchant'], $post_data));
        $this->options->setExtra('test_customer', $this->get_field_value('test_customer', $options['test_customer'], $post_data));
        $this->options->setTimeout((int) $this->get_field_value('timeout', $options['timeout'], $post_data));
        $this->options->setExtra('processing_fee', $this->get_field_value('processing_fee', $options['processing_fee'], $post_data));
        $this->options->setFactor((float) $this->get_field_value('factor', $options['factor'], $post_data));
        $this->options->setLogo($this->get_field_value('logo', $options['logo'], $post_data) == 'yes' ? true : false);
        $this->options->setDebug($this->get_field_value('debug', $options['debug'], $post_data) == 'yes' ? true : false);

        // set addresses & tokens
        $policy = $this->options->getExtra('processing_fee');
        $networks_data = $this->get_field_value('networks', $options['networks'], $post_data);
        $tokens_data = $this->get_field_value('tokens', $options['tokens'], $post_data);

        foreach ($this->options->getNetworks() as $network) {
            $this->options->getCurrency($network->abbr)->parseAbbr()->setAddress($networks_data[$network->abbr]);
            $this->options->getCurrency($network->abbr)->setPolicy($policy);
            if ($network->isNetwork()) {
                $tokens = $network->getTokens($this->options->currencies);
                if($tokens) {
                    $tokens = array_merge([$network], $tokens);
                    foreach ($tokens as $token) {
                        $this->options->getCurrency($token->abbr)->setPolicy($this->options->getExtra('processing_fee'));
                        $this->options->getCurrency($token->abbr)->setAddress($network->getAddress());
                        $this->options->setExtra($token->abbr, array_key_exists($token->abbr, $tokens_data) ? true : false);
                    }
                }
            }
        }
        $this->options->saveCurrencies();
        $this->update_option('options', $this->options->toJson());
    }
    
    public function admin_options()
    {
        global $wp_version;
        $this->form_fields = $this->admin_fields();

        if (count($this->options->currencies) < count($this->options->loadCurrencies()->currencies)) {
            $this->update_option('options', $this->options->toJson());
        }

        foreach ($this->options->getNetworks() as $network) {
            if ($network->hasError()) {
                $this->add_error(sprintf("<strong>%s has error:</strong> %s", ...[$network->name, $network->getError()]));
            }
        }
        if ($this->get_errors()) {
            $this->display_errors();
        }
        ?>
            <h3><?php _e('Multi Crypto Currency Payment Gateway', 'mccp'); ?></h3>
            <div><?php _e('This plugin uses the Apirone crypto processing service.', 'mccp'); ?> <a href="https://apirone.com" target="_blank"><?php _e('Details'); ?></a></div>
            <hr>
            <table class="form-table mccp-settings">
                <?php $this->generate_settings_html($this->form_fields); ?>
            </table>
            <div><hr/>
            <h3>Plugin Info:</h3>
                Version: <b><?php echo $this->get_option('version', 'n/a'); ?></b></br>
                Account: <b><?php echo $this->options->getAccount(); ?></b><br/>
                PHP version: <b><?php echo phpversion(); ?></b><br/>
                WordPress: <b><?php echo $wp_version; ?></b><br/>
                WooCommerce: <b><?php echo WC_VERSION; ?></b><br/>
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
                'description' => __('Shows Merchant name in the payment order. If this field is blank then Site Title from General settings will be shown.', 'mccp'),
                'desc_tip' => true,
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
            'networks' => array(
                'type' => 'networks',
                'description' => __('List of available cryptocurrencies processed by Apirone', 'mccp'),
                'desc_tip' => true,
                'default' => [],
            ),
            'tokens' => array(
                'type' => 'tokens',
                'description' => __('List of available cryptocurrencies processed by Apirone', 'mccp'),
                'desc_tip' => true,
                'default' => [],
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
            'factor' => array(
                'title' => __('Price adjustment factor', 'mccp'),
                'type' => 'number',
                'default' => '1',
                'description' => __('If you want to add/subtract percent to/from the payment amount, use the following  price adjustment factor multiplied by the amount.<br />For example: <br />100% * 0.99 = 99% <br />100% * 1.01 = 101%', 'mccp'),
                'desc_tip' => true,
                'custom_attributes' => array('min' => 0.01, 'step' => '0.01', 'required' => 'required'),
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
        $fields = $this->options_fields();
        ob_start();
        echo $this->generate_text_html('merchant', $fields['merchant']);
        echo $this->generate_text_html('test_customer', $fields['test_customer']);
        echo $this->generate_text_html('timeout', $fields['timeout']);
        echo $this->generate_networks_html('networks', $fields['networks']);
        echo $this->generate_select_html('processing_fee', $fields['processing_fee']);
        echo $this->generate_text_html('factor', $fields['factor']);
        echo $this->generate_checkbox_html('logo', $fields['logo']);
        echo $this->generate_checkbox_html('debug', $fields['debug']);
        return ob_get_clean();
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
                        foreach($this->options->getNetworks() as $network) : ?>
                            <?php $tokens = $network->getTokens($this->options->getCurrencies()); 
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

                                    <?php echo wc_help_tip(sprintf(__('Enter valid address to activate <b>%s</b> blockchain', 'mccp'), $network->getName())); ?>
                                    </label>
                                </th>
                                <td class="forminp">
                                    <input type="text" name="woocommerce_mccp_networks[<?php echo esc_html( $network->abbr ); ?>]" class="input-text regular-input<?php echo $network->hasError() ? ' error' : '' ;?>" value="<?php echo esc_html( $network->getAddress() ); ?>">

                                    <?php  if ($tokens) : ?>
                                        <div class="tokens_wrapper">
                                        <?php $tokens = array_merge([$network], $tokens); ?>
                                            <?php foreach ($tokens as $token) : ?>
                                            <div class="token_item">
                                                <span class="currency-icon <?php echo str_replace('@', '_', esc_html($token->abbr)); ?>"></span>
                                                <label for="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]">
                                                    <input type="checkbox" name="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]"
                                                    id="woocommerce_mccp_tokens[<?php echo esc_html($token->abbr); ?>]"
                                                    <?php if ($this->options->getExtra($token->abbr) == 1) : ?>
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
            $invoices = Invoice::getOrderInvoices($order->get_id());
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

    public function do_update()
    {
        $settings = get_option('woocommerce_mccp_settings', false);
        if (!$settings) {
            return;
        }

        $version = $settings['version'] ?? false;
        $code_version = get_plugin_data(MCCP_MAIN)['Version'];

        if(version_compare($code_version, $version, '=')) {
            return;
        }

        // Up from 2.0.0 and above
        if(version_compare($version, '2.0.0', '>=')) {
            $settings['version'] = $code_version;
            update_option('woocommerce_mccp_settings', $settings, true);
            return;
        }

        // Up to 2.0.0 - Migrate to SDK
        $account = get_option('woocommerce_mccp_account', null);
        $options = ($account) ? Options::fromJson($account) : Options::init()->createAccount();

        if (version_compare($version, '1.1.0', '>=') && version_compare($version, '1.2.10', '<=')) {
            // Rename & update existing table
            global $wpdb, $table_prefix;
            $query = "SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE table_schema = '" . DB_NAME . "' AND table_name = '" . $table_prefix . "apirone_mccp';";
            $rows = $wpdb->get_results($query);
            if(!empty($rows)){
                $rename_table = "RENAME TABLE " . DB_NAME . "." . $table_prefix. "apirone_mccp TO " . DB_NAME . "." . $table_prefix. "apirone_invoice;";
                $wpdb->query($rename_table);
                $rename_column = "ALTER TABLE " . DB_NAME . "." . $table_prefix. "apirone_invoice RENAME COLUMN `order_id` TO `order`;";
                $wpdb->query($rename_column);
            }

            // Update old user-data price format for existing invoices
            $query = "SELECT id, details FROM " . $table_prefix . "apirone_invoice";
            $result = $wpdb->get_results($query, OBJECT);
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
                        $wpdb->update( $table_prefix . "apirone_invoice",  ['details' =>$row['details']], ['id' => $row['id']]);
                    }
                }
            }

            // Move options
            $options->setMerchant($settings['merchant']);
            $options->setFactor((float) $settings['factor']);
            $options->setTimeout((int) $settings['timeout']);
            $options->setLogo($settings['apirone_logo']);
            $options->setDebug($settings['debug']);

            $options->setExtra('test_customer', $settings['test_customer'] ?? '');
            $options->setExtra('processing_fee', $settings['processing_fee'] ?? 'percentage');

            // Set tokens as enabled if network address is set
            $currencies = is_array($settings['currencies']) ? $settings['currencies'] : [];
            foreach ($options->getNetworks() as $network) {
                if (array_key_exists($network->abbr, $currencies)) {
                    $network->setAddress($currencies[$network->abbr]->address)->setPolicy('percentage')->parseAbbr();
                    if ($network->isNetwork()) {
                        $tokens = $network->getTokens($options->currencies);
                        if($tokens) {
                            $tokens = array_merge([$network], $tokens);
                            foreach ($tokens as $token) {
                                $options->getCurrency($token->abbr)->setPolicy('percentage');
                                $options->getCurrency($token->abbr)->setAddress($network->getAddress());
                                if($token->address) {
                                    $options->setExtra($token->abbr, true);
                                }
                            }
                        }
                    }
                }
            }
        }
    
        if ($version == false) {
            // Update 1.0.0
            mccp_create_table();

            $currencies = is_array($settings['currencies']) ? $settings['currencies'] : [];
            foreach ($options->getNetworks() as $network) {
                if (array_key_exists($network->abbr, $currencies)) {
                    $network->setAddress($currencies[$network->abbr]['address'])->setPolicy('percentage')->parseAbbr();
                    if ($network->isNetwork()) {
                        $tokens = $network->getTokens($options->currencies);
                        if($tokens) {
                            $tokens = array_merge([$network], $tokens);
                            foreach ($tokens as $token) {
                                $options->getCurrency($token->abbr)->setPolicy('percentage');
                                $options->getCurrency($token->abbr)->setAddress($network->getAddress());
                                $options->setExtra($token->abbr, true);
                            }
                        }
                    }
                }
            }
        }

        $options->saveCurrencies();

        // unset($settings['currencies']);
        $settings['enabled'] = $this->settings['enabled'];
        $settings['options'] = $options->toJson();
        $settings['secret'] = $settings['secret'] ?? get_option('woocommerce_mccp_secret');
        $settings['version'] = $code_version;

        update_option('woocommerce_mccp_settings', $settings);
        delete_option('woocommerce_mccp_wallets');
        delete_option('woocommerce_mccp_account');
        delete_option('woocommerce_mccp_secret');
        $this->init_settings();
    }
    public function callback_handler() 
    {
        $this->callback_check();

        $order_handler = static function($invoice) {
            WC_MCCP::order_status_update($invoice);
        };

        echo Invoice::callbackHandler($order_handler);
        exit;
    }

    private function callback_check()
    {
        $secret = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : null;
        $data = file_get_contents('php://input');
        $data = ($data) ? json_decode(Utils::sanitize($data)) : new \stdClass;
        $invoice = $data->invoice ?? null; 
        if ($invoice) {
            $invoice = Invoice::getInvoice($invoice);
            $order = new WC_Order($invoice->order);

            if ($secret == md5($this->get_option('secret') . $order->get_order_key())) {
                return;
            }
        }

        $message = sprintf('Secret %s not valid for invoice %s', $secret, $invoice->invoice ? $invoice->invoice : 'is null');
        Utils::send_json($message, 400);
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
            $invoice->setMeta('order-status', $new_status);
        }

        if($saved_status == $current_status && $saved_status != $new_status) {
            $order->update_status('wc-' . $new_status);
            $invoice->setMeta('order-status', $new_status);
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

    public function render_handler()
    {
        if (Render::isAjaxRequest()) {
            $data = file_get_contents('php://input');
            $params = ($data) ? json_decode(Utils::sanitize($data)) : null;

            if ($params) {
                $id = property_exists($params, 'invoice') ? (string) $params->invoice : '';
                $offset = property_exists($params, 'offset') ? (int) $params->offset : null;
                header("Content-Type: text/plain");
                $invoice = Invoice::getInvoice($id);
                if ($invoice->details->isExpired()) {
                    $invoice->update();
                }
                if ($offset === null) {
                    echo $invoice->id ? $invoice->details->statusNum() : 0;
                    exit;
                }
                Render::setTimeZoneByOffset($offset);
                Render::$logo = Invoice::$settings->logo;
                echo $invoice->render();
                $order = new WC_Order($invoice->order);

                if ($invoice->status == 'expired' && $order->get_status() === 'pending') {
                    WC_MCCP::order_status_update($invoice, $order);
                }
                if ($invoice->status == 'expired' && $order->get_status() === 'failed') {
                    wc_get_template( 'checkout/thankyou.php', array( 'order' => $order ) );
                }
            }
            exit;
        }
        echo 0;
        exit;
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
}
