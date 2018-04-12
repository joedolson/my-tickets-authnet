<?php
/*
Plugin Name: My Tickets: Authorize.net
Plugin URI: http://www.joedolson.com/
Description: Add support for the Authorize.net payment gateway to My Tickets.
Author: Joseph C Dolson
Author URI: http://www.joedolson.com/my-tickets-authnet/authorizenet
Version: 1.1.3
*/
/*  Copyright 2014-2018  Joe Dolson (email : joe@joedolson.com)

    This program is open source software; you can redistribute it and/or modify
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
$amt_version = '1.1.3';

load_plugin_textdomain( 'my-tickets-authnet', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

// The URL of the site with EDD installed.
define( 'EDD_MTA_STORE_URL', 'https://www.joedolson.com' );
// The title of your product in EDD and should match the download title in EDD exactly.
define( 'EDD_MTA_ITEM_NAME', 'My Tickets: Authorize.net' );

if ( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
	// load our custom updater if it doesn't already exist.
	include( dirname( __FILE__ ) . '/updates/EDD_SL_Plugin_Updater.php' );
}

// retrieve our license key from the DB.
$license_key = trim( get_option( 'mta_license_key' ) );
// setup the updater.

if ( class_exists( 'EDD_SL_Plugin_Updater' ) ) { // prevent fatal error if doesn't exist for some reason.
	$edd_updater = new EDD_SL_Plugin_Updater( EDD_MTA_STORE_URL, __FILE__, array(
		'version'   => $amt_version,		// current version number
		'license'   => $license_key,		// license key (used get_option above to retrieve from DB).
		'item_name' => EDD_MTA_ITEM_NAME,	// name of this plugin.
		'author'    => 'Joe Dolson',		// author of this plugin.
		'url'       => home_url(),
	) );
}
/**
 *
 * @package AuthorizeNet
 */
require_once( 'lib/shared/AuthorizeNetRequest.php' );
require_once( 'lib/shared/AuthorizeNetTypes.php' );
require_once( 'lib/shared/AuthorizeNetXMLResponse.php' );
require_once( 'lib/shared/AuthorizeNetResponse.php' );
require_once( 'lib/AuthorizeNetAIM.php' );
require_once( 'lib/AuthorizeNetARB.php' );
require_once( 'lib/AuthorizeNetCIM.php' );
require_once( 'lib/AuthorizeNetSIM.php' );
require_once( 'lib/AuthorizeNetDPM.php' );
require_once( 'lib/AuthorizeNetTD.php' );

if ( ! class_exists( "SoapClient" ) ) {
	require_once( 'lib/AuthorizeNetSOAP.php' );
}

add_action( 'mt_receive_ipn', 'mt_authorizenet_ipn' );
/**
 * Attaches functionality to the response received from authorize.net
 *
 * Requires definition of a request parameter unique to this gateway.
 * Valid response calls mt_handle_payment() and passes the $response value, $response_code, payment $data, and $_REQUEST
 */
function mt_authorizenet_ipn() {
	if ( isset( $_REQUEST['mt_authnet_ipn'] ) && $_REQUEST['mt_authnet_ipn'] == 'true' ) {
		$options      = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$options      = array_walk_recursive( $options, 'trim' ); // if somebody has spaces in settings.
		$redirect_url = false;
		// all gateway data should be stored in the core My Tickets settings array.
		$api          = $options['mt_gateways']['authorizenet']['api'];
		$hash         = $options['mt_gateways']['authorizenet']['hash'];
		// these all need to be set from Authorize.Net data.
		$required_array = array( 'x_response_code', 'x_item_number', 'x_amount', 'x_email', 'x_first_name', 'x_last_name' );
		foreach ( $required_array as $item ) {
			if ( ! isset( $_POST[$item] ) ) {
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
		$ipn = new mt_AuthorizeNetSIM( $api, $hash );
		// in sandbox mode, isAuthorizeNet always returns false.
		$allow = ( 'true' == $options['mt_use_sandbox'] ) ? true : false;
		// check that price paid matches expected total.
		$value_match = mt_check_payment_amount( $price, $item_number );
		if ( ( $ipn->isAuthorizeNet() || ( $allow == true ) ) && $value_match ) {
			$receipt_id   = get_post_meta( $item_number, '_receipt', true );
			$redirect_url = get_permalink( $options['mt_purchase_page'] );
			if ( $ipn->approved ) {
				$response     = 'VERIFIED';
				$redirect_url = esc_url_raw( add_query_arg( array(
						'response_code'  => 'thanks',
						'transaction_id' => $ipn->transaction_id,
						'receipt_id'     => $receipt_id,
						'gateway'        => 'authorizenet',
						'payment'        => $item_number
					), $redirect_url ) );
				$txn_id       = $ipn->transaction_id;
				// save data
			} else {
				$response     = 'FAILED';
				$redirect_url = esc_url_raw( add_query_arg( array(
						'response_code'        => $ipn->response_code,
						'response_reason_text' => urlencode( $ipn->response_reason_text )
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
				'phone'          => $phone
			);
			// use this filter to add custom data from your custom form fields
			$data = apply_filters( 'mta_transaction_data', $data, $_POST );
			mt_handle_payment( $response, $response_code, $data, $_REQUEST );
		} else {
			wp_die( __( 'That transaction was not handled by Authorize.net.', 'my-tickets-authnet' ) );
		}
		if ( $redirect_url ) {
			echo mt_AuthorizeNetMyTickets::getRelayResponseSnippet( esc_url_raw( $redirect_url ) );
			//wp_safe_redirect( $redirect_url );
			die;
		}
	}

	return;
}

add_filter( 'mt_setup_gateways', 'mt_setup_authnet', 10, 1 );
function mt_setup_authnet( $gateways ) {
	// this key is how the gateway will be referenced in all contexts.
	$gateways['authorizenet'] = array(
		'label'  => __( 'Authorize.net', 'my-tickets-authnet' ),
		'fields' => array(
			'api'  => __( 'API Login ID', 'my-tickets-authnet' ),
			'key'  => __( 'Transaction Key', 'my-tickets-authnet' ),
			'hash' => __( 'MD5 Hash Value', 'my-tickets-authnet' ),
		),
	);

	return $gateways;
}

add_filter( 'mt_shipping_fields', 'mt_authnet_shipping_fields', 10, 2 );
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
 */
function mt_authnet_messages( $message, $code ) {
	if ( isset( $_GET['gateway'] ) && $_GET['gateway'] == 'authorizenet' ) {
		$options = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		if ( $code == 1 || $code == 'thanks' ) {
			$receipt_id     = sanitize_text_field( $_GET['receipt_id'] );
			$transaction_id = sanitize_text_field( $_GET['transaction_id'] );
			$receipt        = esc_url( add_query_arg( array( 'receipt_id' => $receipt_id ), get_permalink( $options['mt_receipt_page'] ) ) );

			return sprintf( __( 'Thank you for your purchase! Your Authorize.net transaction id is: #%1$s. <a href="%2$s">View your receipt</a>', 'my-tickets-authnet' ), $transaction_id, $receipt );
		} else {
			return sprintf( __( 'Sorry, an error occurred: %s', 'my-tickets-authnet' ), '<strong>' . sanitize_text_field( $_GET['response_reason_text'] ) . "</strong>" );
		}
	}

	return $message;
}

/*
 * Maps statuses returned by Authorize.net to the My Tickets status values
 *
 * @param int $status original status
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

/*
 * Generates purchase form to be displayed under shopping cart confirmation.
 *
 * @param string $form
 * @param string $gateway name of gateway
 * @param array $args data for current cart
 */
add_filter( 'mt_gateway', 'mt_gateway_authorizenet', 10, 3 );
function mt_gateway_authorizenet( $form, $gateway, $args ) {
	if ( $gateway == 'authorizenet' ) {
		$options        = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$payment_id     = $args['payment'];
		$shipping_price = ( $args['method'] == 'postal' ) ? $options['mt_shipping'] : 0;
		$handling       = ( isset( $options['mt_handling'] ) ) ? $options['mt_handling'] : 0;
		$total          = mt_money_format( '%i', $args['total'] + $shipping_price + $handling );
		$nonce          = wp_create_nonce( 'my-tickets-authnet-authorizenet' );

		$url  = mt_replace_http( add_query_arg( 'mt_authnet_ipn', 'true', trailingslashit( home_url() ) ) );
		$rand = time() . rand( 100000, 999999 );
		$form = mt_AuthorizeNetMyTickets::directPost( $url, $payment_id, $total, $rand, $nonce );
		/* This might be part of handling discount codes.
		if ( $discount == true && $discount_rate > 0 ) {
			$form .= "
			<input type='hidden' name='discount_rate' value='$discount_rate' />";
			if ( $quantity == 'true' ) {
				$form .= "
				<input type='hidden' name='discount_rate2' value='$discount_rate' />";
			}
		}
		*/
		$form .= apply_filters( 'mt_authnet_form', '', $gateway, $args );
	}

	return $form;
}


/**
 * Insert license key field onto license keys page.
 *
 * @param string $fields Existing fields.
 *
 * @return string
 */
add_action( 'mt_license_fields', 'mta_license_field' );
function mta_license_field( $fields ) {
	$field = 'mta_license_key';
	$active = ( get_option( 'mta_license_key_valid' ) == 'valid' ) ? ' <span class="license-activated">(active)</span>' : '';
	$name =  __( 'My Tickets: Authorize.net', 'my-tickets-authnet' );
	return $fields . "
	<p class='license'>
		<label for='$field'>$name$active</label><br/>
		<input type='text' name='$field' id='$field' size='60' value='".esc_attr( trim( get_option( $field ) ) )."' />
	</p>";
}

add_action( 'mt_save_license', 'mta_save_license', 10, 2 );
function mta_save_license( $response, $post ) {
	$field = 'mta_license_key';
	$name =  __( 'My Tickets: Authorize.net', 'my-tickets-authnet' );
	$verify = mt_verify_key( $field, EDD_MTA_ITEM_NAME, EDD_MTA_STORE_URL );
	$verify = "<li>$verify</li>";

	return $response . $verify;
}

// these are existence checkers. Exist if licensed.
if ( get_option( 'mta_license_key_valid' ) == 'true' || get_option( 'mta_license_key_valid' ) == 'valid' ) {
	function mta_valid() {
		return true;
	}
} else {
	$message = sprintf( __( "Please <a href='%s'>enter your My Tickets: Authorize.net license key</a> to be eligible for support.", 'my-tickets' ), admin_url( 'admin.php?page=my-tickets' ) );
	add_action( 'admin_notices', create_function( '', "if ( ! current_user_can( 'manage_options' ) ) { return; } else { echo \"<div class='error'><p>$message</p></div>\";}" ) );
}


function mt_authnet_supported() {
	return array(
		'USD', 'CAD', 'GBP', 'EUR', 'AUD', 'NZD'
	);
}

add_filter( 'mt_currencies', 'mt_authnet_currencies', 10, 1 );
function mt_authnet_currencies( $currencies ) {
	$options  = ( ! is_array( get_option( 'mt_settings' ) ) ) ? array() : get_option( 'mt_settings' );
	$defaults = mt_default_settings();
	$options  = array_merge( $defaults, $options );
	$mt_gateways = $options['mt_gateway'];

	if ( is_array( $mt_gateways ) && in_array( 'authorizenet', $mt_gateways ) ) {
		$authnet = mt_authnet_supported();
		$return = array();
		foreach ( $authnet as $currency ) {
			$keys = array_keys( $currencies );
			if ( in_array( $currency, $keys ) ) {
				$return[$currency] = $currencies[$currency];
			}
		}

		return $return;
	}

	return $currencies;
}


if ( ! function_exists( 'mt_zerodecimal_currency' ) ) {
	// if not up to date and this function doesn't exist, then My Tickets doesn't support zero decimal currencies.
	function mt_zerodecimal_currency() {
		return false;
	}
}
