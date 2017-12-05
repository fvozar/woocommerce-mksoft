<?php
/**
 * WooCommerce MKSoft.
 *
 * @package   WooCommerce MKSoft
 * @author    Ing. Filip Vozár <filip.vozar@gmail.com>
 * @license   GPL-2.0+
 * @link      https://filipvozar.eu
 * @copyright 2017 Ing. Filip Vozár
 */

/**
 * WC_MKSoft.
 *
 * @package WooCommerce MKSoft
 * @author  Ing. Filip Vozár <filip.vozar@gmail.com>
 */
class WC_MKSoft
{

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   1.0.0
	 *
	 * @var     string
	 */
	protected $version = '1.0.0';

	/**
	 * Unique identifier for your plugin.
	 *
	 * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should
	 * match the Text Domain file header in the main plugin file.
	 *
	 * @since    1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'wc-mksoft';

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance;

	/**
	 * Slug of the plugin screen.
	 *
	 * @since    1.0.0
	 *
	 * @var      string
	 */
	protected $plugin_screen_hook_suffix;


	/**
	 * MKSoft api client instance.
	 *
	 * @since   1.0.0
	 *
	 * @var     MKSoftApiClient
	 */
	protected $api_client;


	/**
	 * Initialize the plugin by setting localization, filters, and administration functions.
	 *
	 * @since     1.0.0
	 */
	private function __construct()
	{
		// Define custom functionality. Read more about actions and filters: http://codex.wordpress.org/Plugin_API#Hooks.2C_Actions_and_Filters
		add_action('init', [$this, 'init']);
		add_action('admin_init', [$this, 'admin_init']);
	}


	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance()
	{
		// If the single instance hasn't been set, set it now.
		if (null === self::$instance) {
			self::$instance = new self;
		}

		return self::$instance;
	}


	public function admin_init()
	{
		if (is_admin() && current_user_can('activate_plugins') && ! is_plugin_active('woocommerce/woocommerce.php')) {
			add_action('admin_notices', [$this, 'woocommerce_not_activated']);

			deactivate_plugins(__DIR__ . '/wc-mksoft.php');

			if (isset($_GET['activate'])) {
				unset($_GET['activate']);
			}
		}

		if ( ! get_option('woocommerce_mks_ftp_address') ||
		     ! get_option('woocommerce_mks_ftp_user') ||
		     ! get_option('woocommerce_mks_ftp_password')
		) {
			add_action('admin_notices', [$this, 'plugin_not_configured']);
		}
	}


	public function woocommerce_not_activated()
	{
		?>
		<div class="error">
			<p>MKSoft plugin vyžaduje mať nainštalovaný a aktivovaný WooCommerce plugin.</p>
		</div>
		<?php
	}


	public function plugin_not_configured()
	{
		?>
		<div class="error">
			<p>MKSoft plugin nie je správne nakonfigurovaný. Pokračujte prosím do <a
						href="<?php echo admin_url('admin.php?page=wc-settings&tab=wc_mksoft'); ?>">nastavení</a>.</p>
		</div>
		<?php
	}


	public function init()
	{
		add_action('woocommerce_settings_start', [$this, 'add_woo_settings']);
		add_filter('woocommerce_settings_tabs_array', [$this, 'add_woo_settings_tab'], 30);
		add_action('woocommerce_settings_tabs_wc_mksoft', [$this, 'add_woo_settings_tab_content']);
		add_action('woocommerce_settings_save_wc_mksoft', [$this, 'save_woo_settings_tab_content']);

		add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
		add_action('woocommerce_checkout_order_processed', [$this, 'mks_sync_order'], 5);
	}


	public function mks_api()
	{
		$mks_ftp_host = get_option('woocommerce_mks_ftp_address');
		$mks_ftp_user = get_option('woocommerce_mks_ftp_user');
		$mks_ftp_pass = get_option('woocommerce_mks_ftp_password');

		return new MKSoftApiClient($mks_ftp_host, $mks_ftp_user, $mks_ftp_pass);
	}


	/**
	 * @param int $order_id
	 */
	public function mks_sync_order($order_id)
	{
		$order = wc_get_order($order_id);
		$this->do_order_sync($order);
	}


	private function do_order_sync(WC_Order $order)
	{
		$orderFileName = "obj{$order->get_id()}.txt";
		$payload       = $this->createOrderPayload($order);

		$api = $this->get_api_client();

		try {
			$api->sync_order($orderFileName, $payload->saveXML());
			update_post_meta($order->get_id(), 'wc_mks_is_uploaded', __('Áno', 'wc-mksoft'));
		} catch (RuntimeException $e) {
			update_post_meta($order->get_id(), 'wc_mks_is_uploaded', __('Chyba, skontrolujte nastavenia', 'wc-mksoft'));
		}
	}


	private function createOrderPayload(WC_Order $order)
	{
		$mks_export_description = get_option('woocommerce_mks_export_description', get_option('blogname', ''));
		$mks_order_code         = get_option('woocommerce_mks_order_code', __('OBJ', 'wc-mksoft'));
		$mks_confirm_order      = get_option('woocommerce_mks_confirm_order');
		$mks_price_type         = get_option('woocommerce_mks_price_type', 2);

		$payload = simplexml_load_string(file_get_contents(__DIR__ . '/order-template.xml'));

		$payload->attributes()->popis = $mks_export_description;
		if ($mks_confirm_order) {
			$payload->attributes()->kontakt = $order->get_billing_email();
		}

		$customerData         = &$payload->firma;
		$customerData->nazov1 = "{$order->get_billing_first_name()} {$order->get_billing_last_name()}";
		$customerData->nazov2 = $order->get_billing_company();
		$customerData->ulica  = $order->get_billing_address_1();
		$customerData->ulica2 = $order->get_billing_address_2();
		$customerData->psc    = $order->get_billing_postcode();
		$customerData->obec   = $order->get_billing_city();
		$customerData->stat   = $order->get_billing_state();
		$customerData->mobil  = $order->get_billing_phone();
		$customerData->email  = $order->get_billing_email();

		$orderData           = &$payload->objednavka;
		$orderData->typ      = $mks_order_code;
		$orderData->datum    = $order->get_date_created()->date('d.m.Y');
		$orderData->typceny  = $mks_price_type;
		$orderData->poznamka = $order->get_customer_note();

		/** @var WC_Order_Item $item */
		foreach ($order->get_items() as $item) {
			/** @var WC_Product $product */
			$product   = $item->get_product();
			$orderItem = $orderData->addChild('pohyb');
			$orderItem->addChild('nazov', $item->get_name());
			$orderItem->addChild('oznacenie', $product->get_sku());
			$orderItem->addChild('pocet', $item->get_quantity());
			$orderItem->addChild('cena', $product->get_price());
		}

		return $payload;
	}


	/**
	 * Add MKSoft setting fields.
	 *
	 * @since    1.0.0
	 */
	public function get_settings()
	{
		$invoice_settings = [
			[
				'title' => __('Údaje o pripojení k MKSoft', 'wc-mksoft'),
				'type'  => 'title',
				'desc'  => 'Nastavenia pre pripojenie k účtovnému softvéru MKSoft',
				'id'    => 'woocommerce_mks_ftp',
			],
			[
				'title' => __('FTP účet', 'wc-mksoft'),
				'type'  => 'title',
				'desc'  => '',
				'id'    => 'woocommerce_mks_invoice_title1',
			],
			[
				'title' => __('FTP adresa', 'wc-mksoft'),
				'id'    => 'woocommerce_mks_ftp_address',
				'desc'  => '',
				'class' => 'input-text regular-input',
				'type'  => 'text',
			],
			[
				'title' => __('FTP používateľ', 'wc-mksoft'),
				'id'    => 'woocommerce_mks_ftp_user',
				'desc'  => '',
				'class' => 'input-text regular-input',
				'type'  => 'text',
			],
			[
				'title' => __('FTP heslo', 'wc-mksoft'),
				'id'    => 'woocommerce_mks_ftp_password',
				'desc'  => '',
				'class' => 'input-text regular-input',
				'type'  => 'password',
			],
			[
				'type' => 'sectionend',
				'id'   => 'woocommerce_mks_ftp',
			],
			[
				'title' => __('Export objednávky', 'wc-mksoft'),
				'type'  => 'title',
				'desc'  => 'Nastavenia pre export objednávky do účtovného programu MKSoft',
				'id'    => 'woocommerce_mks_export',
			],
			[
				'title' => __('Popis exportu', 'wc-mksoft'),
				'id'    => 'woocommerce_mks_export_description',
				'desc'  => '',
				'class' => 'input-text regular-input',
				'type'  => 'text',
			],
			[
				'title' => __('Kód objednávky', 'wc-mksoft'),
				'id'    => 'woocommerce_mks_order_code',
				'desc'  => '',
				'class' => 'input-text regular-input',
				'type'  => 'text',
			],
			[
				'title'   => __('Typ ceny pre export', 'wc-mksoft'),
				'id'      => 'woocommerce_mks_price_type',
				'default' => '2',
				'type'    => 'select',
				'options' => [
					'2' => __('Ceny sú s DPH', 'wc-mksoft'),
					'0' => __('Ceny sú bez DPH', 'wc-mksoft'),
				],
			],
			[
				'title'   => __('Kontakt na klienta', 'wc-mksoft'),
				'id'      => 'woocommerce_mks_confirm_order',
				'desc'    => __('Zahrnúť kontakt na klienta pre zaslanie potvrdenia objednávky cez MKSoft',
					'wc-mksoft'),
				'default' => 'no',
				'type'    => 'checkbox',
			],
			[
				'type' => 'sectionend',
				'id'   => 'woocommerce_mks_export',
			],
		];

		return $invoice_settings;
	}


	public function add_woo_settings()
	{
		global $woocommerce_settings;
		$woocommerce_settings['wc_mksoft'] = apply_filters('woocommerce_wc_mksoft_settings', $this->get_settings());
	}


	/**
	 * Create MKSoft tab on Woocommerce settings page.
	 *
	 * @since    1.0.0
	 */
	public function add_woo_settings_tab($tabs)
	{
		$tabs['wc_mksoft'] = __('MKSoft', 'wc-mksoft');

		return $tabs;
	}


	public function save_woo_settings_tab_content()
	{
		if (class_exists('WC_Admin_Settings')) {
			global $woocommerce_settings;
			$woocommerce_settings['wc_mksoft'] = apply_filters('woocommerce_wc_mksoft_settings', $this->get_settings());
			WC_Admin_Settings::save_fields($woocommerce_settings['wc_mksoft']);
		}
	}


	/**
	 * Display MKSoft setting fields.
	 *
	 * @since    1.0.0
	 */
	public function add_woo_settings_tab_content()
	{
		global $woocommerce_settings;
		echo '<p>Máte s modulom technický problém? Napíšte mi na <a href="mailto:filip.vozar@gmail.com">filip.vozar@gmail.com</a></p>';
		woocommerce_admin_fields($woocommerce_settings['wc_mksoft']);
	}


	public function add_meta_boxes()
	{
		add_meta_box('wc_mks_sync_box', __('MK soft', 'wc-mksoft'), [$this, 'add_box'], 'shop_order', 'side');
	}


	public function add_box($post)
	{
		echo '<p>' . __('Objednávka evidovaná', 'wc-mksoft') . ': ';

		$isUploaded = get_post_meta($post->ID, 'wc_mks_is_uploaded', true);
		$text       = $isUploaded ? 'Áno' : 'Nie';
		echo "<strong>{$text}</strong>";
		echo '</p>';
	}


	/**
	 * @param $string
	 *
	 * @return string
	 */
	public function convert_to_plaintext($string)
	{
		return html_entity_decode(wp_strip_all_tags($string), ENT_QUOTES, get_option('blog_charset'));
	}


	/**
	 * @return MKSoftApiClient
	 */
	private function get_api_client()
	{
		if (null === $this->api_client) {
			$this->api_client = $this->mks_api();
		}

		return $this->api_client;
	}
}
