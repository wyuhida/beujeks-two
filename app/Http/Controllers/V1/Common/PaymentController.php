<?php

namespace App\Http\Controllers\V1\Common;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PaymentGateway;
use App\Models\Common\PaymentLog;
use App\Services\SendPushNotification;
use App\Models\Common\Country;
use App\Models\Common\Setting;
use App\Models\Common\State;
use App\Models\Common\City;
use App\Models\Common\Menu;
use App\Models\Common\Card;
use App\Models\Common\User;
use App\Models\Common\Provider;
use App\Helpers\Helper;
use App\Models\Delivery\Delivery;
use App\Models\Common\Settings;
use App\Models\Common\UserWallet;
use App\Models\Common\ProviderCard;
use App\Models\Common\ProviderWallet;
use App\Services\Transactions;
use Auth;
use Carbon\Carbon;

class PaymentController extends Controller
{
	/**
     * add wallet money for user.
     *
     * @return \Illuminate\Http\Response
     */
    public function add_money(Request $request)
    {
        $this->validate($request, [
            'user_type' => 'required',
            'amount' => 'required',
            'payment_mode' => 'required'
        ]);

        $random = 'TRNX'.mt_rand(100000, 999999);

        $user_type = $request->user_type;
 
        $log = new PaymentLog();
        $log->user_type = $user_type;
        $log->admin_service = 'WALLET';
        $log->is_wallet = '1';
        $log->amount = $request->amount;
        $log->transaction_code = $random;
        $log->payment_mode = strtoupper($request->payment_mode);
        $log->user_id = Auth::guard($user_type)->user()->id;
        $log->company_id = Auth::guard($user_type)->user()->company_id;
        $log->save();

        switch (strtoupper($request->payment_mode)) {

          case 'BRAINTREE':

           $gateway = new PaymentGateway('braintree');
            return $gateway->process([
                'amount' => $request->amount,
                'nonce' => $request->braintree_nonce,
                'order' => $random,
            ]);

            break;


            case 'MIDTRANS':

               $gateway = new PaymentGateway('midtrans');
                return $gateway->process([
                    'amount' => $request->amount,
                    'order' => $random,
                ]);

            break;


          case 'CARD':
            
            if ($user_type == 'provider') {

                ProviderCard::where('provider_id', Auth::guard('provider')->user()->id)->update(['is_default' => 0]);
                ProviderCard::where('card_id', $request->card_id)->update(['is_default' => 1]);


            } else {
                Card::where('user_id', Auth::guard('user')->user()->id)->update(['is_default' => 0]);
                Card::where('card_id', $request->card_id)->update(['is_default' => 1]);
            }

            
            $gateway = new PaymentGateway('stripe');
            $response = $gateway->process([
                "order" => $random,
                "amount" => $request->amount,
                "currency" => 'USD',
                "customer" => Auth::guard($user_type)->user()->stripe_cust_id, 
                "card" => $request->card_id,
                "description" => "Adding Money for " . Auth::guard($user_type)->user()->email,
                "receipt_email" => Auth::guard($user_type)->user()->email
            ]);
            if($response->status == "SUCCESS") { 
                if($user_type == 'user'){

                    //create transaction to user wallet
                    $transaction['id']=Auth::guard('user')->user()->id;
                    $transaction['amount']=$log->amount;
                    $transaction['company_id']=$log->company_id;                                        
                    (new Transactions)->userCreditDebit($transaction,1);

                    //update wallet balance
                    $wallet_balance = Auth::guard('user')->user()->wallet_balance+$log->amount;
                    User::where('id',Auth::guard('user')->user()->id)
                    ->where('company_id',Auth::guard('user')->user()->company_id)->update(['wallet_balance' => $wallet_balance]);

                    (new SendPushNotification)->WalletMoney(Auth::guard('user')->user()->id, Auth::guard('user')->user()->currency_symbol.$log->amount, 'common', 'Wallet amount added', ['amount' => $log->amount]);
                }else{
       
                    //create transaction to provider wallet
                    $transaction['id']=Auth::guard('provider')->user()->id;
                    $transaction['amount']=$log->amount;
                    $transaction['company_id']=$log->company_id;                                        
                    (new Transactions)->providerCreditDebit($transaction,1);

                    //update wallet balance
                    $wallet_balance = Auth::guard('provider')->user()->wallet_balance+$log->amount;

                    Provider::where('id',Auth::guard('provider')->user()->id)
                    ->where('company_id',Auth::guard('provider')->user()->company_id)->update(['wallet_balance' => $wallet_balance]);

                    (new SendPushNotification)->ProviderWalletMoney(Auth::guard('provider')->user()->id, Auth::guard('provider')->user()->currency_symbol.$log->amount, 'common', 'Wallet amount added', ['amount' => $log->amount]);
                }

                return Helper::getResponse(['data'=> ['wallet_balance' => $wallet_balance],'message' => trans('api.amount_added_to_your_wallet')]);
            }else{
                return Helper::getResponse(['status' => '500', 'message' => trans('Transaction Failed')]);
            }
            break;
        }
    }


    public function midtrans_verify(Request $request)
    {

        \Log::info($request->all());
  
        $response = (new PaymentGateway('midtrans'))->verify($request);

    
        if($response->status=='failed'){
            return Helper::getResponse([ 'data' => $response, 'message' => 'Payment Failed!' ]);
        }    

        if($response->admin_service == "WALLET") {
            if($response->user_type == 'user') {

                $user = User::find($response->user_id);

                $transaction['id']=$user->id;
                $transaction['amount']=$response->amount;
                $transaction['company_id']=$response->company_id;                                        
                (new Transactions)->userCreditDebit($transaction,1);

                //update wallet balance
                $wallet_balance = $user->wallet_balance+$response->amount;
                User::where('id',$user->id)
                ->where('company_id',$user->company_id)->update(['wallet_balance' => $wallet_balance]);

                (new SendPushNotification)->WalletMoney($user->id, $user->currency_symbol.$response->amount, 'common', 'Wallet amount added', ['amount' => $response->amount]);
            } else {

                $user = Provider::find($response->user_id);

                //create transaction to provider wallet
                $transaction['id']=$user->id;
                $transaction['amount']=$response->amount;
                $transaction['company_id']=$response->company_id;                                        
                (new Transactions)->providerCreditDebit($transaction,1);

                //update wallet balance
                $wallet_balance = $user->wallet_balance+$response->amount;

                Provider::where('id',$user->id)
                ->where('company_id',$user->company_id)->update(['wallet_balance' => $wallet_balance]);

                (new SendPushNotification)->ProviderWalletMoney($user->id, $user->currency_symbol.$response->amount, 'common', 'Wallet amount added', ['amount' => $response->amount]);
            }

        } else if($response->admin_service == "TRANSPORT") {

            $UserRequest = \App\Models\Transport\RideRequest::find($response->transaction_id);

            $UserRequest->paid = 1;
            $UserRequest->status = 'COMPLETED';
            $UserRequest->save();
            //for create the transaction
            (new \App\Http\Controllers\V1\Transport\Provider\TripController)->callTransaction($UserRequest->id);

            $requestData = ['type' => $UserRequest->admin_service, 'room' => 'room_'.$UserRequest->company_id, 'id' => $UserRequest->id, 'city' =>  $UserRequest->city_id, 'user' => $UserRequest->user_id ];
            app('redis')->publish('checkTransportRequest', json_encode( $requestData ));

            $response->type_id = $UserRequest->ride_type_id;

        }else if($response->admin_service == "DELIVERY") {

            $UserRequest = \App\Models\Delivery\DeliveryRequest::find($response->transaction_id);

            $delivery = Delivery::where('delivery_request_id', $UserRequest->id)->first();


            $delivery->status = 'STARTED';
            $delivery->started_at = (Carbon::now())->toDateTimeString();
            $delivery->save();

            $UserRequest->paid = '1';
            $UserRequest->save();


            Delivery::where('delivery_request_id', $UserRequest->id)->update(array('paid' => 1));

            if(($UserRequest->payable_amount != 0)) {
                (new SendPushNotification)->Payment($UserRequest, 'delivery');
            }

            //for create the transaction
            (new \App\Http\Controllers\V1\Delivery\Provider\TripController)->callTransaction($UserRequest->id);

            $requestData = ['type' => $UserRequest->admin_service, 'room' => 'room_'.$UserRequest->company_id, 'id' => $UserRequest->id, 'city' =>  $UserRequest->city_id, 'user' => $UserRequest->user_id ];
            app('redis')->publish('checkDeliveryRequest', json_encode( $requestData ));

            $response->type_id = $UserRequest->delivery_type_id;

        }  else if($response->admin_service == "SERVICE") {

            $UserRequest = \App\Models\Service\ServiceRequest::find($response->transaction_id);

            $payment = \App\Models\Service\ServiceRequestPayment::where('service_request_id', $UserRequest->id)->first();
            $payment->payable = 0;
            $payment->save();

            $UserRequest->paid = 1;
            $UserRequest->status = 'COMPLETED';
            $UserRequest->save();

            //for create the transaction
            (new  \App\Http\Controllers\V1\Service\Provider\ServeController)->callTransaction($UserRequest->id);
            $requestData = ['type' => 'SERVICE', 'room' => 'room_'.$UserRequest->company_id, 'id' => $UserRequest->id, 'city' => $UserRequest->city_id, 'user' => $UserRequest->user_id ];
            app('redis')->publish('checkServiceRequest', json_encode( $requestData ));

            $response->type_id = $UserRequest->service_id;

        } else {

            $log = PaymentLog::where('transaction_code', $request->order_id)->first();
            $log->save();

            $requestData = json_decode($log->order_request);
            foreach ($requestData as $key => $requestDatum) {
                $request->request->add([$key => $requestDatum]);
            }
            $request->request->add(['user_id' => $log->user_id]);
            $request->request->add(['payment_mode' => 'MIDTRANS_SUCCESS']);
            
            return (new \App\Http\Controllers\V1\Order\User\HomeController)->checkout($request);

        }

        return Helper::getResponse([ 'data' => $response, 'message' => 'Payment Success!' ]);
    }
}
