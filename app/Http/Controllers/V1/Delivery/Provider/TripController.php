<?php

namespace App\Http\Controllers\V1\Delivery\Provider;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Models\Delivery\DeliveryRequestDispute;
use App\Models\Delivery\DeliveryRequest; 
use App\Models\Delivery\DeliveryPayment; 
use App\Services\V1\Common\ProviderServices;
use App\Models\Common\RequestFilter;
use App\Services\SendPushNotification;
use App\Models\Common\ProviderService;
use Illuminate\Support\Facades\Hash;
use App\Services\ReferralResource;
use App\Models\Delivery\Delivery;
use App\Models\Common\Provider;
use Location\Distance\Vincenty;
use Location\Coordinate;
use App\Models\Common\Setting;
use App\Services\V1\Delivery\DeliveryService;
use App\Models\Common\Reason;
use App\Models\Common\Rating;
use App\Models\Common\UserRequest;
use App\Models\Common\AdminService;
use App\Models\Common\User;
use App\Models\Common\Promocode;
use App\Models\Common\PromocodeUsage;
use App\Models\Common\PeakHour;
use App\Models\Common\Dispute;
use App\Traits\Actions;
use App\Helpers\Helper;
use Carbon\Carbon;
use App\Services\Transactions;
use App\Models\Common\Admin;
use App\Models\Common\Chat;
use Auth;
use Log;
use DB;



class TripController extends Controller
{

    use Actions;

    public function index(Request $request)
	{
		try{

			$settings = json_decode(json_encode(Setting::where('company_id', Auth::guard('provider')->user()->company_id)->first()->settings_data));

	        $siteConfig = $settings->site;

	        $deliveryConfig = $settings->delivery;

			$Provider = Provider::with(['service'  => function($query) {  
				$query->where('admin_service', 'DELIVERY' ); 
			}])->where('id', Auth::guard('provider')->user()->id)->first();

			$provider = $Provider->id;

			$IncomingRequests = DeliveryRequest::with(['delivery.payment','user','delivery'=> function($q) {
				$q->where('status', '!=' ,'COMPLETED');
			},'service'])
				->where('status','<>', 'CANCELLED')
				->where('status','<>', 'SCHEDULED')
				->where('provider_rated', '0')
				->where('provider_id', $provider )->first();

				if($IncomingRequests != null) {
					$deliveries = Delivery::select('id')->where('delivery_request_id', $IncomingRequests->id)->pluck('id');
					$delivery_payments = DeliveryPayment::whereIn('delivery_id', $deliveries)->get();
					if(count($delivery_payments)) {
						$delivery_id = $IncomingRequests->id;
						$user_id = 0;
						$provider_id = 0;
						$fleet_id = 0;
						$promocode_id = 0;
						$payment_id = 0;
						$company_id = 0;
						$payment_mode = 0;
						$fixed = 0;
						$distance = 0;
						$weight = 0;
						$commision = 0;
						$commision_percent = 0;
						$fleet = 0;
						$fleet_percent = 0;
						$discount = 0;
						$discount_percent = 0;
						$tax = 0;
						$tax_percent = 0;
						$wallet = 0;
						$is_partial = 0;
						$cash = 0;
						$card = 0;
						$peak_amount = 0;
						$peak_comm_amount = 0;
						$total_waiting_time = 0;
						$tips = 0;
						$round_of = 0;
						$total = 0;
						$payable = 0;
						$provider_pay = 0;

						foreach ($delivery_payments as $key => $delivery_payment) {
							$user_id = $delivery_payment->user_id;
							$provider_id = $delivery_payment->provider_id;
							$fleet_id = $delivery_payment->fleet_id;
							$$promocode_id = $delivery_payment->promocode_id;
							$payment_id = $delivery_payment->payment_id;
							$company_id = $delivery_payment->company_id;
							$payment_mode = $delivery_payment->payment_mode;
							$fixed += $delivery_payment->fixed;
							$distance += $delivery_payment->distance;
							$weight += $delivery_payment->weight;
							$commision += $delivery_payment->commision;
							$commision_percent = $delivery_payment->commision_percent;
							$fleet += $delivery_payment->fleet;
							$fleet_percent = $delivery_payment->fleet_percent;
							$discount += $delivery_payment->discount;
							$discount_percent = $delivery_payment->discount_percent;
							$tax += $delivery_payment->tax;
							$tax_percent = $delivery_payment->tax_percent;
							$wallet += $delivery_payment->wallet;
							$is_partial = $delivery_payment->is_partial;
							$cash += $delivery_payment->cash;
							$card += $delivery_payment->card;
							$peak_amount += $delivery_payment->peak_amount;
							$peak_comm_amount += $delivery_payment->peak_comm_amount;
							$total_waiting_time += $delivery_payment->total_waiting_time;
							$tips += $delivery_payment->tips;
							$round_of += $delivery_payment->round_of;
							$total += $delivery_payment->total;
							$payable += $delivery_payment->payable;
							$provider_pay += $delivery_payment->provider_pay;
						}

						$payment = new \stdClass;
						$payment->delivery_id = $delivery_id;
						$payment->user_id = $user_id;
						$payment->provider_id = $provider_id;
						$payment->fleet_id = $fleet_id;
						$payment->promocode_id = $promocode_id;
						$payment->payment_id = $payment_id;
						$payment->company_id = $company_id;
						$payment->payment_mode = $payment_mode;
						$payment->fixed = $fixed;
						$payment->distance = round($distance,2);
						$payment->weight = $weight;
						$payment->commision = $commision;
						$payment->commision_percent = $commision_percent;
						$payment->fleet = $fleet;
						$payment->fleet_percent = $fleet_percent;
						$payment->discount = $discount;
						$payment->discount_percent = $discount_percent;
						$payment->tax = round($tax,2);
						$payment->tax_percent = $tax_percent;
						$payment->wallet = $wallet;
						$payment->is_partial = $is_partial;
						$payment->cash = $cash;
						$payment->card = $card;
						$payment->peak_amount = $peak_amount;
						$payment->peak_comm_amount = $peak_comm_amount;
						$payment->total_waiting_time = $total_waiting_time;
						$payment->tips = $tips;
						$payment->round_of = $round_of;
						$payment->total = round($total,2);
						$payment->payable = $payable;
						$payment->provider_pay = $provider_pay;

						$base_distance  = $IncomingRequests->base_distance;
						$total_distance = $IncomingRequests->distance;
						$base_weight  = $IncomingRequests->base_weight;
						$total_weight = $IncomingRequests->weight;
						$tot_dis = $total_distance - $base_distance;
						$tot_weig = $total_weight - $base_weight;
						if($IncomingRequests->calculator == "WEIGHT"){
							$payment->base_fare_text = 'Base Fare for '.$base_weight .' kgs';
						}else if($IncomingRequests->calculator == "DISTANCE"){
							$payment->base_fare_text = 'Base Fare for '.$base_distance .' kgs';
						}else{
							$payment->base_fare_text = 'Base Fare for '.$base_distance .' kms&'.$base_weight.' kgs';
						}
						
						$payment->distance_fare_text = 'Distance Fare for '.$tot_dis.' Kms';
						$payment->weight_fare_text = 'Delivery Weight for '.$tot_weig.' Kgs';
						$payment->discount_fare_text = 'Discount ('.$IncomingRequests->discount_percent .'%)';

						$IncomingRequests->payment = $payment;


					}
				}
//return $IncomingRequests;
			if(!empty($request->latitude)) {
				$Provider->update([
						'latitude' => $request->latitude,
						'longitude' => $request->longitude,
				]);

				//when the provider is idle for a long time in the mobile app, it will change its status to hold. If it is waked up while new incoming request, here the status will change to active
				//DB::table('provider_services')->where('provider_id',$Provider->id)->where('status','hold')->update(['status' =>'active']);
			}

			$Reason=Reason::where('type','PROVIDER')->where('service','DELIVERY')->where('status','Active')->get();

			$referral_total_count = (new ReferralResource)->get_referral('provider', Auth::guard('provider')->user()->id)[0]->total_count;
			$referral_total_amount = (new ReferralResource)->get_referral('provider', Auth::guard('provider')->user()->id)[0]->total_amount;

			$Response = [
					'sos' => isset($siteConfig->sos_number) ? $siteConfig->sos_number : '911' , 
                	'emergency' => isset($siteConfig->contact_number) ? $siteConfig->contact_number : [['number' => '911']],
					'account_status' => $Provider->status,
					'service_status' => !empty($IncomingRequests) ? 'DELIVERY':'ACTIVE',
					'request' => $IncomingRequests,
					'provider_details' => $Provider,
					'reasons' => $Reason,
					'referral_count' => $siteConfig->referral_count,
					'referral_amount' => $siteConfig->referral_amount,
					'otp' => $deliveryConfig->otp,
					'referral_total_count' => $referral_total_count,
					'referral_total_amount' => $referral_total_amount,
				];

			if($IncomingRequests != null){
				if(!empty($request->latitude) && !empty($request->longitude)) {
					//$this->calculate_distance($request,$IncomingRequests->id);
				}	
			}

			return Helper::getResponse(['data' => $Response ]);

		} catch (ModelNotFoundException $e) {
			//dd($e);
			return Helper::getResponse(['status' => 500, 'error' => $e->getMessage()]);
		}
	}

	public function update_ride(Request $request)
	{
		$this->validate($request, [
			  'id' => 'required|numeric|exists:delivery.deliveries,id,provider_id,'.Auth::guard('provider')->user()->id,
			  'status' => 'required|in:ACCEPTED,STARTED,ARRIVED,PICKEDUP,DROPPED,PAYMENT,COMPLETED',
		   ]);

		try {
			$ride = (new DeliveryService())->updateRide($request);
			//return $ride;
			return Helper::getResponse(['message' => isset($ride['message']) ? $ride['message'] : 'test' , 'data' => isset($ride['data']) ? $ride['data']: []  ]);
		} catch (Exception $e) {  
            return Helper::getResponse(['status' => 500, 'error' => $e->getMessage()]);
        }
	}

	public function cancel_ride(Request $request)
	{

		$this->validate($request, [
			  'id' => 'required|numeric|exists:delivery.deliveries,id,provider_id,'.$this->user->id,
			  //'service_id' => 'required|numeric|exists:common.admin_services,id',
			  'reason'=>'required',
		   ]);

		$request->request->add(['cancelled_by' => 'PROVIDER']);

		//try {
			$ride = (new DeliveryService())->cancelRide($request);

			$provider = Provider::find($this->user->id);
			$provider->is_assigned = 0;
			$provider->save();

			return Helper::getResponse(['status' => $ride['status'], 'message' => $ride['message'], 'data' => isset($ride['data']) ? $ride['data']: []  ]);
		/*} catch (Exception $e) {  
            return Helper::getResponse(['status' => 500, 'error' => $e->getMessage()]);
        }*/
	}


	public function rate(Request $request)
    {
        $this->validate($request, [
              'id' => 'required|numeric|exists:delivery.delivery_requests,id,provider_id,'.Auth::guard('provider')->user()->id,
              'rating' => 'required|integer|in:1,2,3,4,5',
              'comment' => 'max:255',
          ],['comment.max'=>'character limit should not exceed 255']);

        try {

        	$rideRequest = DeliveryRequest::where('id', $request->id)->where('status', 'COMPLETED')->firstOrFail();
        	
        	$data = (new ProviderServices())->rate($request, $rideRequest );

        	return Helper::getResponse(['status' => isset($data['status']) ? $data['status'] : 200, 'message' => isset($data['message']) ? $data['message'] : '', 'error' => isset($data['error']) ? $data['error'] : '' ]);

        } catch (Exception $e) {
            return Helper::getResponse(['status' => 500, 'message' => trans('api.ride.request_not_completed'), 'error' =>trans('api.ride.request_not_completed') ]);
        }
    }

	/**
	 * Get the trip history of the provider
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function trips(Request $request)
	{
		try{
			$jsonResponse = [];
			$jsonResponse['type'] = 'delivery';
			$request->request->add(['admin_service'=>'DELIVERY']);
			$withCallback=[
							'user' => function($query){  $query->select('id', 'first_name', 'last_name', 'rating', 'picture','currency_symbol' ); },
							'provider' => function($query){  $query->select('id', 'first_name', 'last_name', 'rating', 'picture','currency_symbol' ); },
							'provider_vehicle' => function($query){  $query->select('id', 'provider_id', 'vehicle_make', 'vehicle_model', 'vehicle_no' ); },
							'deliveries.payment', 
							'service' => function($query){  $query->select('id','vehicle_name', 'vehicle_image'); },
                            'rating' => function($query){  $query->select('request_id','user_rating', 'provider_rating','user_comment','provider_comment'); },
							'payment','service_type'
						  ];
		    $ProviderRequests = DeliveryRequest::select('id', 'booking_id', 'assigned_at', 's_address', 'd_address','provider_id','user_id','timezone','delivery_vehicle_id', 'status', 'provider_vehicle_id','started_at');
		    $data=(new ProviderServices())->providerHistory($request,$ProviderRequests,$withCallback);
		   			  
			$jsonResponse['total_records'] = count($data);
		    $jsonResponse['delivery'] = $data;
			return Helper::getResponse(['data' => $jsonResponse]);
		}
		catch (Exception $e) {
			return response()->json(['error' => trans('api.something_went_wrong')]);
		}
	}
	/**
	 * Get the trip history of the provider
	 *
	 * @return \Illuminate\Http\Response
	 */
	public function gettripdetails(Request $request,$id)
	{
		try{
			
			$jsonResponse = [];
			$jsonResponse['type'] = 'delivery';
			$providerrequest = DeliveryRequest::with(array('deliveries.package_type','deliveries.payment','service','user','service_type',
			'rating'=>function($query){
				$query->select('id','request_id','user_rating', 'provider_rating','user_comment','provider_comment');
				$query->where('admin_service','DELIVERY');
			},'dispute'=>function($query){
				$query->where('dispute_type','provider');
			}));
			$request->request->add(['admin_service'=>'DELIVERY','id'=>$id]);
			$data=(new ProviderServices())->providerTripsDetails($request,$providerrequest);
            $jsonResponse['delivery'] = $data;
			return Helper::getResponse(['data' => $jsonResponse]);
		}
		catch (Exception $e) {
			return response()->json(['error' => trans('api.something_went_wrong')]);
		}
	}
	//Save the dispute details
	public function ride_request_dispute(Request $request) {

		$this->validate($request, [
				'id' => 'required', 
				'user_id' => 'required',
				'provider_id'=>'required',
				'dispute_name' => 'required',
				'dispute_type' => 'required',
			]);

		$ride_request_dispute = DeliveryRequestDispute::where('company_id',Auth::guard('provider')->user()->company_id)
							    ->where('delivery_request_id',$request->id)
								->where('dispute_type','provider')
								->first();
		$request->request->add(['admin_service'=>'DELIVERY']);						
		if($ride_request_dispute==null)
		{
			
			try{
				$disputeRequest = new DeliveryRequestDispute;
				$data=(new ProviderServices())->providerDisputeCreate($request, $disputeRequest);
				return Helper::getResponse(['status' => 200, 'message' => trans('admin.create')]);
			} 
			catch (\Throwable $e) {
				return Helper::getResponse(['status' => 404, 'message' => trans('admin.something_wrong'), 'error' => $e->getMessage()]);
			}
		}else{
			return Helper::getResponse(['status' => 404, 'message' => trans('Already Dispute Created for the Ride Request')]);
		}
	}

	public function get_ride_request_dispute(Request $request,$id) {
		$ride_request_dispute = DeliveryRequestDispute::where('company_id',Auth::guard('provider')->user()->company_id)
							    ->where('delivery_request_id',$id)
								->where('dispute_type','provider')
								->first();
	    if($ride_request_dispute) $ride_request_dispute->created_time=(\Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $ride_request_dispute->created_at, 'UTC'))->setTimezone(Auth::guard('provider')->user()->timezone)->format(Helper::dateFormat());								
		return Helper::getResponse(['data' => $ride_request_dispute]);
	}

	public function getdisputedetails(Request $request)
	{
		$dispute = Dispute::select('id','dispute_name')->get();
        return Helper::getResponse(['data' => $dispute]);
	}
    

	public function callTransaction($request_id){  

		$UserRequest = DeliveryRequest::with('provider')->with('payment')->findOrFail($request_id);

		if($UserRequest->paid==1){
			$transation=array();
			$transation['admin_service']='DELIVERY';
			$transation['company_id']=$UserRequest->company_id;
			$transation['transaction_id']=$UserRequest->id;
			$transation['country_id']=$UserRequest->country_id;
        	$transation['transaction_alias']=$UserRequest->booking_id;	


			$provider = Provider::where('id',$UserRequest->provider_id)->first();

			$deliveries = Delivery::select('id')->where('delivery_request_id', $UserRequest->id)->pluck('id'); 

			$delivery_payments = DeliveryPayment::whereIn('delivery_id', $deliveries)->get();


			if(count($delivery_payments)) {
				$delivery_id = $UserRequest->id;
				$user_id = 0;
				$provider_id = 0;
				$fleet_id = 0;
				$payment_id = 0;
				$payment_mode = 0;
				$fixed = 0;
				$commision = 0;
				$fleet = 0;
				$fleet_percent = 0;
				$discount = 0;
				$tax = 0;
				$wallet = 0;
				$is_partial = 0;
				$peak_amount = 0;
				$peak_comm_amount = 0;
				$tips = 0;
				$total = 0;

				foreach ($delivery_payments as $delivery_payment) {
					$user_id = $delivery_payment->user_id;
					$provider_id = $delivery_payment->provider_id;
					$fleet_id = $delivery_payment->fleet_id;
					$payment_id = $delivery_payment->payment_id;
					$payment_mode = $delivery_payment->payment_mode;
					$fixed += $delivery_payment->fixed;
					$commision += $delivery_payment->commision;
					$fleet += $delivery_payment->fleet;
					$fleet_percent = $delivery_payment->fleet_percent;
					$discount += $delivery_payment->discount;
					$tax += $delivery_payment->tax;
					$wallet += $delivery_payment->wallet;
					$is_partial = $delivery_payment->is_partial;
					$peak_amount += $delivery_payment->peak_amount;
					$peak_comm_amount += $delivery_payment->peak_comm_amount;
					$tips += $delivery_payment->tips;
					$total += $delivery_payment->total;					
				}

				$paymentsRequest = new \stdClass;
				$paymentsRequest->delivery_id = $delivery_id;
				$paymentsRequest->user_id = $user_id;
				$paymentsRequest->provider_id = $provider_id;
				$paymentsRequest->fleet_id = $fleet_id;
				$paymentsRequest->payment_id = $payment_id;
				$paymentsRequest->payment_mode = $payment_mode;
				$paymentsRequest->commision = $commision;
				$paymentsRequest->fleet = $fleet;
				$paymentsRequest->fleet_percent = $fleet_percent;
				$paymentsRequest->discount = $discount;
				$paymentsRequest->tax = $tax;
				$paymentsRequest->wallet = $wallet;
				$paymentsRequest->is_partial = $is_partial;
				$paymentsRequest->peak_comm_amount = $peak_comm_amount;
				$paymentsRequest->tips = $tips;
				$paymentsRequest->total = $total;

			}	

			
			$fleet_amount=$discount=$admin_commision=$credit_amount=$balance_provider_credit=$provider_credit=0;                

			if($paymentsRequest->is_partial==1){
				//partial payment
				if($paymentsRequest->payment_mode=="CASH"){
					$credit_amount=$paymentsRequest->wallet + $paymentsRequest->tips;
				}
				else{
					$credit_amount=$paymentsRequest->total + $paymentsRequest->tips;
				}
			}
			else{
				if($paymentsRequest->payment_mode=="CARD" || $paymentsRequest->payment_id=="WALLET"){
					$credit_amount=$paymentsRequest->total + $paymentsRequest->tips;
				}
				else{

					$credit_amount=0;                    
				}    
			}                
			


			//admin,fleet,provider calculations
			if(!empty($paymentsRequest->commision)){

				$admin_commision=$paymentsRequest->commision;

				if(!empty($paymentsRequest->fleet_id)){
					//get the percentage of fleet owners
					$fleet_per=$paymentsRequest->fleet_percent;
					$fleet_amount=($admin_commision) * ( $fleet_per/100 );
					$admin_commision=$admin_commision;

				}

				//check the user applied discount
				if(!empty($paymentsRequest->discount)){
					$balance_provider_credit=$paymentsRequest->discount;
				}  

			}
			else{

				if(!empty($paymentsRequest->fleet_id)){
					$fleet_per=$paymentsRequest->fleet_percent;
					$fleet_amount=($paymentsRequest->total) * ( $fleet_per/100 );
					$admin_commision=$fleet_amount;
				}
				if(!empty($paymentsRequest->discount)){
					$balance_provider_credit=$paymentsRequest->discount;
				}    
			}


			if(!empty($admin_commision)){
				//add the commission amount to admin wallet and debit amount to provider wallet, update the provider wallet amount to provider table				
        		$transation['id']=$paymentsRequest->provider_id;
        		$transation['amount']=$admin_commision;
			   (new Transactions)->adminCommission($transation);
			}
			

			if(!empty($paymentsRequest->fleet_id) && !empty($fleet_amount)){
				$paymentsRequest->fleet=$fleet_amount;
				$paymentsRequest->save();
				//create the amount to fleet account and deduct the amount to admin wallet, update the fleet wallet amount to fleet table				
        		$transation['id']=$paymentsRequest->fleet_id;
        		$transation['amount']=$fleet_amount;
			   	(new Transactions)->fleetCommission($transation);
				                       
			}
			if(!empty($balance_provider_credit)){
				//debit the amount to admin wallet and add the amount to provider wallet, update the provider wallet amount to provider table				
        		$transation['id']=$paymentsRequest->provider_id;
        		$transation['amount']=$balance_provider_credit;
			   	(new Transactions)->providerDiscountCredit($transation);				
			}

			if(!empty($paymentsRequest->tax)){
				//debit the amount to provider wallet and add the amount to admin wallet
				$transation['id']=$paymentsRequest->provider_id;
        		$transation['amount']=$paymentsRequest->tax;
				(new Transactions)->taxCredit($transation);
			}

			if(!empty($paymentsRequest->peak_comm_amount)){
				//add the peak amount commision to admin wallet
				$transation['id']=$paymentsRequest->provider_id;
        		$transation['amount']=$paymentsRequest->peak_comm_amount;
				(new Transactions)->peakAmount($transation);
			}

			if(!empty($paymentsRequest->waiting_comm_amount)){
				//add the waiting amount commision to admin wallet
				$transation['id']=$paymentsRequest->provider_id;
        		$transation['amount']=$paymentsRequest->waiting_comm_amount;
				(new Transactions)->waitingAmount($transation);
			}  
			if($credit_amount>0){               
				//provider ride amount
				//check whether provider have any negative wallet balance if its deduct the amount from its credit.
				//if its negative wallet balance grater of its credit amount then deduct credit-wallet balance and update the negative amount to admin wallet
				$transation['id']=$paymentsRequest->provider_id;
				$transation['amount']=$credit_amount;

				if($provider->wallet_balance>0){
					$transation['admin_amount']=$credit_amount-($admin_commision+$paymentsRequest->tax);

				}
				else{
					$transation['admin_amount']=$credit_amount-($admin_commision+$paymentsRequest->tax)+($provider->wallet_balance);
				}

				(new Transactions)->providerRideCredit($transation);
			}

			return true;
		}
		else{
			
			return true;
		}
		
	}
	

	

}
