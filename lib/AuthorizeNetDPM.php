<?php
/**
 * Demonstrates the Direct Post Method.
 *
 * To implement the Direct Post Method you need to implement 3 steps:
 *
 * Step 1: Add necessary hidden fields to your checkout form and make your form is set to post to AuthorizeNet.
 *
 * Step 2: Receive a response from AuthorizeNet, do your business logic, and return
 *         a relay response snippet with a url to redirect the customer to.
 *
 * Step 3: Show a receipt page to your customer.
 *
 * This class is more for demonstration purposes than actual production use.
 *
 *
 * @package    AuthorizeNet
 * @subpackage AuthorizeNetDPM
 */

/**
 * A class that demonstrates the DPM method.
 *
 * @package    AuthorizeNet
 * @subpackage AuthorizeNetDPM
 */
class mt_AuthorizeNetMyTickets extends mt_AuthorizeNetSIM_Form {

	const LIVE_URL = 'https://secure2.authorize.net/gateway/transact.dll';
	const SANDBOX_URL = 'https://test.authorize.net/gateway/transact.dll';

	/**
	 * Implements all 3 steps of the Direct Post Method
	 *
	 * @param string $url URL.
	 * @param integer $item_number Payment ID.
	 * @param float $price Total to charge.
	 * @param integer $rand ID.
	 * @param string $nonce Number used once.
	 *
	 * @return string
	 */
	public static function directPost( $url, $item_number, $price = '0.00', $rand = '', $nonce ) {
		$options = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$api     = trim( $options['mt_gateways']['authorizenet']['api'] );
		$key     = trim( $options['mt_gateways']['authorizenet']['key'] );
		// Step 1: Show checkout form to customer.
		$fp_sequence = $rand; // Any sequential number like an invoice number.
		return mt_AuthorizeNetMyTickets::getCreditCardForm( $price, $item_number, $fp_sequence, $url, $api, $key, $nonce );
	}

	/**
	 * A snippet to send to AuthorizeNet to redirect the user back to the
	 * merchant's server. Use this on your relay response page.
	 *
	 * @param string $redirect_url Where to redirect the user.
	 *
	 * @return string
	 */
	public static function getRelayResponseSnippet( $redirect_url ) {
		$return = "<html>
					<head>
						<script>
						<!--
						window.location=\"{$redirect_url}\";
						//-->
						</script>
						</head>
						<body>
						<noscript>
							<meta http-equiv='refresh' content='1;url=$redirect_url'>
						</noscript>
						<a href='$redirect_url'>" . __( 'Return to site', 'my-tickets-authnet' ) . '</a>
					</body>
				</html>';

		return $return;
	}

	/**
	 * Generate a sample form for use in a demo Direct Post implementation.
	 *
	 * @param string $price Amount of the transaction.
	 * @param string $fp_id Sequential number(ie. Invoice #)
	 * @param string $relay_url The Relay Response URL
	 * @param string $api_login_id Your API Login ID
	 * @param string $transaction_key Your API Tran Key.
	 * @param string $nonce WP Nonce
	 *
	 * @return string
	 */
	public static function getCreditCardForm( $price, $item_number, $fp_id, $relay_url, $api, $key, $nonce ) {
		$options       = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$test_mode     = ( 'true' == $options['mt_use_sandbox'] ) ? true : false;
		$prefill       = ( $test_mode ) ? true : false;
		$time          = time();
		$fp            = self::getFingerprint( $api, $key, $price, $fp_id, $time );
		$sim           = new mt_AuthorizeNetSIM_Form(
			array(
				'x_amount'         => $price,
				'x_fp_sequence'    => $fp_id,
				'x_fp_hash'        => $fp,
				'x_fp_timestamp'   => $time,
				'x_relay_response' => 'TRUE',
				'x_relay_url'      => $relay_url,
				'x_login'          => $api,
			)
		);
		$description    = apply_filters( 'mta_purchase_description', sprintf( __( 'Ticket Payment ID: %s', 'my-tickets-authnet' ), $item_number ), $item_number, $price );
		$hidden_fields  = $sim->getHiddenFieldString();
		$hidden_fields .= "<input type='hidden' name='x_referer_url' value='" . $relay_url . "' />";
		$hidden_fields .= '<input type="hidden" name="x_item_number" value="' . $item_number . '" />';
		$hidden_fields .= '<input type="hidden" name="x_description" value="' . $description . ' " />';
		$post_url = ( $test_mode ? self::SANDBOX_URL : self::LIVE_URL );
		$email    = ( isset( $_POST['mt_email'] ) ) ? esc_attr( $_POST['mt_email'] ) : '';
		$fname    = ( isset( $_POST['mt_fname'] ) ) ? esc_attr( stripslashes( $_POST['mt_fname'] ) ) : '';
		$lname    = ( isset( $_POST['mt_lname'] ) ) ? esc_attr( stripslashes( $_POST['mt_lname'] ) ) : '';
		$phone    = ( isset( $_POST['mt_phone'] ) ) ? esc_attr( stripslashes( $_POST['mt_phone'] ) ) : '';
		
		$current_user = wp_get_current_user();
		$address      = get_user_meta( $current_user->ID, '_mt_shipping_address', true );
		if ( $address ) {
			$street  = esc_attr( stripslashes( $address['street'] ) );
			$city    = esc_attr( stripslashes( $address['city'] ) );
			$state   = esc_attr( stripslashes( $address['state'] ) );
			$code    = esc_attr( stripslashes( $address['code'] ) );
			$country = esc_attr( stripslashes( $address['country'] ) );
		} else {
			$street  = '';
			$city    = '';
			$state   = '';
			$code    = '';
			$country = '';
		}
		$form = '
        <form method="post" action="' . $post_url . '" autocomplete="on" novalidate>
			<div>' . $hidden_fields . '</div>
			<fieldset class="mt-payment-details">
			<legend>' . __( 'Payment Details', 'my-tickets-authnet' ) . '</legend>
				<p>
					<label for="x_card_num">' . __( 'Credit Card Number', 'my-tickets-authnet' ) . '</label>
					<input type="text" pattern="\d*" autocomplete="cc-number" class="cc-num" required size="22" id="x_card_num" name="x_card_num" value="' . ( $prefill ? '6011000000000012' : '' ) . '" />
				</p>
				<p>
					<label for="x_exp_date">' . __( 'Expiration (mm/yy)', 'my-tickets-authnet' ) . '</label>
					<input type="text" autocomplete="cc-exp" required size="6" id="x_exp_date" name="x_exp_date" placeholder="05/' . date( 'y', strtotime( '+ 2 years' ) ) . '" value="' . ( $prefill ? '04/21' : '' ) . '" />
					
					<label for="x_card_code">' . __( 'Security Code', 'my-tickets-authnet' ) . '</label>
					<input type="number" autocomplete="off" class="cc-cvc" required size="5" id="x_card_code" name="x_card_code" placeholder="123" value="' . ( $prefill ? '782' : '' ) . '" />
				</p>
				<p>
					<label for="x_first_name">' . __( 'First Name', 'my-tickets-authnet' ) . '</label>
					<input type="text" required size="17" id="x_first_name" name="x_first_name" value="' . $fname . '" />
				</p>
				<p>
					<label for="x_last_name">' . __( 'Last Name', 'my-tickets-authnet' ) . '</label>
					<input type="text" required size="17" id="x_last_name" name="x_last_name" value="' . $lname . '" />
				</p>
				<p>
					<label for="x_email">' . __( 'Email', 'my-tickets-authnet' ) . '</label>
					<input type="email" required size="17" id="x_email" name="x_email" value="' . $email . '" />
				</p>
				<p>
					<label for="x_phone">' . __( 'Phone', 'my-tickets-authnet' ) . '</label>
					<input type="text" required size="17" id="x_phone" name="x_phone" value="' . $phone . '" />
				</p>' . 
					apply_filters( 'mta_custom_id_fields', '', $price, $item_number )
				. '
			</fieldset>
			<fieldset class="mt-billing-address">
				<legend>' . __( 'Billing Address', 'my-tickets-authnet' ) . '</legend>
				<p>
					<label for="x_address">' . __( 'Address', 'my-tickets-authnet' ) . '</label>
					<input type="text" required id="x_address" name="x_address" value="' . $street . '" />
				</p>
				<p>
					<label for="x_city">' . __( 'City', 'my-tickets-authnet' ) . '</label>
					<input type="text" required id="x_city" name="x_city" value="' . $city . '" />
				</p>
				<p>
					<label for="x_state">' . __( 'State/Province', 'my-tickets-authnet' ) . '</label>
					<input type="text" required id="x_state" name="x_state" value="' . $state . '" />
				</p>
				<p>
					<label for="x_zip">' . __( 'Postal Code', 'my-tickets-authnet' ) . '</label>
					<input type="text" required size="10" id="x_zip" name="x_zip" value="' . $code . '" />
				</p>
				<p>
					<label for="x_country">' . __( 'Country', 'my-tickets-authnet' ) . '</label>
					<input type="text" required id="x_country" name="x_country" value="' . $country . '" />
				</p>' . 
					apply_filters( 'mta_custom_address_fields', '', $price, $item_number )
				. '
			</fieldset>' .
		        mt_render_field( 'address', 'authorizenet' )
		        . '<input type="submit" name="submit" class="button" value="' . __( 'Pay with Authorize.net', 'my-tickets-authnet' ) . '" />';
		$form .= apply_filters( 'mt_authorizenet_form', '', $price );
		$form .= '</form>';

		return $form;
	}

}