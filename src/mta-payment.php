<?php
/**
 * My Tickets: AuthNet - payment processing.
 *
 * @category Functionality
 * @package  My Tickets: Authorize.net
 * @author   Joe Dolson
 * @license  GPLv3
 * @link     https://www.joedolson.com/my-tickets-authorizenet/
 */

require __DIR__ . '/vendor/autoload.php';
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

add_action( 'init', 'my_tickets_authnet_process_payment' );
/**
 * Handle processing of payment.
 */
function my_tickets_authnet_process_payment() {
	if ( isset( $_POST['_mt_action']) && 'authnet' == $_POST['_mt_action'] && wp_verify_nonce( $_POST['_wp_authnet_nonce'], 'my-tickets-authnet' ) ) {
		$options         = array_merge( mt_default_settings(), get_option( 'mt_settings' ) );
		$authnet_options = $options['mt_gateways']['authorizenet'];
		$purchase_page   = get_permalink( $options['mt_purchase_page'] );

		// create authentication connection.
		$authentication  = new AnetAPI\MerchantAuthenticationType();
		$authentication->setName( $authnet_options['api'] );
		$authentication->setTransactionKey( $authnet_options['key'] );

		$payment_id   = absint( $_POST['payment_id'] );
		$payer_email  = get_post_meta( $payment_id, '_email', true );
		$paid         = get_post_meta( $payment_id, '_total_paid', true );
		$payer_name   = get_the_title( $payment_id );
		$names        = explode( ' ', $payer_name );
		$passed       = sanitize_text_field( $_POST['amount'] );
		$ship_address = array();
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
		$card_num    = sanitize_text_field( trim( wp_unslash( $_POST['card-number'] ) ) );
		$card_code   = sanitize_text_field( trim( wp_unslash( $_POST['card-cvc'] ) ) );
		$exp_date    = sanitize_text_field( trim( wp_unslash( $_POST['expiry-month'] ) ) ) . '/' . sanitize_text_field( trim( wp_unslash( $_POST['expiry-year'] ) ) );
		// create payment data for card.
		$card = new AnetAPI\CreditCardType();
		$card->setCardNumber( str_replace( ' ', '', $card_num ) );
		$card->setExpirationDate( $exp_date );
		$card->setCardCode( $card_code );
		// Add the payment data to a paymentType object
		$payment = new AnetAPI\PaymentType();
		$payment->setCreditCard( $card );

		// Create order information
		$order = new AnetAPI\OrderType();
		$order->setInvoiceNumber( $payment_id );
		// Translators: Blog name.
		$description = sprintf( __( '%s - Ticket Order', 'my-tickets' ), get_option( 'blogname' ) );
		$order->setDescription($description );

		// Set the customer's Bill To address
		$address = new AnetAPI\CustomerAddressType();
		// collect data from submission.
		$name     = sanitize_text_field( wp_unslash( $_POST['mt-card-name'] ) );
		$names    = explode( ' ', $name );
		$f_name   = array_shift( $names );
		$l_name   = implode( ' ', $names );
		$address1 = isset( $_POST['card_address'] ) ? sanitize_text_field( wp_unslash( $_POST['card_address'] ) ) : '';
		$address2 = isset( $_POST['card_address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['card_address_2'] ) ) : '';
		$city     = isset( $_POST['card_city'] ) ? sanitize_text_field( wp_unslash( $_POST['card_city'] ) ) : '';
		$country  = isset( $_POST['card_country'] ) ? sanitize_text_field( wp_unslash( $_POST['card_country'] ) ) : '';
		$state    = isset( $_POST['card_state'] ) ? sanitize_text_field( wp_unslash( $_POST['card_state'] ) ) : '';
		$zip      = isset( $_POST['card_zip'] ) ? sanitize_text_field( wp_unslash( $_POST['card_zip'] ) ) : '';

		// Set customer's identifying information
		$customer = new AnetAPI\CustomerDataType();
		$customer->setType( "individual" );
		$customer->setEmail( $payer_email );

		$address->setFirstName( $f_name );
		$address->setLastName( $l_name );
		$address->setAddress( $address1 . ' ' . $address2 );
		$address->setCity( $city );
		$address->setState( $state );
		$address->setZip( $zip );
		$address->setCountry( $country );

		// Create request type object.
		$request_type = new AnetAPI\TransactionRequestType();
		$request_type->setTransactionType("authCaptureTransaction");
		$request_type->setAmount( $paid );
		$request_type->setCustomer( $customer );
		$request_type->setPayment( $payment );
		$request_type->setOrder( $order );
		$request_type->setBillTo( $address );

		$ref_id = 'ref' . time();
		// Assemble the complete transaction request
		$transaction = new AnetAPI\CreateTransactionRequest();
		$transaction->setMerchantAuthentication( $authentication );
		$transaction->setRefId( $ref_id );
		$transaction->setTransactionRequest( $request_type );

		// Create the controller and get the response.
		$controller = new AnetController\CreateTransactionController( $transaction );
		// Switch between sandbox and production.
		if ( isset( $options['mt_use_sandbox'] ) && 'true' == $options['mt_use_sandbox'] ) {
			$response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::SANDBOX );
		} else {
			$response = $controller->executeWithApiResponse( \net\authorize\api\constants\ANetEnvironment::PRODUCTION );
		}

		$transaction_id = '';
		$payment_status = '';
		$redirect       = '';
		if ( null !== $response ) {
			if ( 'Ok' === $response->getMessages()->getResultCode() ) {
				$transaction_response = $response->getTransactionResponse();
				if ( null !== $transaction_response->getMessages() ) {
					// Transaction was successful.
					$payment_status = 'Completed';
					$transaction_id = $transaction_response->getTransId();
					$receipt_id     = get_post_meta( $payment_id, '_receipt', true );
					$redirect       = mt_replace_http( esc_url_raw( add_query_arg( array(
						'response_code'  => 'thanks',
						'gateway'        => 'authnet',
						'transaction_id' => $transaction_id,
						'receipt_id'     => $receipt_id,
						'payment_id'     => $payment_id,
					), $purchase_page ) ) );

					if ( isset( $_POST['mt_shipping_street'] ) ) {
						$ship_address = array(
							'street'  => isset( $_POST['mt_shipping_street'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_street'] ) ) : '',
							'street2' => isset( $_POST['mt_shipping_street2'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_street2'] ) ) : '',
							'city'    => isset( $_POST['mt_shipping_city'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_city'] ) ) : '',
							'state'   => isset( $_POST['mt_shipping_state'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_state'] ) ) : '',
							'country' => isset( $_POST['mt_shipping_code'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_code'] ) ) : '',
							'code'    => isset( $_POST['mt_shipping_country'] ) ? sanitize_text_field( wp_unslash( $_POST['mt_shipping_country'] ) ) : '',
						);
					}
				} else {
					// Transaction failed.
					$message        = $transaction_response->getErrors()[0]->getErrorText();
					$payment_status = 'Failed';
					// redirect on failed payment.
					$redirect = mt_replace_http( esc_url_raw( add_query_arg( array(
						'response_code' => 'failed',
						'gateway'       => 'authnet',
						'payment_id'    => $payment_id,
						'reason'        => urlencode( $message ),
					), $purchase_page ) ) );
				}
			} else {
				// failed; no response was returned.
				echo '<pre>';
				print_r( $response );
				echo '</pre>';
			}
		}

		$data = array(
			'transaction_id' => $transaction_id,
			'price'          => $paid,
			'currency'       => $options['mt_currency'],
			'email'          => $payer_email,
			'first_name'     => $f_name, // get from charge info.
			'last_name'      => $l_name, // get from charge info.
			'status'         => $payment_status,
			'purchase_id'    => $payment_id,
			'shipping'       => $ship_address,
		);
		// Remove cc number from log data.
		$_REQUEST['card-number'] = '';
		$_REQUEST['card-cvc']    = '';

		mt_handle_payment( 'VERIFIED', '200', $data, $_REQUEST );

		// redirect back to our previous page with the added query variable.
		wp_safe_redirect( $redirect );
		exit;
	}
}