<?php 

namespace App\Services;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Validator;
use Exception;
use DateTime;
use Auth;
use Lang;
use App\Models\Common\Setting;
use App\ServiceType;
use App\Models\Common\Promocode;
use App\Provider;
use App\ProviderService;
use App\Helpers\Helper;
use GuzzleHttp\Client;
use App\Models\Common\PaymentLog;


//PayuMoney
use Tzsk\Payu\Facade\Payment AS PayuPayment;

//Paypal
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payee;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;

use Redirect;
use Session;
use URL;


class PaymentGateway {

	private $gateway;

	public function __construct($gateway){
		$this->gateway = strtoupper($gateway);
	}

	public function process($attributes) {
		$provider_url = '';

		$gateway = ($this->gateway == 'STRIPE') ? 'CARD' : $this->gateway ;

		$log = PaymentLog::where('transaction_code', $attributes['order'])->where('payment_mode', $gateway )->first();

		if($log->user_type == 'provider') {
			$provider_url = '/provider';
		}

		switch ($this->gateway) {

			case "MIDTRANS":

			    try {


			    	$settings = json_decode(json_encode(Setting::first()->settings_data));
        			$paymentConfig = json_decode( json_encode( $settings->payment ) , true);

        			$cardObject = array_values(array_filter( $paymentConfig, function ($e) { return $e['name'] == 'midtrans'; }));
			        $midtrans = 0;

			        $mid_server_key = "";
			       
			        if(count($cardObject) > 0) { 
			            $midtrans = $cardObject[0]['status'];

			            $stripeSecretObject = array_values(array_filter( $cardObject[0]['credentials'], function ($e) { return $e['name'] == 'mid_server_key'; }));
			           
			            if(count($stripeSecretObject) > 0) {
			                $mid_server_key = $stripeSecretObject[0]['value'];
			            }

			     
			        }


					\Midtrans\Config::$serverKey = $mid_server_key;
					// Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
					\Midtrans\Config::$isProduction = false;
					// Set sanitization on (default)
					\Midtrans\Config::$isSanitized = true;
					// Set 3DS transaction for credit card to true
					\Midtrans\Config::$is3ds = true;
					 
					$params = array(
					    'transaction_details' => array(
					        'order_id' => $attributes['order'],
					        'gross_amount' => $attributes['amount'],
					    ),
					    'customer_details' => array(
					        'first_name' => \Auth::guard($log->user_type)->user()->first_name,
					        'last_name' => \Auth::guard($log->user_type)->user()->last_name,
					        'email' => \Auth::guard($log->user_type)->user()->email,
					        'phone' => \Auth::guard($log->user_type)->user()->mobile,
					    ),
					);
					 
					$snapToken = \Midtrans\Snap::getSnapToken($params);

					if($log->admin_service=='WALLET' || $log->admin_service=='SERVICE' || $log->admin_service=='DELIVERY' || $log->admin_service=='ORDER'){

						return Helper::getResponse(['data'=> ['token' => $snapToken],'message' =>'Token Generated successfully']);

					}else{

						return (Object)['status' => 'SUCCESS', 'token' => $snapToken]; 

					}

					
					
			    	
			    } catch (Exception $e) {
			    	if($log->admin_service=='WALLET' || $log->admin_service=='SERVICE'){

						return Helper::getResponse(['data'=> ['error' => $e->getMessage()],'message' => $e->getMessage()]);

					}else{

                         return (Object)['status' => 'FAILURE','message' => $e->getMessage()];

					}
			    	
			    }

			break;

			case "STRIPE":

				try {
				
					$settings = json_decode(json_encode(Setting::first()->settings_data));
        			$paymentConfig = json_decode( json_encode( $settings->payment ) , true);

        			$cardObject = array_values(array_filter( $paymentConfig, function ($e) { return $e['name'] == 'card'; }));
			        $card = 0;

			        $stripe_secret_key = "";
			        $stripe_publishable_key = "";
			        $stripe_currency = "";

			        if(count($cardObject) > 0) { 
			            $card = $cardObject[0]['status'];

			            $stripeSecretObject = array_values(array_filter( $cardObject[0]['credentials'], function ($e) { return $e['name'] == 'stripe_secret_key'; }));
			            $stripePublishableObject = array_values(array_filter( $cardObject[0]['credentials'], function ($e) { return $e['name'] == 'stripe_publishable_key'; }));
			            $stripeCurrencyObject = array_values(array_filter( $cardObject[0]['credentials'], function ($e) { return $e['name'] == 'stripe_currency'; }));

			            if(count($stripeSecretObject) > 0) {
			                $stripe_secret_key = $stripeSecretObject[0]['value'];
			            }

			            if(count($stripePublishableObject) > 0) {
			                $stripe_publishable_key = $stripePublishableObject[0]['value'];
			            }

			            if(count($stripeCurrencyObject) > 0) {
			                $stripe_currency = $stripeCurrencyObject[0]['value'];
			            }
			        }


        			\Stripe\Stripe::setApiKey( $stripe_secret_key );
					  $Charge = \Stripe\Charge::create([
		                "amount" => $attributes['amount'] * 100,
		                "currency" => $attributes['currency'],
		                "customer" => $attributes['customer'],
		                "card" => $attributes['card'],
		                "description" => $attributes['description'],
		                "receipt_email" => $attributes['receipt_email']
		             ]);
					$log->response = json_encode($Charge);
                	$log->save();

					$paymentId = $Charge['id'];

					return (Object)['status' => 'SUCCESS', 'payment_id' => $paymentId];

				} catch(StripeInvalidRequestError $e){
					// echo $e->getMessage();exit;
					return (Object)['status' => 'FAILURE', 'message' => $e->getMessage()];

	            } catch(Exception $e) {
	                return (Object)['status' => 'FAILURE','message' => $e->getMessage()];
	            }

				break;

			default:
				return redirect('dashboard');
		}
		

	}

	public function verify(Request $request) {

		$settings = json_decode(json_encode(Setting::first()->settings_data));
		$paymentConfig = json_decode( json_encode( $settings->payment ) , true);

		$cardObject = array_values(array_filter( $paymentConfig, function ($e) { return $e['name'] == 'midtrans'; }));
        $midtrans = 0;

        $mid_server_key = "";
       
        if(count($cardObject) > 0) { 
            $midtrans = $cardObject[0]['status'];

            $stripeSecretObject = array_values(array_filter( $cardObject[0]['credentials'], function ($e) { return $e['name'] == 'mid_server_key'; }));
           
            if(count($stripeSecretObject) > 0) {
                $mid_server_key = $stripeSecretObject[0]['value'];
            }

     
        }

		$log = PaymentLog::where('transaction_code', $request->order_id)->first();

        $orderData = [];
		
	    $midtrans_detail = json_decode($log->response);

	    \Midtrans\Config::$serverKey = $mid_server_key;
		// Set to Development/Sandbox Environment (default). Set to true for Production Environment (accept real transaction).
		\Midtrans\Config::$isProduction = false;
		// Set sanitization on (default)
		\Midtrans\Config::$isSanitized = true;
		// Set 3DS transaction for credit card to true
		\Midtrans\Config::$is3ds = true;

	    $transaction = \Midtrans\Transaction::status($request->order_id);

	    $fraud = $transaction->fraud_status;


        if($transaction->transaction_status == 'capture' || $fraud == 'accept'){

		    if($log->admin_service == "ORDER") {
		    	$orderData = json_decode($log->order_request, true);
		    }


	        $log->response = json_encode($transaction);
	        $log->save();

	       
	        $payment_id = $request->order_id;
	        


	        if($log->admin_service == "TRANSPORT") {
	        	try {
	        		$userRequest = \App\Models\Transport\RideRequest::find($log->transaction_id);
	        		$log->type_id = $userRequest->ride_type_id;

	        		$payment = \App\Models\Transport\RideRequestPayment::where('ride_request_id', $log->transaction_id)->first();
	        		$payment->payment_id = $payment_id;
		    		$payment->save();
	        	} catch (\Throwable $e) { }
	        	
	        } else if($log->admin_service == "ORDER") {
	        	$log->transaction_id = $payment_id;
	        	$log->save();
	        } else if($log->admin_service == "SERVICE") {
	        	try {
	        		$userRequest = \App\Models\Service\ServiceRequest::find($log->transaction_id);
	        		$log->type_id = $userRequest->service_id;

	        		$payment = \App\Models\Service\ServiceRequestPayment::where('service_request_id', $log->transaction_id)->first();
	        		$payment->payment_id = $payment_id;
		    		$payment->save();
	        	} catch (\Throwable $e) { }
	        	
	        }

	        $log->payment_id = $payment_id;
            $log->status='success';
	        return $log;

	    }else{

	    	$log->status = 'failed';
	    	return $log;
	    }

        


	}
	
}