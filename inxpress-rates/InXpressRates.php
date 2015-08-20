<?php
/**
 * InXpress Rates
 *
 * Uses the InXpress API to get live shipping rates based on product weight and dimensions
 *
 * INSTALLATION INSTRUCTIONS
 * Upload the inxpress-rates folder with InXpressRates.php to your WordPress install under: wp-content/shopp/shipping
 *
 * @author Pixel by Proxy
 * @copyright 2015 Pixel by Proxy
 * @package shopp
 * @version 1.0
 * @since 1.2
 * @subpackage InXpressRates
**/

class ShoppInXpressRates extends ShippingFramework implements ShippingModule {

	const SLUGNAME = 'InXpress';
	const APIURL = 'http://www.ixpapi.com/ixpapp/rates.php?acc=%s&dst=%s&pst=%s&prd=P&wgt=%u&pcs=%u|%u|%u|%u';

	public $dimensions = true;
	public $weight = 0;

	public $xml = true;		// Requires the XML parser
	public $postcode = true;	// Requires a postal code for rates
	public $singular = true;	// Module can only be used once
	public $realtime = true;	// Provides real-time rates

	public function __construct () {
		parent::__construct();
		
		$this->setup('inxpressid');

		add_action('shipping_service_settings',      array($this, 'settings'));
		add_action('shopp_verify_shipping_services', array($this, 'verify'));
	}

	public function init () {
		$this->weight = 0;
	}

	public function calcitem ( $id, $Item ) {
		if ( $Item->freeshipping ) return;
		$this->packager->add_item($Item);
	}

	public function methods () {
		return 'InXpress Service Rates';
	}

	public function calculate ( &$options, $Order ) {
		// Don't get an estimate without a postal code
		// Only show for international orders
		if ( ! $this->international() || empty($Order->Shipping->postcode) ) return $options;

		// remove the spaces from the post code
		$postcode = str_replace(' ', '', $Order->Shipping->postcode);
		if ( empty($postcode) ) return $options;

		$total = 0.0;

		// Iterate over each package
		while ( $this->packager->packages() ) {

			$pkg = $this->packager->package();
			$url = $this->build($pkg, $postcode, $Order->Shipping->country);
			$Response = $this->send($url);

			if ( ! $Response) {
				new ShoppError('Shipping options and rates are not available from InXpress. Please try again.', 'inxpress_rate_error', SHOPP_TRXN_ERR);
				return false;
			}

			if ( $Response->tag('errorResponse') ) {
				$errors = (array)$Response->content('message');
				new ShoppError('InXpress &mdash; '.$errors[0],'inxpress_rate_error',SHOPP_TRXN_ERR);
				return false;
			}

			if ( !$Response->tag('ratingResponse') ) {
				new ShoppError('Unable to get the shipping cost for InXpress. Please try again.', 'inxpress_rate_error', SHOPP_TRXN_ERR);
				return false;
			}

			$total += doubleval($Response->content('totalCharge'));

		} // end while ( $Package = $Packages->each() )

		$rate = array(
			'slug' => self::SLUGNAME,
			'name' => $this->settings['servicetype'],
			'amount' => $total
		);

		$options[ self::SLUGNAME ] = new ShippingOption($rate);

		return $options;

	}

	public function build ( $pkg, $country, $postcode ) {

		$expresscode = $this->settings['inxpressid'];
		$width = $this->size( $pkg->width(), 'width' );
		$height = $this->size( $pkg->height(), 'height' );
		$length = $this->size( $pkg->length(), 'length' );

		$pounds = $ounces = 0;
		list($pounds, $ounces) = $this->size($pkg->weight(), 'weight');

		$url = sprintf(self::APIURL, $expresscode, $postcode, $country, $pounds, $length, $width, $height, $pounds);
		
		return $url;
	}

	public function size ( $value = 0, $size='weight' ) {
		if ( ! isset($this->sizes[ $size ]) ) return $value;

		$dimension = convert_unit($value, $this->sizes[ $size ]['unit']);

		$method = "size$size";
		if ( method_exists($this, $method) ) $dimension = $this->$method($dimension);
		else $dimension = $this->sized($dimension, $size);

		return $dimension;
	}

	public function sized ( $value, $size ) {
		if ( ! isset($this->sizes[ $size ]) ) return $value;

		$value = (float)$value;

		if ($value < $this->sizes[ $size ]['min']) $value = (float)$this->sizes[ $size ]['min'];

		return ceil($value);
	}

	public function sizeweight ( $value ) {
		$value = (float)$value;

		if ($value < $this->sizes['weight']['min']) $value = (float)$this->sizes['weight']['min'];

		$pounds = intval($value);
		$ounces = ceil( ($value - $pounds) * 16 );

		return array($pounds, $ounces);
	}

	public function international () { // @todo Move to framework
		return ( substr(ShoppOrder()->Shipping->country, 0, 2) != $this->base['country'] );
	}

	public function verify () {
		if ( ! $this->activated() ) return;

		$expresscode = $this->settings['inxpressid'];
		$width = 12;
		$height = 12;
		$length = 12;
		$pounds = 12;

		$url = sprintf(self::APIURL, $expresscode, $postcode, $country, $pounds, $length, $width, $height, $pounds);
		$Response = $this->send($url);
		if ( $Response->tag('errorResponse') ) {
			$errors = $Response->content('message');
			new ShoppError(join(' ', $errors), 'inxpress_verify_auth', SHOPP_ADDON_ERR);
		}
	}

	public function send ( $url ) {
		$response = parent::send("" ,$url);
		if ( empty($response) ) return false;
		return new xmlQuery($response);
	}

	public function settings () {

		$this->ui->text(0, array(
			'name' => 'inxpressid',
			'value' => $this->settings['inxpressid'],
			'size' => 16,
			'label' => 'InXpress User ID'
		));

		$this->ui->text(1, array(
			'name' => 'servicetype',
			'value' => $this->settings['servicetype'],
			'size' => 16,
			'label' => 'Service Type'
		));

	}

	public function logo () {
		return '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';
	}

}