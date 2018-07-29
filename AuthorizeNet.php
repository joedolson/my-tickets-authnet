<?php
/**
 * My Tickets: Authorize.net payment gateway
 *
 * @package     My Tickets: Authorize.net
 * @author      Joe Dolson
 * @copyright   2014-2018 Joe Dolson
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: My Tickets: Authorize.net
 * Plugin URI: http://www.joedolson.com/my-tickets/add-ons/
 * Description: Add support for the Authorize.net payment gateway to My Tickets.
 * Author: Joseph C Dolson
 * Author URI: http://www.joedolson.com
 * Text Domain: my-tickets-authnet
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/license/gpl-2.0.txt
 * Domain Path: lang
 * Version:     1.2.0
 */

/*
	Copyright 2014-2018  Joe Dolson (email : joe@joedolson.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

global $amt_version;
$amt_version = '1.2.0';

load_plugin_textdomain( 'my-tickets-authnet', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

// The URL of the site with EDD installed.
define( 'EDD_MTA_STORE_URL', 'https://www.joedolson.com' );
// The title of your product in EDD and should match the download title in EDD exactly.
define( 'EDD_MTA_ITEM_NAME', 'My Tickets: Authorize.net' );

if ( ! class_exists( 'EDD_SL_Plugin_Updater' ) ) {
	// load our custom updater if it doesn't already exist.
	include( dirname( __FILE__ ) . '/updates/EDD_SL_Plugin_Updater.php' );
}

// retrieve our license key from the DB.
$license_key = trim( get_option( 'mta_license_key' ) );
// setup the updater.

if ( class_exists( 'EDD_SL_Plugin_Updater' ) ) { // prevent fatal error if doesn't exist for some reason.
	$edd_updater = new EDD_SL_Plugin_Updater( EDD_MTA_STORE_URL, __FILE__, array(
		'version'   => $amt_version,        // current version number
		'license'   => $license_key,        // license key (use above to retrieve from DB).
		'item_name' => EDD_MTA_ITEM_NAME,   // name of this plugin.
		'author'    => 'Joe Dolson',        // author of this plugin.
		'url'       => home_url(),
	) );
}

add_action( 'mt_receive_ipn', 'mt_authorizenet_ipn' );
/**
 * Attaches functionality to the response received from authorize.net
 *
 * Requires definition of a request parameter unique to this gateway.
 * Valid response calls mt_handle_payment() and passes the $response value, $response_code, payment $data, and $_REQUEST
 */
function mt_authorizenet_ipn() {
	if ( isset( $_REQUEST['mt_authnet_ipn'] ) && 'true' == $_REQUEST['mt_authnet_ipn'] ) {
		$options      = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$options      = array_walk_recursive( $options, 'trim' ); // if somebody has spaces in settings.
		$redirect_url = false;
		// all gateway data should be stored in the core My Tickets settings array.
		$api          = $options['mt_gateways']['authorizenet']['api'];
		// these all need to be set from Authorize.Net data.
		$required_array = array( 'x_response_code', 'x_item_number', 'x_amount', 'x_email', 'x_first_name', 'x_last_name' );
		foreach ( $required_array as $item ) {
			if ( ! isset( $_POST[ $item ] ) ) {
				return false;
			}
		}

		$payment_status   = mt_map_status( $_POST['x_response_code'] ); // map response to equivalent from Auth.net.
		$item_number      = $_POST['x_item_number'];
		$price            = $_POST['x_amount'];
		$payer_email      = $_POST['x_email']; // must add to form.
		$payer_first_name = $_POST['x_first_name'];
		$payer_last_name  = $_POST['x_last_name'];
		$phone            = $_POST['x_phone'];

		// map AuthNet format of address to MT format.
		$address = array(
			'street'  => isset( $_POST['x_ship_to_address'] ) ? $_POST['x_ship_to_address'] : '',
			'street2' => isset( $_POST['x_shipping_street2'] ) ? $_POST['x_shipping_street2'] : '',
			'city'    => isset( $_POST['x_ship_to_city'] ) ? $_POST['x_ship_to_city'] : '',
			'state'   => isset( $_POST['x_ship_to_state'] ) ? $_POST['x_ship_to_state'] : '',
			'country' => isset( $_POST['x_ship_to_country'] ) ? $_POST['x_ship_to_country'] : '',
			'code'    => isset( $_POST['x_ship_to_zip'] ) ? $_POST['x_ship_to_zip'] : ''
		);

		$billing = implode( ', ', array(
			'address' => $_POST['x_address'],
			'city'    => $_POST['x_city'],
			'state'   => $_POST['x_state'],
			'postal'  => $_POST['x_zip'],
			'country' => $_POST['x_country'],
		) );

		// authorizeNet IPN data.
		// This will only handle refunds; get refund data if sent. Model on Stripe.
		// check that price paid matches expected total.
		$value_match = mt_check_payment_amount( $price, $item_number );
		if ( ( $ipn->isAuthorizeNet() || ( true == $allow ) ) && $value_match ) {
			$receipt_id   = get_post_meta( $item_number, '_receipt', true );
			$redirect_url = get_permalink( $options['mt_purchase_page'] );
			if ( $ipn->approved ) {
				$response     = 'VERIFIED';
				$redirect_url = esc_url_raw( add_query_arg( array(
						'response_code'  => 'thanks',
						'transaction_id' => $ipn->transaction_id,
						'receipt_id'     => $receipt_id,
						'gateway'        => 'authorizenet',
						'payment'        => $item_number,
					), $redirect_url ) );
				$txn_id       = $ipn->transaction_id;
				// save data
			} else {
				$response     = 'FAILED';
				$redirect_url = esc_url_raw( add_query_arg( array(
						'response_code'        => $ipn->response_code,
						'response_reason_text' => urlencode( $ipn->response_reason_text ),
					), $redirect_url ) );
				$txn_id       = false;
			}
			$response_code = '200';
			$data          = array(
				'transaction_id' => $txn_id,
				'price'          => $price,
				'currency'       => $options['mt_currency'],
				'email'          => $payer_email,
				'first_name'     => $payer_first_name,
				'last_name'      => $payer_last_name,
				'status'         => $payment_status,
				'purchase_id'    => $item_number,
				'shipping'       => $address,
				'billing'        => $billing,
				'phone'          => $phone,
			);
			// use this filter to add custom data from your custom form fields.
			$data = apply_filters( 'mta_transaction_data', $data, $_POST );
			mt_handle_payment( $response, $response_code, $data, $_REQUEST );
		} else {
			wp_die( __( 'That transaction was not handled by Authorize.net.', 'my-tickets-authnet' ) );
		}
	}

	return;
}

add_filter( 'mt_setup_gateways', 'mt_setup_authnet', 10, 1 );
/**
 * Setup the Authorize.net gateway settings.
 *
 * @param array $gateways Existing gateways.
 *
 * @return array
 */
function mt_setup_authnet( $gateways ) {
	// this key is how the gateway will be referenced in all contexts.
	$gateways['authorizenet'] = array(
		'label'  => __( 'Authorize.net', 'my-tickets-authnet' ),
		'fields' => array(
			'api'  => __( 'API Login ID', 'my-tickets-authnet' ),
			'key'  => __( 'Transaction Key', 'my-tickets-authnet' ),
		),
	);

	return $gateways;
}

add_filter( 'mt_shipping_fields', 'mt_authnet_shipping_fields', 10, 2 );
/**
 * Process the shipping fields to match the Auth.net format.
 *
 * @param string $form Purchase form.
 * @param string $gateway Current gateway.
 *
 * @return string
 */
function mt_authnet_shipping_fields( $form, $gateway ) {
	if ( $gateway == 'authorizenet' ) {
		$search  = array(
			'mt_shipping_street',
			'mt_shipping_street2',
			'mt_shipping_city',
			'mt_shipping_state',
			'mt_shipping_country',
			'mt_shipping_code',
		);
		$replace = array(
			'x_ship_to_address',
			'x_shipping_street2',
			'x_ship_to_city',
			'x_ship_to_state',
			'x_ship_to_country',
			'x_ship_to_zip',
		);

		return str_replace( $search, $replace, $form );
	}

	return $form;
}

add_filter( 'mt_format_transaction', 'mt_authnet_transaction', 10, 2 );
/**
 * Customize transaction data from gateway.
 *
 * @param array  $transaction Transaction data.
 * @param string $gateway current gateway.
 *
 * @return array
 */
function mt_authnet_transaction( $transaction, $gateway ) {
	if ( $gateway == 'authorizenet' ) {
		// alter return value if desired.
	}

	return $transaction;
}

add_filter( 'mt_response_messages', 'mt_authnet_messages', 10, 2 );
/**
 * Feeds custom response messages to return page (cart)
 *
 * @param string $message Response from gateway.
 * @param string $code Coded response.
 *
 * @return string message.
 */
function mt_authnet_messages( $message, $code ) {
	if ( isset( $_GET['gateway'] ) && 'authorizenet' == $_GET['gateway'] ) {
		$options = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		if ( 1 == $code || 'thanks' == $code ) {
			$receipt_id     = $_GET['receipt_id'];
			$transaction_id = $_GET['transaction_id'];
			$receipt        = esc_url( add_query_arg( array( 'receipt_id' => $receipt_id ), get_permalink( $options['mt_receipt_page'] ) ) );
			// Translators: Transaction ID, URL to receipt.
			return sprintf( __( 'Thank you for your purchase! Your Authorize.net transaction id is: #%1$s. <a href="%2$s">View your receipt</a>', 'my-tickets-authnet' ), $transaction_id, $receipt );
		} else {
			// Translators: error message from Authorize.net.
			return sprintf( __( 'Sorry, an error occurred: %s', 'my-tickets-authnet' ), '<strong>' . esc_html( $_GET['response_reason_text'] ) . '</strong>' );
		}
	}

	return $message;
}

/*
 * Maps statuses returned by Authorize.net to the My Tickets status values
 *
 * @param int $status original status.
 *
 * @return string
*/
function mt_map_status( $status ) {
	switch ( $status ) {
		case 1:
			$response = 'Completed';
			break;
		case 2:
			$response = 'Failed';
			break;
		case 3:
			$response = 'Failed';
			break;
		case 4:
			$response = 'Pending';
			break;
		default:
			$response = 'Completed';
	}

	return $response;
}

add_filter( 'mt_gateway', 'mt_gateway_authorizenet', 10, 3 );
/**
 * Generates purchase form to be displayed under shopping cart confirmation.
 *
 * @param string $form Purchase form.
 * @param string $gateway name of gateway.
 * @param array $args data for current cart.
 *
 * @return updated form.
 */
function mt_gateway_authorizenet( $form, $gateway, $args ) {
	if ( 'authorizenet' == $gateway ) {
		$options        = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$url            = mt_replace_http( add_query_arg( 'mt_authnet_ipn', 'true', trailingslashit( home_url() ) ) );
		$payment_id     = $args['payment'];
		$amount         = $args['total'];
		$handling       = ( isset( $options['mt_handling'] ) ) ? $options['mt_handling'] : 0;
		$shipping       = ( 'postal' == $args['method'] ) ? $options['mt_shipping'] : 0;
		$total          = ( $amount + $handling + $shipping );
		$purchaser      = get_the_title( $payment_id );
		$form           = mt_authnet_form( $url, $payment_id, $total, $args );

		$form .= apply_filters( 'mt_authnet_form', '', $gateway, $args );
	}

	return $form;
}

add_action( 'mt_license_fields', 'mta_license_field' );
/**
 * Insert license key field onto license keys page.
 *
 * @param string $fields Existing fields.
 *
 * @return string
 */
function mta_license_field( $fields ) {
	$field  = 'mta_license_key';
	$active = ( 'valid' == get_option( 'mta_license_key_valid' ) ) ? ' <span class="license-activated">(active)</span>' : '';
	$name   =  __( 'My Tickets: Authorize.net', 'my-tickets-authnet' );
	return $fields . "
	<p class='license'>
		<label for='$field'>$name$active</label><br/>
		<input type='text' name='$field' id='$field' size='60' value='" . esc_attr( trim( get_option( $field ) ) ) . "' />
	</p>";
}

add_action( 'mt_save_license', 'mta_save_license', 10, 2 );
/**
 * Save license key.
 *
 * @param string $response Other message responses.
 * @param array  $post POST data.
 *
 * @return string
 */
function mta_save_license( $response, $post ) {
	$field  = 'mta_license_key';
	$name   =  __( 'My Tickets: Authorize.net', 'my-tickets-authnet' );
	$verify = mt_verify_key( $field, EDD_MTA_ITEM_NAME, EDD_MTA_STORE_URL );
	$verify = "<li>$verify</li>";

	return $response . $verify;
}

// these are existence checkers. Exist if licensed.
if ( 'true'== get_option( 'mta_license_key_valid' ) || 'valid' == get_option( 'mta_license_key_valid' ) ) {
	function mta_valid() {
		return true;
	}
} else {
	add_action( 'admin_notices', 'mta_licensed' );
}

/**
 * Display admin notice if license not provided.
 */
function mta_licensed() {
	global $current_screen;
	if ( stripos( $current_screen->id, 'my-tickets' ) ) {
		// Translators: Settings page URL.
		$message = sprintf( __( "Please <a href='%s'>enter your My Tickets: Authorize.net license key</a> to be eligible for support.", 'my-tickets-authnet' ), admin_url( 'admin.php?page=my-tickets' ) );
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		} else {
			echo "<div class='error'><p>$message</p></div>";
		}
	}
}

add_action( 'admin_notices', 'mta_requires_ssl' );
/**
 * Authorize.net only functions under SSL. Notify user that this is required.
 */
function mta_requires_ssl() {
	global $current_screen;
	if ( stripos( $current_screen->id, 'my-tickets' ) ) {
		if ( 0 === stripos( home_url(), 'https' ) ) {
			return;
		} else {
			echo "<div class='error'><p>" . __( 'Authorize.net requires an SSL Certificate. Please switch your site to HTTPS. <a href="https://websitesetup.org/http-to-https-wordpress/">How to switch WordPress to HTTPS</a>', 'my-tickets-authnet' ) . '</p></div>';
		}
	}
}


/**
 * Get currencies supported by gateway.
 */
function mt_authnet_supported() {
	return array( 'USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD' );
}

add_filter( 'mt_currencies', 'mt_authnet_currencies', 10, 1 );
/**
 * Parse currency information from base set.
 *
 * @param array $currencies All currencies.
 *
 * @return array
 */
function mt_authnet_currencies( $currencies ) {
	$options     = ( ! is_array( get_option( 'mt_settings' ) ) ) ? array() : get_option( 'mt_settings' );
	$defaults    = mt_default_settings();
	$options     = array_merge( $defaults, $options );
	$mt_gateways = $options['mt_gateway'];

	if ( is_array( $mt_gateways ) && in_array( 'authorizenet', $mt_gateways ) ) {
		$authnet = mt_authnet_supported();
		$return  = array();
		foreach ( $authnet as $currency ) {
			$keys = array_keys( $currencies );
			if ( in_array( $currency, $keys ) ) {
				$return[ $currency ] = $currencies[ $currency ];
			}
		}

		return $return;
	}

	return $currencies;
}



/**
 * Set up form for making a Authorize.net payment via AIM.
 *
 * @param string  $url $url to send query to. (Unused)
 * @param integer $payment_id ID for this payment.
 * @param float   $total Total amount of payment.
 * @param array   $args Payment arguments.
 *
 * @return string.
 */
function mt_authnet_form( $url, $payment_id, $total, $args ) {
	$options = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
	$year    = date( 'Y' );
	$years   = '';
	for( $i = 0; $i < 20; $i ++ ) {
		$years .= "<option value='$year'>$year</option>";
		$year ++;
	}
	$nonce = wp_create_nonce( 'my-tickets-authnet' );
	$form  = "
	<div class='payment-errors' aria-live='assertive'></div>
	<form action='' method='POST' id='my-tickets-authnet-payment-form'>
		<input type='hidden' name='_wp_authnet_nonce' value='$nonce' />
		<input type='hidden' name='_mt_action' value='authnet' />
		<div class='card section'>
		<fieldset>
			<legend>" . __( 'Credit Card Details', 'my-tickets-authnet' ) . "</legend>
			<div class='form-row'>
				<label for='mt-card-name'>" . __( 'Name on card', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="mt-card-name" size="20" autocomplete="cc-name" class="card-name" name="mt-card-name" />
			</div>
			<div class="form-row">
				<label for="mt-cc-number">' . __( 'Credit Card Number', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="mt-cc-number" size="20" autocomplete="cc-number" class="card-number cc-num" name="card-number" />
			</div>
			<div class="form-row">
				<label for="cvc">' . __( 'CVC', 'my-tickets-authnet' ) . '</label>
				<input type="text" size="4" autocomplete="off" class="card-cvc cc-cvc" name="card-cvc" id="cvc" />
			</div>
			<div class="form-row">
			<fieldset>
				<legend>' . __( 'Expiration (MM/YY)', 'my-tickets-authnet' ) . '</legend>
				<label for="expiry-month" class="screen-reader-text">' . __('Expiration month', 'my-tickets-authnet') . '</label>
				<select class="card-expiry-month" id="expiry-month" name="expiry-month">
					<option value="01">01</option>
					<option value="02">02</option>
					<option value="03">03</option>
					<option value="04">04</option>
					<option value="05">05</option>
					<option value="06">06</option>
					<option value="07">07</option>
					<option value="08">08</option>
					<option value="09">09</option>
					<option value="10">10</option>
					<option value="11">11</option>
					<option value="12">12</option>
				</select>
				<span> / </span>
				<label for="expiry-year" class="screen-reader-text">' . __( 'Expiration year', 'my-tickets-authnet' ) . '</label>
				<select class="card-expiry-year" id="expiry-year" name="expiry-year">
					' . $years . '
				</select>
			</fieldset>
			</div>
		</fieldset>
		</div>
		<div class="address section">
		<fieldset>
		<legend>' . __( 'Billing Address', 'my-tickets-authnet' ) . '</legend>
			<div class="form-row">
				<label for="address1">' . __( 'Address (1)', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="address1" name="card_address" class="card-address" />
			</div>
			<div class="form-row">
				<label for="address2">' . __( 'Address (2)', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="address2" name="card_address_2" class="card-address-2" />
			</div>
			<div class="form-row">
				<label for="card_city">' . __( 'City', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="card_city" name="card_city" class="card-city" />
			</div>
			<div class="form-row">
				<label for="card_zip">' . __( 'Zip / Postal Code', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="card_zip" name="card_zip" class="card-zip" />
			</div>
			<div class="form-row">
				<label for="card_country">' . __( 'Country', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="card_country" name="card_country" class="card-country" />
			</div>
			<div class="form-row">
				<label for="card_state">' . __( 'State', 'my-tickets-authnet' ) . '</label>
				<input type="text" id="card_state" name="card_state" class="card-state" />
			</div>
		</fieldset>
		</div>';
	$form .= "<input type='hidden' name='payment_id' value='" . esc_attr( $payment_id ) . "' />
	<input type='hidden' name='amount' value='$total' />";
	$form .= mt_render_field( 'address', 'authnet' );
	$form .= "<input type='submit' name='authnet_submit' id='mt-authnet-submit' class='button' value='" . esc_attr( apply_filters( 'mt_gateway_button_text', __( 'Pay Now', 'my-tickets' ), 'authnet' ) ) . "' />";
	$form .= apply_filters( 'mt_authnet_form', '', 'authnet', $args );
	$form .= '</form>';

	return $form;
}

add_action( 'init', 'my_tickets_authnet_process_payment' );
/**
 * Handle processing of payment.
 */
function my_tickets_authnet_process_payment() {
	if ( isset( $_POST['_mt_action']) && 'authnet' == $_POST['_mt_action'] && wp_verify_nonce( $_POST['_wp_authnet_nonce'], 'my-tickets-authnet' ) ) {

		// Call this only when needed.
		require_once( dirname( __FILE__ ) . '/includes/anet_php_sdk/AuthorizeNet.php' );

		$options         = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$authnet_options = $options['mt_gateways']['authorizenet'];
		$purchase_page   = get_permalink( $options['mt_purchase_page'] );
		$transaction     = new AuthorizeNetAIM( $authnet_options['api'], $authnet_options['key'] );
		// check if we are using test mode.
		if ( isset( $options['mt_use_sandbox'] ) && 'true' == $options['mt_use_sandbox'] ) {
			$transaction->setSandbox( true );
		} else {
			$transaction->setSandbox( false );
		}

		$payment_id  = $_POST['payment_id'];
		$payer_email = get_post_meta( $payment_id, '_email', true );
		$paid        = get_post_meta( $payment_id, '_total_paid', true );
		$payer_name  = get_the_title( $payment_id );
		$names       = explode( ' ', $payer_name );
		$first_name  = array_shift( $names );
		$last_name   = implode( ' ', $names );
		$passed      = $_POST['amount'];
		$address     = array();
		// compare amounts from payment and from passage.
		if ( $paid != $passed ) {
			$redirect = mt_replace_http( esc_url_raw( add_query_arg( array(
					'response_code' => 'failed',
					'gateway'       => 'authnet',
					'payment_id'    => $payment_id,
					'reason'        => urlencode( __( 'The purchase amount on this sale was changed in an invalid manner.', 'my-tickets-authnet' ) ),
				), $purchase_page ) ) );
			wp_safe_redirect( $redirect );
			// probably fraudulent: user attempted to change the amount paid. Raise fraud flag?
		}
		// Set transaction values.
		$transaction->amount      = $paid;
		$transaction->card_num    = strip_tags( trim( $_POST['card-number'] ) );
		$transaction->card_code   = strip_tags( trim( $_POST['card-cvc'] ) );
		$transaction->exp_date    = strip_tags( trim( $_POST['expiry-month'] ) ) . '/' . strip_tags( trim( $_POST['expiry-year'] ) );
		// Translators: Blog name.
		$transaction->description = sprintf( __( '%s - Ticket Order', 'my-tickets' ), get_option( 'blogname' ) );
		$name                     = $_POST['mt-card-name'];
		$names                    = explode( ' ', $name );
		$f_name                   = array_shift( $names );
		$l_name                   = implode( ' ', $names );
		$transaction->first_name  = $f_name;
		$transaction->last_name   = $l_name;
		$transaction->address     = $card_info['card_address'] . ' ' . $card_info['card_address_2'];
		$transaction->city        = $card_info['card_city'];
		$transaction->country     = $card_info['card_country'];
		$transaction->state       = $card_info['card_state'];
		$transaction->zip         = $card_info['card_zip'];
		$transaction->customer_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$transaction->email       = $payer_email;
		$transaction->invoice_num = $payment_id;
		// attempt to charge the customer's card.
		try {
			// Charge the card using Authorize.net.
			$response   = $transaction->authorizeAndCapture();
			$receipt_id = get_post_meta( $payment_id, '_receipt', true ); 
			// Get shipping adress information and map to MT format.
			if ( isset( $_POST['mt_shipping_street'] ) ) {
				$address = array(
					'street'  => isset( $_POST['mt_shipping_street'] ) ? $_POST['mt_shipping_street'] : '',
					'street2' => isset( $_POST['mt_shipping_street2'] ) ? $_POST['mt_shipping_street2'] : '',
					'city'    => isset( $_POST['mt_shipping_city'] ) ? $_POST['mt_shipping_city'] : '',
					'state'   => isset( $_POST['mt_shipping_state'] ) ? $_POST['mt_shipping_state'] : '',
					'country' => isset( $_POST['mt_shipping_code'] ) ? $_POST['mt_shipping_code'] : '',
					'code'    => isset( $_POST['mt_shipping_country'] ) ? $_POST['mt_shipping_country'] : '',
				);
			}
			if ( $response->approved ) {
				$payment_status = 'Completed';
				$redirect       = mt_replace_http( esc_url_raw( add_query_arg( array(
					'response_code'  => 'thanks',
					'gateway'        => 'authnet',
					'transaction_id' => $transaction_id,
					'receipt_id'     => $receipt_id,
					'payment_id'     => $payment_id,
				), $purchase_page ) ) );
				$status = 'VERIFIED';
			} else {
				// Handle failure case.
				$status         = 'Pending';
				$message        = $response->response_reason_text;
				$payment_status = 'Failed';
				// redirect on failed payment.
				$redirect = mt_replace_http( esc_url_raw( add_query_arg( array(
					'response_code' => 'failed',
					'gateway'       => 'authnet',
					'payment_id'    => $payment_id,
					'reason'        => urlencode( $message ),
				), $purchase_page ) ) );
			}

		} catch ( Exception $e ) {
			// Handle exception.
			$message        = $e->response_reason_text;
			$payment_status = 'Failed';
			// redirect on failed payment.
			$redirect = mt_replace_http( esc_url_raw( add_query_arg( array(
				'response_code' => 'failed',
				'gateway'       => 'authnet',
				'payment_id'    => $payment_id,
				'reason'        => urlencode( $message ),
			), $purchase_page ) ) );
		}

		$data  = array(
			'transaction_id' => $transaction_id,
			'price'          => $amount,
			'currency'       => $options['mt_currency'],
			'email'          => $payer_email,
			'first_name'     => $f_name, // get from charge info.
			'last_name'      => $l_name, // get from charge info.
			'status'         => $payment_status,
			'purchase_id'    => $payment_id,
			'shipping'       => $address,
		);

		mt_handle_payment( 'VERIFIED', '200', $data, $_REQUEST );

		// redirect back to our previous page with the added query variable.
		wp_redirect( $redirect );
		exit;
	}
}

/**
 * Set cURL to use SSL version supporting TLS 1.2
 *
 * @param object $handle CURL object.
 */
function mta_http_api_curl( $handle ) {
	curl_setopt( $handle, CURLOPT_SSLVERSION, 6 );
}
add_action( 'http_api_curl', 'mta_http_api_curl' );
