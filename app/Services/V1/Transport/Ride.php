<?php 

namespace App\Services\V1\Transport;

use Illuminate\Http\Request;
use Validator;
use Exception;
use DateTime;
use Carbon\Carbon;
use Auth;
use Lang;
use App\Helpers\Helper;
use GuzzleHttp\Client;
use App\Models\Common\Card;
use App\Models\Common\User;
use App\Models\Common\State;
use App\Models\Common\Admin;
use App\Models\Transport\RideRequest;
use App\Models\Common\CompanyCountry;
use App\Models\Transport\RentalPackage;
use App\Models\Transport\OutstationRideStatePrice;
use App\Models\Transport\RideRequestPayment;
use App\Models\Transport\RideRequestWaitingTime;
use App\Models\Transport\RideCityPrice;
use App\Models\Transport\RidePeakPrice;
use App\Services\SendPushNotification;
use App\Models\Common\PromocodeUsage;
use App\Models\Common\Promocode;
use App\Models\Common\PeakHour;
use App\Models\Common\Setting;
use App\Services\Transactions;
use App\Services\V1\Common\UserServices;
use App\Services\V1\Common\ProviderServices;
use App\Models\Common\UserRequest;
use App\Models\Common\GeoFence;
use App\Models\Common\Chat;
use App\Traits\Actions;
use Illuminate\Support\Facades\Mail;
use App\Models\Common\Provider;


class Ride { 

	use Actions;

	/**
		* Get a validator for a tradepost.
		*
		* @param  array $data
		* @return \Illuminate\Contracts\Validation\Validator
	*/
	protected function validator(array $data) {
		$rules = [
			'location'  => 'required',
		];

		$messages = [
			'location.required' => 'Location Required!',
		];

		return Validator::make($data,$rules,$messages);
	}

   
	public function createRide(Request $request) {

		if($request->vehicle_type == "RENTAL")
		{
			$ride_city_price = RideCityPrice::where('service_type',$request->vehicle_type)->where('ride_delivery_vehicle_id', $request->service_type)->first();
			$rental_package= RentalPackage::where('id',$request->rental_package_id)->first();

		}else if($request->vehicle_type == "OUTSTATION") {
			$ride_city_price = OutstationRideStatePrice::where('ride_delivery_vehicle_id', $request->service_type)->first();
		}
		else{
			$geofence =(new UserServices())->poly_check_request((round($request->s_latitude,6)),(round($request->s_longitude,6)), $this->user->city_id);

			if($geofence == false) {
				return ['status' => 400, 'message' => trans('user.ride.service_not_available_location'), 'error' => trans('user.ride.service_not_available_location')];
			}

			$ride_city_price = RideCityPrice::where('service_type',$request->vehicle_type)->where('geofence_id',$geofence['id'])->where('ride_delivery_vehicle_id', $request->service_type)->first();
		}

		if($ride_city_price == null) {

			return ['status' => 400, 'message' => trans('user.ride.service_not_available_location'), 'error' => trans('user.ride.service_not_available_location')];
		}

		$credit_ride_limit = isset($this->settings->transport->credit_ride_limit) ? $this->settings->transport->credit_ride_limit : 0;

		$ActiveRequests = RideRequest::PendingRequest($this->user->id)->count();

		if($ActiveRequests > $credit_ride_limit) {
			return ['status' => 422, 'message' => trans('api.ride.request_inprogress')];
		}
		
		$timezone =  (Auth::guard('user')->user()->state_id) ? State::find($this->user->state_id)->timezone : '';

		$country =  CompanyCountry::where('country_id', $this->user->country_id)->first();

		$currency =  ($country != null) ? $country->currency : '' ; 

		if($request->has('schedule_date') && $request->schedule_date != "" && $request->has('schedule_time') && $request->schedule_time != ""){

			$schedule_date = (Carbon::createFromFormat('Y-m-d H:i:s', (Carbon::parse($request->schedule_date. ' ' .$request->schedule_time)->format('Y-m-d H:i:s')), $timezone))->setTimezone('UTC'); 


			$beforeschedule_time = (new Carbon($schedule_date))->subHour(1);
			$afterschedule_time = (new Carbon($schedule_date))->addHour(1);


			$CheckScheduling = RideRequest::where('status','SCHEDULED') 
							->where('user_id', $this->user->id)
							->whereBetween('schedule_at',[$beforeschedule_time,$afterschedule_time])
							->count();


			if($CheckScheduling > 0){
				return ['status' => 422, 'message' => trans('api.ride.request_already_scheduled')];
			}

		}

		$distance = $this->settings->transport->provider_search_radius ? $this->settings->transport->provider_search_radius : 100;

		$latitude = $request->s_latitude;
		$longitude = $request->s_longitude;
		$service_type = $request->service_type;


		$child_seat = $request->child_seat != null  ? $request->child_seat : 0 ;
		$wheel_chair = $request->wheel_chair != null ? $request->wheel_chair : 0 ;
		$ride_type = $request->vehicle_type;

		$request->request->add(['latitude' => $request->s_latitude]);
		$request->request->add(['longitude' => $request->s_longitude]);

		$request->request->add(['distance' => $distance]);
		$request->request->add(['provider_negative_balance' => $this->settings->site->provider_negative_balance]);

		$callback = function ($q) use($request) {
			$q->where('status','active');
			$q->where('admin_service', 'TRANSPORT');
			$q->where('ride_delivery_id',$request->service_type);
		};

		$childseat = function($query) use ($child_seat, $wheel_chair,$ride_type){    
					if($child_seat != 0) {
						$query->where('child_seat', $child_seat);
					}
					if($wheel_chair != 0) {
						$query->where('wheel_chair',$wheel_chair);
					}
					if($ride_type =='OUTSTATION')
					{
						$query->where('is_outstation',1);
					}
					if($ride_type =='RENTAL')
					{
						$query->where('is_rental',1);
					}
					};

		$withCallback = ['service' => $callback, 'service.ride_vehicle'];
		$whereHasCallback = ['service' => $callback, 'service.vehicle' => $childseat];

		$Providers = (new UserServices())->availableProviders($request, $withCallback, $whereHasCallback);
		
		if(count($Providers) == 0 && $request->schedule_date == "") {
			return ['status' => 422, 'message' => trans('api.ride.no_providers_found')];
		}     

		// try {
			$route_key = '';
			if($request->vehicle_type != "RENTAL")
			{
				$details = "https://maps.googleapis.com/maps/api/directions/json?origin=".$request->s_latitude.",".$request->s_longitude."&destination=".$request->d_latitude.",".$request->d_longitude."&mode=driving&key=".$this->settings->site->server_key;

				$json = Helper::curl($details);

				$details = json_decode($json, TRUE);

				$route_key = (count($details['routes']) > 0) ? $details['routes'][0]['overview_polyline']['points'] : '';
			}

			$message = "Schedule request created!";
			$rideRequest = new RideRequest;
			$otp=$this->getOtp(mt_rand(1000 , 9999));
			
			if($request->vehicle_type == "RIDE")
			{
				$rideRequest->type = $geofence['type'];
				$rideRequest->geofence_id = $geofence['id'];
			}
			$rideRequest->status = 'SEARCHING';
			$rideRequest->company_id = $this->company_id;
			$rideRequest->admin_service = 'TRANSPORT';
			$rideRequest->service_type = $request->vehicle_type;
			$rideRequest->rental_package_id = $request->rental_package_id;
			$prefix = $this->settings->transport->booking_prefix;
			$rideRequest->booking_id = Helper::generate_booking_id($prefix);
			$rideRequest->user_id = $this->user->id;
			//$rideRequest->provider_service_id = $request->service_type;
			$rideRequest->ride_type_id = $request->ride_type_id;
	
			$rideRequest->payment_mode = $request->payment_mode;
			$rideRequest->promocode_id = $request->promocode_id ? : 0;
			
			$rideRequest->timezone = $timezone;
			$rideRequest->currency = $currency;
			if($this->settings->transport->manual_request == "1") $rideRequest->request_type = "MANUAL";
			$rideRequest->country_id = $this->user->country_id;
			$rideRequest->city_id = $this->user->city_id;
			$rideRequest->state_id = $this->user->state_id;
			$rideRequest->s_address = $request->s_address ? $request->s_address : "";
			$rideRequest->d_address = $request->d_address ? $request->d_address  : "";
			$rideRequest->s_latitude = $request->s_latitude;
			$rideRequest->s_longitude = $request->s_longitude;

			if($request->vehicle_type == "RENTAL")
			{
				$rideRequest->distance = $rental_package->km;
				$rideRequest->d_latitude = $request->s_latitude;
				$rideRequest->d_longitude = $request->s_longitude;
			}else{
				$rideRequest->distance = (count($details['routes']) > 0) ? ($details['routes'][0]['legs'][0]['distance']['value'] ) : 0;
				$rideRequest->d_latitude = $request->d_latitude;
				$rideRequest->d_longitude = $request->d_longitude;
			}

			if($request->vehicle_type == "OUTSTATION")
			{	
				$rideRequest->outstation_type = $request->outstation_type;
				if($request->outstation_type=="TWOWAY"){
					$return_date = (Carbon::createFromFormat('Y-m-d H:i:s', (Carbon::parse($request->return_day. ' ' .$request->return_time)->format('Y-m-d H:i:s')), $timezone))->setTimezone('UTC');
					$departure_date =(Carbon::createFromFormat('Y-m-d H:i:s', (Carbon::parse($request->depart_day. ' ' .$request->depart_time)->format('Y-m-d H:i:s')), $timezone))->setTimezone('UTC');
					$rideRequest->return_date = $return_date;
					$rideRequest->departure_date = $departure_date;
				}else{
					$departure_date =(Carbon::createFromFormat('Y-m-d H:i:s', (Carbon::parse($request->depart_day. ' ' .$request->depart_time)->format('Y-m-d H:i:s')), $timezone))->setTimezone('UTC');
					$rideRequest->departure_date = $departure_date;
				}

				$futurehours = (new Carbon($departure_date))->subHour(1);
				$currentDate = Carbon::now()->addHour();
				if($currentDate < $futurehours)
				{
					$rideRequest->status = 'SCHEDULED';
					$rideRequest->schedule_at = $departure_date;
					$rideRequest->is_scheduled = 'YES';
					
					$message = 'Your Outstation is scheduled for '.$request->depart_time.' '.$request->depart_day.'. Ride details Will be sent to you by before one hour.';
				}
			}
			
			$rideRequest->ride_delivery_id = $service_type;
			// dd($request->someone);
			if($request->has('someone') && $request->someone==1){
				$rideRequest->someone=$request->someone;
				$rideRequest->someone_name=$request->someone_name;
				$rideRequest->someone_mobile=$request->someone_mobile;
				$rideRequest->someone_email=$request->someone_email;
				 try{
					  if(!empty($request->someone_email)&&!empty($this->settings->site->send_email) && $this->settings->site->send_email == 1 && $this->settings->transport->ride_otp) {
						 Mail::send('mails/someone', ['settings' => $this->settings,'user'=>$this->user,'otp'=>$otp], function ($message) use($request) {
							$message->from($this->settings->site->mail_from_address, $this->settings->site->mail_from_name);
							$message->to($request->someone_email, $this->user->first_name.' '.$this->user->last_name)->subject('Notification');
						  });
					   }

					    if(!empty($request->someone_mobile)&& !empty($this->settings->site->send_sms) && $this->settings->site->send_sms == 1 && $this->settings->transport->ride_otp)
					    {
					    	 $plusCodeMobileNumber='+'.$this->user->country_code.$request->someone_mobile;
					    	 $smsMessage =Auth::guard('user')->user()->first_name. ' ' . Auth::guard('user')->user()->last_name.' has booked a ride for you in '. $this->settings->site->site_title .'. Your Ride OTP '.$otp;
					    	Helper::send_sms($this->company_id,$plusCodeMobileNumber, $smsMessage);
						 
					   }  

				   }catch (\Throwable $e) { 
					   throw new \Exception($e->getMessage());
					}   
			}
			$rideRequest->track_distance = 1;
			$rideRequest->track_latitude = $request->s_latitude;
			$rideRequest->track_longitude = $request->s_longitude;
			if($request->d_latitude == null && $request->d_longitude == null) $rideRequest->is_drop_location = 0;
			$rideRequest->destination_log = json_encode([['latitude' => $rideRequest->d_latitude, 'longitude' => $request->d_longitude, 'address' => $request->d_address]]);
			$rideRequest->unit = isset($country->distance_unit) ? $country->distance_unit : 'Miles';
			if($this->user->wallet_balance > 0) $rideRequest->use_wallet = $request->use_wallet ? : 0;
			$rideRequest->is_track = "YES";
			$rideRequest->otp = $otp;
			$rideRequest->assigned_at = Carbon::now();
			$rideRequest->route_key = $route_key;
			if($Providers->count() <= (isset($this->settings->transport->surge_trigger) ? $this->settings->transport->surge_trigger : 0) && $Providers->count() > 0){
				$rideRequest->surge = 1;
			}

			if($request->has('schedule_date') && $request->schedule_date != "" && $request->has('schedule_time') && $request->schedule_time != ""){
				$rideRequest->status = 'SCHEDULED';
				$rideRequest->schedule_at = (Carbon::createFromFormat('Y-m-d H:i:s', (Carbon::parse($request->schedule_date. ' ' .$request->schedule_time)->format('Y-m-d H:i:s')), $timezone))->setTimezone('UTC');
				$rideRequest->is_scheduled = 'YES';
			}

			$rideRequest->save();

			// update payment mode
			User::where('id', $this->user->id)->update(['payment_mode' => $request->payment_mode]);

			if($request->has('card_id')){
				Card::where('user_id',Auth::guard('user')->user()->id)->update(['is_default' => 0]);
				Card::where('card_id',$request->card_id)->update(['is_default' => 1]);
			}

			$rideRequest = RideRequest::with('ride', 'ride_type','rental_package')->where('id', $rideRequest->id)->first();

			(new UserServices())->createRequest($Providers, $rideRequest, 'TRANSPORT');
			
			return ['message' => ($rideRequest->status == 'SCHEDULED') ? 'Schedule request created!' : 'New request created!', 'data' => [
						'message' => ($rideRequest->status == 'SCHEDULED') ? $message : 'New request created!',
						'request' => $rideRequest->id,
						'status'=>($rideRequest->service_type == 'OUTSTATION') ? ($rideRequest->status == 'SCHEDULED') ? $rideRequest->status : '' : '',
					]];

		// } catch (Exception $e) {  
		// 	throw new \Exception($e->getMessage());
		// }
		

	}
	public function getOtp($otp) {
		$rideOtp = RideRequest::where('otp', $otp)->whereNotIn('status', ['CANCELLED', 'COMPLETED'])->first();

		if($rideOtp != null) {
			$this->getOtp(mt_rand(1000 , 9999));
		}

		return $otp;
	}

	public function cancelRide($request) {

		try{

			$rideRequest = RideRequest::findOrFail($request->id);

			if($rideRequest->status == 'CANCELLED')
			{
				return ['status' => 404, 'message' => trans('api.ride.already_cancelled')];
			}

			if(in_array($rideRequest->status, ['SEARCHING','STARTED','ARRIVED','SCHEDULED'])) {

				if($rideRequest->status != 'SEARCHING'){

					$validator = Validator::make($request->all(), [
						'cancel_reason'=> 'max:255',]);

					if ($validator->fails()) {

						$errors = [];
						foreach (json_decode( $validator->errors(), true ) as $key => $error) {
						   $errors[] = $error[0];
						}

						header("Access-Control-Allow-Origin: *");
						header("Access-Control-Allow-Headers: *");
						header('Content-Type: application/json');
						http_response_code(422);
						echo json_encode(Helper::getResponse(['status' => 422, 'message' => !empty($errors[0]) ? $errors[0] : "",  'error' => !empty($errors[0]) ? $errors[0] : "" ])->original);
						exit;
					}
				}

				$rideRequest->status = 'CANCELLED';

				if($request->cancel_reason=='ot')
					$rideRequest->cancel_reason = $request->cancel_reason_opt;
				else
					$rideRequest->cancel_reason = $request->cancel_reason;

				$rideRequest->cancelled_by = $request->cancelled_by;
				$rideRequest->save();
				$request->request->add(['admin_service' => 'TRANSPORT']);
				if($request->cancelled_by == "PROVIDER") {
					if($this->settings->transport->broadcast_request == 1){
						(new ProviderServices())->cancelRequest($request);
						(new SendPushNotification)->ProviderCancelRide($rideRequest,'transport');
						return ['status' => 200, 'message' => trans('api.ride.request_rejected') ];
					 }else{
					 	(new ProviderServices())->cancelRequest($request);
					 	(new SendPushNotification)->ProviderCancelRide($rideRequest,'transport');
						return ['status' => 200, 'message' => trans('api.ride.request_rejected') ];
						// (new ProviderServices())->assignNextProvider($rideRequest->id, $rideRequest->admin_service );
						// return ['status' => 200, 'message' => trans('api.ride.request_rejected'),'data' => $rideRequest->with('user')->get() ];
					 }
				} else {
					(new UserServices())->cancelRequest($rideRequest);
					(new SendPushNotification)->UserCancelRide($rideRequest->provider_id,'transport');
				}

				return ['status' => 200, 'message' => trans('api.ride.ride_cancelled')];

			} else {

				return ['status' => 403, 'message' => trans('api.ride.already_onride')];
			}
		}

		catch (ModelNotFoundException $e) {
			return $e->getMessage();
		}
	}

	public function extendTrip(Request $request) {
		try{

			$rideRequest = RideRequest::select('id')->findOrFail($request->id);

			$details = "https://maps.googleapis.com/maps/api/directions/json?origin=".$rideRequest->s_latitude.",".$rideRequest->s_longitude."&destination=".$request->latitude.",".$request->longitude."&mode=driving&key=".$this->settings->site->server_key;

			$json = Helper::curl($details);

			$details = json_decode($json, TRUE);

			$route_key = (count($details['routes']) > 0) ? $details['routes'][0]['overview_polyline']['points'] : '';

			$destination_log = json_decode($rideRequest->destination_log);
			$destination_log[] = ['latitude' => $request->latitude, 'longitude' => $request->longitude, 'address' => $request->address];

			$rideRequest->d_latitude = $request->latitude;
			$rideRequest->d_longitude = $request->longitude;
			$rideRequest->d_address = $request->address;
			$rideRequest->route_key = $route_key;
			$rideRequest->destination_log = json_encode($destination_log);

			$rideRequest->save();

			$message = trans('api.destination_changed');

			(new SendPushNotification)->sendPushToProvider($rideRequest->provider_id, 'transport', $message);

			(new SendPushNotification)->sendPushToUser($rideRequest->user_id, 'transport', $message); 

			//Send message to socket
			$requestData = ['type' => 'TRANSPORT', 'room' => 'room_'.$this->company_id, 'id' => $rideRequest->id, 'city' => ($this->settings->demo_mode == 0) ? $rideRequest->city_id : 0, 'user' => $rideRequest->user_id ];
			app('redis')->publish('checkTransportRequest', json_encode( $requestData ));

			return $rideRequest;

		} catch (\Throwable $e) {
			return $e->getMessage() ;
		}
	}

	public function updateRide(Request $request) { 
		try{

			$ride_otp = $this->settings->transport->ride_otp;

			$rideRequest = RideRequest::with('user','rental_package')->findOrFail($request->id);

			//Add the Log File for ride
			$user_request = UserRequest::where('request_id', $request->id)->where('admin_service', 'TRANSPORT')->first();

			if($request->status == 'DROPPED' && $request->d_latitude != null && $request->d_longitude != null) {

				$rideRequest->d_latitude = $request->d_latitude;
				$rideRequest->d_longitude = $request->d_longitude;
				$rideRequest->d_address = $request->d_address;
				$rideRequest->save();

				$details = "https://maps.googleapis.com/maps/api/directions/json?origin=".$rideRequest->s_latitude.",".$rideRequest->s_longitude."&destination=".$request->d_latitude.",".$request->d_longitude."&mode=driving&key=".$this->settings->site->server_key;

				$json = Helper::curl($details);

				$details = json_decode($json, TRUE);

				$route_key = (count($details['routes']) > 0) ? $details['routes'][0]['overview_polyline']['points'] : '';

				$rideRequest->route_key = $route_key;
				
			}

			if($request->status == 'DROPPED' && $rideRequest->payment_mode != 'CASH') {
				$rideRequest->status = 'COMPLETED';
				$rideRequest->paid = 0;

				(new SendPushNotification)->Complete($rideRequest, 'transport');
			} else if ($request->status == 'COMPLETED' && $rideRequest->payment_mode == 'CASH') {
				
				if($rideRequest->status=='COMPLETED'){
					//for off cross clicking on change payment issue on mobile
					return true;
				}
				
				$rideRequest->status = $request->status;
				$rideRequest->paid = 1;                
				
				(new SendPushNotification)->Complete($rideRequest, 'transport');

				//for completed payments
				$RequestPayment = RideRequestPayment::where('ride_request_id', $request->id)->first();
				if($RequestPayment->is_partial != 1 && $RequestPayment->wallet > 0)
				{
					$RequestPayment->payment_mode = 'WALLET';	
				}
				else{
					$RequestPayment->payment_mode = 'WALLET';
				}
				
				$RequestPayment->cash = $RequestPayment->payable;
				$RequestPayment->payable = 0;                
				$RequestPayment->save();               

			} else {
				$rideRequest->status = $request->status;

				if($request->status == 'ARRIVED'){
					(new SendPushNotification)->Arrived($rideRequest, 'transport');
				}
			}

			if($request->status == 'PICKEDUP'){

				$provider = Provider::find($rideRequest->provider_id);
				
				if($provider->airport_at != NULL)
				{
					Provider::where('id', $provider->id)->update(['airport_at' => NULL]);
				}
				
				if($this->settings->transport->ride_otp==1 && $rideRequest->created_type != "ADMIN"){
					if(isset($request->otp) && $rideRequest->request_type != "MANUAL"){
						if($request->otp == $rideRequest->otp){
							$rideRequest->started_at = Carbon::now();
							(new SendPushNotification)->Pickedup($rideRequest, 'transport');
						}else{
							header("Access-Control-Allow-Origin: *");
							header("Access-Control-Allow-Headers: *");
							header('Content-Type: application/json');
							http_response_code(400);
							echo json_encode(Helper::getResponse(['status' => 400, 'message' => trans('api.otp'), 'data'=>$rideRequest,  'error' => trans('api.otp')])->original);
							exit;
						}
					}else{
						$rideRequest->started_at = Carbon::now();
						(new SendPushNotification)->Pickedup($rideRequest, 'transport');
					}
				}else{
					$rideRequest->started_at = Carbon::now();
					(new SendPushNotification)->Pickedup($rideRequest, 'transport');
				}
			}

			$rideRequest->save();

			if($request->status == 'DROPPED') {

				$waypoints = [];

				$chat=Chat::where('admin_service', 'TRANSPORT')->where('request_id', $rideRequest->id)->where('company_id', Auth::guard('provider')->user()->company_id)->first();

				if($chat != null) {
					$chat->delete();
				}

				if($request->has('distance') && $rideRequest->distance != null) {
					$rideRequest->distance  = $request->distance; 
				}

				if($request->has('location_points') && $rideRequest->location_points != null) {

					foreach($request->location_points as $locations) {
						$waypoints[] = $locations['lat'].",".$locations['lng'];
					}

					$details = "https://maps.googleapis.com/maps/api/directions/json?origin=".$rideRequest->s_latitude.",".$rideRequest->s_longitude."&destination=".$request->latitude.",".$request->longitude."&waypoints=" . implode($waypoints, '|')."&mode=driving&key=".$this->settings->site->server_key;

					$json = Helper::curl($details);

					$details = json_decode($json, TRUE);

					$route_key = (count($details['routes']) > 0) ? $details['routes'][0]['overview_polyline']['points'] : '';

					$rideRequest->route_key = $route_key;
					$rideRequest->location_points = json_encode($request->location_points);
				}
				$rideRequest->finished_at = Carbon::now();
				$StartedDate  = date_create($rideRequest->started_at);
				$FinisedDate  = Carbon::now();
				$TimeInterval = date_diff($StartedDate,$FinisedDate);
				$MintuesTime  = $TimeInterval->i;
				$rideRequest->travel_time = $MintuesTime;
				$rideRequest->save();
				$rideRequest->with('user','rental_package')->findOrFail($request->id);
				$invoice = $this->invoice($request->id, ($request->toll_price != null) ? $request->toll_price : 0);
				$rideRequest->invoice = ($invoice) ? $invoice : (object)[];
			   
				if(!empty((array) $rideRequest->invoice)) {
					(new SendPushNotification)->Dropped($rideRequest, 'transport');
				}
				

			}

			$user_request->provider_id = $rideRequest->provider_id;
			$user_request->status = $rideRequest->status;
			$user_request->request_data = json_encode($rideRequest);

			$user_request->save();

			//Send message to socket
			$requestData = ['type' => 'TRANSPORT', 'room' => 'room_'.$this->company_id, 'id' => $rideRequest->id, 'city' => ($this->settings->demo_mode == 0) ? $rideRequest->city_id : 0, 'user' => $rideRequest->user_id ];
			app('redis')->publish('checkTransportRequest', json_encode( $requestData ));
			
			// Send Push Notification to User
			return ['data' => $rideRequest ];

		} catch (Exception $e) {
			throw new \Exception($e->getMessage());
		}
	}

	public function calculateFare($request, $cflag=0){


		try{
			$total=$tax_price='';
			if($request['vehicle_type'] != "RENTAL")
			{
				$location=$this->getLocationDistance($request);
			}
			

			$settings = json_decode(json_encode(Setting::where('company_id', $request['company_id'])->first()->settings_data));
			$siteConfig = $settings->site;
			
			if($request['vehicle_type'] == "RENTAL")
			{
				$ride_city_price = RideCityPrice::where('service_type',$request['vehicle_type'])->where('city_id',$request['city_id'])->where('ride_delivery_vehicle_id', $request['service_type'])->first();
				$rental_package= RentalPackage::where('id',$request['rental_package_id'])->first();

			}elseif ($request['vehicle_type'] == "OUTSTATION") {
				$ride_city_price = OutstationRideStatePrice::where('state_id',$request['state_id'])->where('ride_delivery_vehicle_id', $request['service_type'])->first();
			}
			else{
				$ride_city_price = RideCityPrice::where('service_type',$request['vehicle_type'])->where('geofence_id',$request['geofence_id'])->where('ride_delivery_vehicle_id', $request['service_type'])->first();

				if($siteConfig->unit_measurement=='Kms')
					$kilometer = round($location['meter']/1000,1); //TKM
				else
					$kilometer = round($location['meter']/1609.344,1); //TMi

				if($ride_city_price != null && $kilometer > $ride_city_price->city_limit)
				{
					header("Access-Control-Allow-Origin: *");
					header("Access-Control-Allow-Headers: *");
					header('Content-Type: application/json');
					http_response_code(422);
					echo json_encode(Helper::getResponse(['status' => 422, 'message' =>"Drop location is outside city limits",  'error' => 'Drop location is outside city limits' ])->original);
					exit;
				}
			}

			if($ride_city_price == null)
			{

				header("Access-Control-Allow-Origin: *");
				header("Access-Control-Allow-Headers: *");
				header('Content-Type: application/json');
				http_response_code(422);
				echo json_encode(Helper::getResponse(['status' => 422, 'message' =>trans('user.ride.service_not_available_location'),  'error' => trans('user.ride.service_not_available_location') ])->original);
				exit;
				
			}
			
			$transportConfig = $settings->transport;

			if($request['vehicle_type'] == "RENTAL")
			{
				$total_kilometer = $rental_package->km;
				$time = $rental_package->hour.' hr';
				$return_data['rental_km_price']=$this->applyNumberFormat(floatval($ride_city_price->rental_km_price));
				$return_data['rental_hour_price']= $this->applyNumberFormat(floatval($ride_city_price->rental_hour_price));

			}else{

				if(!empty($location['errors'])){
					throw new Exception($location['errors']);
				}
				else{

					if($siteConfig->unit_measurement=='Kms')
						$total_kilometer = round($location['meter']/1000,1); //TKM
					else
						$total_kilometer = round($location['meter']/1609.344,1); //TMi

					$time = $location['time'];
					$requestarr['time']=$location['time'];
					$requestarr['seconds']=$location['seconds'];
				}
			}	
				
				$requestarr['vehicle_type']=$request['vehicle_type'];
				$requestarr['rental_package_id']=isset($request['rental_package_id'])? $request['rental_package_id']:0;
				$requestarr['city_id']=$request['city_id'];
				$requestarr['state_id']=$request['state_id'];
				$requestarr['meter']=$total_kilometer;
				$requestarr['kilometer']=0;
				$requestarr['minutes']=0;
				$requestarr['service_type']=$request['service_type'];

				if($request['vehicle_type'] == "OUTSTATION")
				{
					$requestarr['outstation_type']=$request['outstation_type'];
					$requestarr['depart_day']=date('Y-m-d', strtotime($request['depart_day']));
					$requestarr['depart_time']=$request['depart_time'];
					$requestarr['return_day']=isset($request['return_day']) ? date('Y-m-d', strtotime($request['return_day'])):'';
				}

				$requestarr['geofence_id']=isset($request['geofence_id']) ? $request['geofence_id'] : 0;               

				$tax_percentage = $ride_city_price->tax;
				$commission_percentage = $ride_city_price->commission;
				$surge_trigger = isset($transportConfig->surge_trigger) ? $transportConfig->surge_trigger : 0 ;
			   
				$price_response=$this->applyPriceLogic($requestarr);

				$total = $price_response['price'];
				$promo = 0;
				$driver_allowance = 0;
				if(isset($price_response['driver_allowance']))
				{
					$driver_allowance = $price_response['driver_allowance'];
					$total += $driver_allowance;
					$return_data['driver_allowance']=$driver_allowance;
				}
				if(!empty($request['promocode_id']))
				{
					$percent_total = $total * $request['percentage']/100;

			        if($percent_total > $request['max_amount']) {
			          $promo = floatval($request['max_amount']);
			        }else{
			          $promo = floatval($percent_total);
			        }
			        $total = $total-$promo;
		    	}

				if($cflag!=0){

					if($commission_percentage>0){

						$commission_price = $this->applyPercentage($price_response['price'],$commission_percentage);
						//$total = $total;

					}
				   
					$peak = 0;

					$start_time = Carbon::now()->toDateTimeString();
					
					
					$peak_percentage = 1+(0/100)."X";
					
					$start_time_check = PeakHour::where('start_time', '<=', $start_time)->where('end_time', '>=', $start_time)->where('city_id',$request['city_id'])->where('company_id', '=', $request['company_id'])->first(); 

					$start_time_check = PeakHour::whereRaw("IF( start_time > end_time,  CONCAT( subdate(CURDATE(), 1), ' ', start_time ), CONCAT( CURDATE(), ' ', start_time ) ) <= '$start_time'")
					->whereRaw("CONCAT( CURDATE(), ' ', end_time ) >= '$start_time'")
					->where('city_id', $request['city_id'])->where('company_id', $request['company_id'])
					->first();
					if($request['vehicle_type'] != "OUTSTATION")
					{
						if($start_time_check){
						
							if($request['vehicle_type'] == "RENTAL")
							{
								$RideCityPrice = RideCityPrice::where('service_type',$request['vehicle_type'])->where('city_id',$request['city_id'])->where('ride_delivery_vehicle_id', $request['service_type'])->first();
							}
							else
							{
								$RideCityPrice = RideCityPrice::where('geofence_id', $request['geofence_id'])->where('ride_delivery_vehicle_id', $request['service_type'])->where('company_id', $request['company_id'] )->first();
							}

							

							$Peakcharges = RidePeakPrice::where('ride_city_price_id', $RideCityPrice->id)->where('ride_delivery_id', $request['service_type'])->where('peak_hour_id',$start_time_check->id)->first();


							if($Peakcharges){                            
								$peak_price=($Peakcharges->peak_price/100) * $price_response['base_price'];
								$total += $peak_price;
								$peak = 1;
								$peak_percentage = 1+($Peakcharges->peak_price/100)."X";
							}
						}

					}
				} 
				if($tax_percentage>0){
					$tax_price = $this->applyPercentage($total,$tax_percentage);
					$total = $total + $tax_price;

				}
				if ($request['vehicle_type'] == "OUTSTATION") {   
					$return_data['driver_beta']=$this->applyNumberFormat(floatval($ride_city_price->driver_allowance));
					$return_data['night_time_allowance']=$this->applyNumberFormat(floatval($ride_city_price->night_time_allowance));
					$return_data['per_hour_price']=$this->applyNumberFormat(floatval($ride_city_price->per_hour_price));
					$return_data['per_km_price']=$this->applyNumberFormat(floatval($ride_city_price->per_km_price));
				}
				$return_data['estimated_fare']=$this->applyNumberFormat(floatval($total)); 
				$return_data['distance']=$total_kilometer;  
				$return_data['coupon_amount']=$promo;  
				$return_data['time']=$time;
				$return_data['tax_price']=$this->applyNumberFormat(floatval($tax_price));    
				$return_data['base_price']=$this->applyNumberFormat(floatval($price_response['price']));    
				$return_data['service_type']=(int)$request['service_type'];   
				$return_data['service']=$price_response['service_type'];   

				if(Auth::guard('user')->user()){
					$return_data['peak']=$peak;    
					$return_data['peak_percentage']=$peak_percentage;   
					$return_data['wallet_balance']=$this->applyNumberFormat(floatval(Auth::guard('user')->user()->wallet_balance));  
				}

				$service_response["data"]=$return_data;

			return $service_response;  

		} catch(Exception $e) {
			$service_response["errors"]=$e->getMessage();
		}
	
		  
	} 

	public function applyPriceLogic($requestarr,$iflag=0){

		$fn_response=array();
		if($requestarr['vehicle_type'] == "RENTAL")
		{
			$ride_city_price = RideCityPrice::where('service_type',$requestarr['vehicle_type'])->where('city_id',$requestarr['city_id'])->where('ride_delivery_vehicle_id', $requestarr['service_type'])->first();

			$rental_package= RentalPackage::where('id',$requestarr['rental_package_id'])->first();

			$settings = json_decode(json_encode(Setting::where('company_id', $this->company_id)->first()->settings_data));

			$siteConfig = $settings->transport;


		}elseif ($requestarr['vehicle_type'] == "OUTSTATION") {
			$ride_city_price = OutstationRideStatePrice::where('state_id',$requestarr['state_id'])->where('ride_delivery_vehicle_id', $requestarr['service_type'])->first();
		}
		else{
			$ride_city_price = RideCityPrice::where('service_type',$requestarr['vehicle_type'])->where('geofence_id',$requestarr['geofence_id'])->where('ride_delivery_vehicle_id', $requestarr['service_type'])->first();
		}
		

		if($ride_city_price == null) {
			header("Access-Control-Allow-Origin: *");
			header("Access-Control-Allow-Headers: *");
			header('Content-Type: application/json');
			http_response_code(400);
			echo json_encode(Helper::getResponse(['status' => 400, 'message' => trans('user.ride.service_not_available_location'),  'error' => trans('user.ride.service_not_available_location')])->original);
			exit;
		}


		$fn_response['service_type']=$requestarr['service_type'];
		

		$price = 0;
		
		if($requestarr['vehicle_type'] == "RENTAL")
		{ 	
			//RENTAL START
			$total_hour_fare =0;
            $total_km_fare =0;
			$total_minutes = 0;
			$total_kilometer= ($rental_package == null) ? 0 : $rental_package->km; //BD       
			$base_price= ($rental_package == null) ? 0 : $rental_package->price; //BP
			$total_hours= ($rental_package == null) ? 0 : $rental_package->hour; //PH
			$price =($rental_package == null) ? 0 : $rental_package->price;

			if($iflag == 1){
				//for invoice fare
				$total_kilometer = $requestarr['kilometer']; //TKM || TMi
				//$total_minutes = $requestarr['minutes']; //TM        
				$total_hours= $requestarr['minutes']/60; //TH
				if($siteConfig->rental_package_change==1)
				{
					if($rental_package->hour < $total_hours || $rental_package->km < $total_kilometer)
	                {   
	                    if($rental_package->hour < $total_hours)
	                    {
							$rental_packages= RentalPackage::where('ride_city_price_id',$ride_city_price->id)->where('hour','<=', $total_hours)->orderBy('hour', 'DESC')->limit(1)->get();
						}
						if($rental_package->km < $total_kilometer)
	                    {
	                       $rental_packages= RentalPackage::where('ride_city_price_id',$ride_city_price->id)->where('km','<=', $total_kilometer)->orderBy('hour', 'DESC')->limit(1)->get();
	                    }
	                    if(!empty($rental_packages))
	                    	$rental_package= RentalPackage::find($rental_packages[0]->id);
					}

				}

				if($rental_package->hour < $total_hours || $rental_package->km < $total_kilometer)
                {   
                    if($rental_package->hour < $total_hours)
                    {
                        $total_hour_fare = ($ride_city_price->rental_hour_price*($total_hours-$rental_package->hour));
                    }
                    if($rental_package->km < $total_kilometer)
                    {
                        $total_km_fare = ($ride_city_price->rental_km_price*($total_kilometer-$rental_package->km));
                    }

                    $price = $total_hour_fare+$total_km_fare+$rental_package->price;   
                }
            }
            $ride_city_price['km'] = $rental_package->km;
            $fn_response['price']=$price;
			$fn_response['base_price']=$base_price;
			$fn_response['distance_fare']= $total_km_fare;
			$fn_response['hour_fare'] = $total_hour_fare;
			$fn_response['minute_fare']=$total_minutes;
			$fn_response['ride_city_price']=$ride_city_price;
			$fn_response['calculator']='RENTAL';
			return $fn_response;
			//RENTAL END
		}
		else if($requestarr['vehicle_type'] == "OUTSTATION") {
			// OUTSTATION START
			$distance_fare =0;
			if($iflag==0){
				//for estimated fare
				$total_kilometer = $requestarr['meter']; //TKM || TMi
				$total_minutes = round($requestarr['seconds']/60); //TM        
				$total_hours=($requestarr['seconds']/60)/60; //TH

		 		$begin = new DateTime($requestarr['depart_day']);
                $end   = new DateTime($requestarr['return_day']);

                $total_days =  $end->diff($begin)->format("%a")+1;

	            $depart_day = $requestarr['depart_day'];
	            $return_day = $requestarr['return_day'];
	            $outstation_type = $requestarr['outstation_type']; 	 
	            
                if($outstation_type == 'TWOWAY')
                {
                    $total_kilometer = $total_kilometer * 2;
                    $base_price = $ride_city_price->distance * $ride_city_price->roundtrip_price;

                    if($ride_city_price->distance < $total_kilometer)
                    	$distance_fare=($total_kilometer - $ride_city_price->distance)*$ride_city_price->roundtrip_price;

                    $price = (($total_kilometer * $ride_city_price->roundtrip_price) + ($ride_city_price->driver_allowance * $total_days));

                    $driver_allowance = $ride_city_price->driver_allowance * $total_days;
                }
                else
                {	
                	$base_price = $ride_city_price->distance * $ride_city_price->oneway_price;

                	if($ride_city_price->distance < $total_kilometer)
                		$distance_fare =($total_kilometer - $ride_city_price->distance)*$ride_city_price->oneway_price;

                    $price = ($total_kilometer * $ride_city_price->oneway_price);

                    $driver_allowance = $ride_city_price->driver_allowance ;
                }
                
			}
			else{
				//for invoice fare
				$total_kilometer = $requestarr['kilometer']; //TKM || TMi       
				$total_minutes = $requestarr['minutes']; //TM        
				$total_hours= $requestarr['minutes']/60; //TH

				$StartedDate  = date_create($requestarr['started_at']);
	            $FinisedDate  = date_create($requestarr['finished_at']);
	            $TimeInterval = date_diff($StartedDate,$FinisedDate);
	            $total_days = $TimeInterval->days+1;

	            if($requestarr['outstation_type'] == 'TWOWAY')
                {
                    $total_kilometer = $total_kilometer * 2;
                    $base_price = $ride_city_price->distance * $ride_city_price->roundtrip_price;

                    if($ride_city_price->distance < $total_kilometer)
                    	$distance_fare=($total_kilometer - $ride_city_price->distance)*$ride_city_price->roundtrip_price;

                    $price = ($total_kilometer * $ride_city_price->roundtrip_price) ;

                    $driver_allowance = $ride_city_price->driver_allowance * $total_days;
                }
                else
                {	
                	$base_price = $ride_city_price->distance * $ride_city_price->oneway_price;

                	if($ride_city_price->distance < $total_kilometer)
                		$distance_fare =($total_kilometer - $ride_city_price->distance)*$ride_city_price->oneway_price;

                    $price = ($total_kilometer * $ride_city_price->oneway_price);

                    $driver_allowance = $ride_city_price->driver_allowance;
                }

            	
			}
		
			$fn_response['driver_allowance'] = $driver_allowance;
			$fn_response['total_days'] = $total_days;
			$fn_response['total_kilometer'] = $total_kilometer;
            $fn_response['price']=$price;
			$fn_response['base_price']=$base_price;
			$fn_response['distance_fare']=$distance_fare;

			$fn_response['hour_fare'] = 0;
			$fn_response['minute_fare']=$total_minutes* $ride_city_price->per_hour_price;

			$fn_response['ride_city_price']=$ride_city_price;
			$fn_response['calculator']='OUTSTATION';
			return $fn_response;
		// OUTSTATION END
		}
		else
		{       
			if($iflag==0){
				//for estimated fare
				$total_kilometer = $requestarr['meter']; //TKM || TMi
				$total_minutes = round($requestarr['seconds']/60); //TM        
				$total_hours=($requestarr['seconds']/60)/60; //TH
			}
			else{
				//for invoice fare
				$total_kilometer = $requestarr['kilometer']; //TKM || TMi       
				$total_minutes = $requestarr['minutes']; //TM        
				$total_hours= $requestarr['minutes']/60; //TH
			}
		   
			$per_minute= ($ride_city_price == null) ? 0 : $ride_city_price->minute; //PM
			$per_hour= ($ride_city_price == null) ? 0 : $ride_city_price->hour; //PH
			$per_kilometer= ($ride_city_price == null) ? 0 : $ride_city_price->price; //PKM
			$base_distance= ($ride_city_price == null) ? 0 : $ride_city_price->distance; //BD       
			$base_price= ($ride_city_price == null) ? 0 : $ride_city_price->fixed; //BP

			if($ride_city_price != null) {
				if($ride_city_price->calculator == 'MIN') {
					//BP+(TM*PM)
					$price = $base_price+($total_minutes * $per_minute);
				} else if($ride_city_price->calculator == 'HOUR') {
					//BP+(TH*PH)
					$price = $base_price+($total_hours * $per_hour);
				} else if($ride_city_price->calculator == 'DISTANCE') {
					//BP+((TKM-BD)*PKM)  
					if($base_distance>$total_kilometer){
						$price = $base_price;
					}else{
						$price = $base_price+(($total_kilometer - $base_distance)*$per_kilometer);            
					}         
				} else if($ride_city_price->calculator == 'DISTANCEMIN') {
					//BP+((TKM-BD)*PKM)+(TM*PM)
					if($base_distance>$total_kilometer){
						$price = $base_price+($total_minutes * $per_minute);
					}
					else{
						$price = $base_price+((($total_kilometer - $base_distance)*$per_kilometer)+($total_minutes * $per_minute));
					}    
				} else if($ride_city_price->calculator == 'DISTANCEHOUR') {
					//BP+((TKM-BD)*PKM)+(TH*PH)
					if($base_distance>$total_kilometer){
						$price = $base_price+($total_hours * $per_hour);
					}
					else{
						$price = $base_price+((($total_kilometer - $base_distance)*$per_kilometer)+($total_hours * $per_hour));
					}    
				} else {
					//by default set Ditance price BP+((TKM-BD)*PKM) 
					$price = $base_price+(($total_kilometer - $base_distance)*$per_kilometer);
				}
			}
		}

		$fn_response['price']=$price;
		$fn_response['base_price']=$base_price;
		if($base_distance>$total_kilometer){
			$fn_response['distance_fare']=0;
		}
		else{
			$fn_response['distance_fare']=($total_kilometer - $base_distance)*$per_kilometer;
		}    
		$fn_response['minute_fare']=$total_minutes * $per_minute;
		$fn_response['hour_fare']=$total_hours * $per_hour;
		$fn_response['calculator']=($ride_city_price == null) ? null : $ride_city_price->calculator;
		$fn_response['ride_city_price']=$ride_city_price;
		
		return $fn_response;
	}

	public function applyPercentage($total,$percentage){
		return ($percentage/100)*$total;
	}

	public function applyNumberFormat($total){
		return round($total, Helper::setting()->site->round_decimal != "" ? Helper::setting()->site->round_decimal : 2 );
	}
	
	public function getLocationDistance($locationarr){

		$fn_response=array('data'=>null,'errors'=>null);

		try{

			$s_latitude = $locationarr['s_latitude'];
			$s_longitude = $locationarr['s_longitude'];
			$d_latitude = empty($locationarr['d_latitude']) ? $locationarr['s_latitude'] : $locationarr['d_latitude'];
			$d_longitude = empty($locationarr['d_longitude']) ? $locationarr['s_longitude'] : $locationarr['d_longitude'];
			
			$apiurl = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=".$s_latitude.",".$s_longitude."&destinations=".$d_latitude.",".$d_longitude."&mode=driving&sensor=false&units=imperial&key=".$locationarr['server_key'];
		
			$client = new Client;
			$location = $client->get($apiurl);           
			$location = json_decode($location->getBody(),true);
		   
			if(!empty($location['rows'][0]['elements'][0]['status']) && $location['rows'][0]['elements'][0]['status']=='ZERO_RESULTS'){
				throw new Exception("Out of service area", 1);
				
			}
			$fn_response["meter"]=$location['rows'][0]['elements'][0]['distance']['value'];
			$fn_response["time"]=$location['rows'][0]['elements'][0]['duration']['text'];
			$fn_response["seconds"]=$location['rows'][0]['elements'][0]['duration']['value'];

		}
		catch(Exception $e){
			$fn_response["errors"]=trans('user.maperror');
		}      

		return $fn_response;    
	}

	public function invoice($request_id, $toll_price = 0)
	{
		//try {                      

			$settings = json_decode(json_encode(Setting::where('company_id', $this->company_id)->first()->settings_data));

			$siteConfig = $settings->site;

			$rideRequest = RideRequest::with('provider')->findOrFail($request_id);   

			/*$RideCommission = RideCity::where('city_id',$rideRequest->city_id)->first();
			$tax_percentage = $RideCommission->tax ? $RideCommission->tax : 0;
			$commission_percentage = $RideCommission->comission ? $RideCommission->comission : 0;
			$waiting_percentage = $RideCommission->waiting_percentage ? $RideCommission->waiting_percentage : 0;
			$peak_percentage = $RideCommission->peak_percentage ? $RideCommission->peak_percentage : 0;*/

			$tax_percentage = $commission_percentage = $waiting_percentage = $peak_percentage =0;

			$Fixed = 0;
			$Base_price = 0;
			$Discount = 0; // Promo Code discounts should be added here.
			$Wallet = 0;            
			$ProviderPay = 0;
			$Distance_fare =0;
			$Minute_fare =0;
			$calculator ='DISTANCE';
			$discount_per =0;
			
			if($siteConfig->unit_measurement=='Kms')
				$total_distance = round($rideRequest->distance/1000,1); //TKM
			else
				$total_distance = round($rideRequest->distance/1609.344,1); //TMi
			//added the common function for calculate the price

			$requestarr['kilometer']=$total_distance;
			$requestarr['time']=0;
			$requestarr['seconds']=0;
			$requestarr['minutes']=$rideRequest->travel_time;
			$requestarr['ride_delivery_id']=$rideRequest->ride_delivery_id;
			$requestarr['city_id']=$rideRequest->city_id;
			$requestarr['state_id']=$rideRequest->state_id;
			$requestarr['service_type']=$rideRequest->ride_delivery_id;
			$requestarr['geofence_id']=$rideRequest->geofence_id;
			$requestarr['vehicle_type']= $rideRequest->service_type;
			$requestarr['rental_package_id']= $rideRequest->rental_package_id;
			$requestarr['outstation_type']=$rideRequest->outstation_type;
			$requestarr['started_at']=$rideRequest->started_at;
			$requestarr['finished_at']=$rideRequest->finished_at;
			$requestarr['return_date']=$rideRequest->return_date;
			$response = new Ride();         
			$pricedata=$response->applyPriceLogic($requestarr,1);

			/*$newRequest = RideRequest::findOrFail($rideRequest->id);
			$newRequest->status = "PICKEDUP";
			$newRequest->save();
			dd($pricedata);
			return false;*/


			
			if(!empty($pricedata)){
				$Base_price =$pricedata['price'];
				$Fixed = $pricedata['base_price'];
				$Distance_fare = $pricedata['distance_fare'];
				$Minute_fare = $pricedata['minute_fare'];
				$Hour_fare = $pricedata['hour_fare'];
				$calculator = $pricedata['calculator'];
				$RideCityPrice = $pricedata['ride_city_price'];
				$rideRequest->calculator=$pricedata['calculator'];
				if($rideRequest->service_type == 'RENTAL')
				{
					$rideRequest->base_distance=isset($RideCityPrice->km) ? $RideCityPrice->km : 0;
				}
				else
				{
					$rideRequest->base_distance=isset($RideCityPrice->distance) ? $RideCityPrice->distance : 0;
				}

				$rideRequest->save();

				$tax_percentage = isset($RideCityPrice->tax) ? $RideCityPrice->tax : 0;
				$commission_percentage = isset($RideCityPrice->commission) ? $RideCityPrice->commission : 0;
				$waiting_percentage = isset($RideCityPrice->waiting_commission) ? $RideCityPrice->waiting_commission : 0;
				$peak_percentage = isset($RideCityPrice->peak_commission) ? $RideCityPrice->peak_commission : 0;
			}

			//check peakhours and waiting charges
			$total_waiting_time=$total_waiting_amount=$peakamount=$peak_comm_amount=$waiting_comm_amount=0;

			if($RideCityPrice->waiting_min_charge>0){
				$total_waiting=round($this->total_waiting($rideRequest->id)/60);
				if($total_waiting>0){
					if($total_waiting > $RideCityPrice->waiting_free_mins){
						$total_waiting_time = $total_waiting - $RideCityPrice->waiting_free_mins;
						$total_waiting_amount = $total_waiting_time * $RideCityPrice->waiting_min_charge;
						$waiting_comm_amount = ($waiting_percentage/100) * $total_waiting_amount;

					}
				}
			}


			$start_time = $rideRequest->started_at;
			$end_time = $rideRequest->finished_at;

			$start_time_check = PeakHour::where('start_time', '<=', $start_time)->where('end_time', '>=', $start_time)->where('timezone', $rideRequest->timezone)->where('company_id', Auth::guard('provider')->user()->company_id)->first();

			if($start_time_check){

				if($rideRequest->service_type == 'RENTAL')
				{
					$RideCityPriceList = RideCityPrice::where('service_type',$rideRequest->service_type)->where('ride_delivery_vehicle_id',$rideRequest->ride_delivery_id)->where('company_id', Auth::guard('provider')->user()->company_id)->first();
				}
				else
				{
					$RideCityPriceList = RideCityPrice::where('geofence_id',$rideRequest->geofence_id)->where('ride_delivery_vehicle_id',$rideRequest->ride_delivery_id)->where('company_id', Auth::guard('provider')->user()->company_id)->first();
				}

				$Peakcharges = RidePeakPrice::where('ride_city_price_id',$RideCityPriceList->id)->where('ride_delivery_id',$rideRequest->ride_delivery_id)->where('peak_hour_id',$start_time_check->id)->first();


				if($Peakcharges){
					$peakamount=($Peakcharges->peak_price/100) * $Fixed;
					$peak_comm_amount = ($peak_percentage/100) * $peakamount;
				}

			}
			  
			
			$Base_price=$Base_price;

			$Commision = ($Base_price) * ( $commission_percentage/100 );
			
			

			if($rideRequest->promocode_id>0){
				if($Promocode = Promocode::find($rideRequest->promocode_id)){
					$max_amount = $Promocode->max_amount;
					$discount_per = $Promocode->percentage;

					$discount_amount = (($Base_price) * ($discount_per/100));

					if($discount_amount>$Promocode->max_amount){
						$Discount = $Promocode->max_amount;
					}
					else{
						$Discount = $discount_amount;
					}

					$PromocodeUsage = new PromocodeUsage;
					$PromocodeUsage->user_id =$rideRequest->user_id;
					$PromocodeUsage->company_id =Auth::guard('provider')->user()->company_id;
					$PromocodeUsage->promocode_id =$rideRequest->promocode_id;
					$PromocodeUsage->status ='USED';
					$PromocodeUsage->save();

					// $Total = $Base_price + $Tax;
					// $payable_amount = $Base_price + $Tax - $Discount;

				}                
			}

		   	$Tax = (($Base_price-$Discount)+$peakamount+$total_waiting_amount) * ( $tax_percentage/100 );

			$Total = ($Base_price-$Discount) + $Tax;

			if($Total < 0){
				$Total = 0.00; // prevent from negative value
				$payable_amount = 0.00;
			}


			//changed by tamil
			
			$Total += $peakamount+$total_waiting_amount+$toll_price;
			
			$all_commision = $Commision + $waiting_comm_amount + $peak_comm_amount;

			$ProviderPay = (($Total+$Discount) - $all_commision)-$Tax;

			$Payment = new RideRequestPayment;


			$Payment->company_id = Auth::guard('provider')->user()->company_id;
			$Payment->ride_request_id = $rideRequest->id;

			$Payment->user_id=$rideRequest->user_id;
			$Payment->provider_id=$rideRequest->provider_id;


			if(!empty($rideRequest->admin_id)){
				$Fleet = Admin::where('id',$rideRequest->admin_id)->where('type','FLEET')->where('company_id',Auth::guard('provider')->user()->company_id)->first();

				$fleet_per=0;


				if(!empty($Fleet)){
					if(!empty($Commision)){                                     
						$fleet_per=$Fleet->commision ? $Fleet->commision : 0;
					}
					else{
						$fleet_per=$RideCityPrice->fleet_commission ? $RideCityPrice->fleet_commission :0;
					}
					

					$Payment->fleet_id=$rideRequest->provider->admin_id;
					$Payment->fleet_percent=$fleet_per;
				}
			}



			$payable_amount = $Total;


			$Payment->fixed = $Fixed ;
			$Payment->distance = $Distance_fare;
			$Payment->minute  = $Minute_fare;
			$Payment->hour  = $Hour_fare;
			$Payment->payment_mode  = $rideRequest->payment_mode;
			$Payment->commision = $Commision;
			$Payment->commision_percent = $commission_percentage;
			$Payment->toll_charge = $toll_price;
			$Payment->total = $Total;
			$Payment->provider_pay = $ProviderPay;
			$Payment->peak_amount = $peakamount;
			$Payment->peak_comm_amount = $peak_comm_amount;
			$Payment->total_waiting_time = $total_waiting_time;
			$Payment->waiting_amount = $total_waiting_amount;
			$Payment->waiting_comm_amount = $waiting_comm_amount;
			if($rideRequest->promocode_id>0){
				$Payment->promocode_id = $rideRequest->promocode_id;
			}
			$Payment->discount = $Discount;
			$Payment->discount_percent = $discount_per;
			$Payment->company_id = Auth::guard('provider')->user()->company_id;


			if($Discount  == ($Base_price + $Tax)){
				$rideRequest->paid = 1;
			}

			if($rideRequest->use_wallet == 1 && $payable_amount > 0){
				
				$User = User::find($rideRequest->user_id);
				$currencySymbol = $rideRequest->currency;
				$Wallet = $User->wallet_balance;

				if($Wallet != 0){

					if($payable_amount > $Wallet) {

						$Payment->wallet = $Wallet;
						$Payment->is_partial=1;
						$Payable = $payable_amount - $Wallet;
						
						$Payment->payable = abs($Payable);

						$wallet_det=$Wallet;  

						if($rideRequest->payment_mode == 'CASH'){
							$Payment->round_of = round($Payable)-abs($Payable);
							$Payment->total = $Total;
							$Payment->payable = round($Payable);
						}                    

					} else {

						$Payment->payable = 0;
						$WalletBalance = $Wallet - $payable_amount;
						
						$Payment->wallet = $payable_amount;
						
						$Payment->payment_id = 'WALLET';
						$Payment->payment_mode = $rideRequest->payment_mode;

						//$rideRequest->paid = 1;
						$rideRequest->status = 'COMPLETED';
						$rideRequest->save();

						$wallet_det=$payable_amount;
					   
					}
					
					(new SendPushNotification)->ChargedWalletMoney($rideRequest->user_id,Helper::currencyFormat($wallet_det,$currencySymbol), 'transport');

					//for create the user wallet transaction

					$transaction['amount']=$wallet_det;
					$transaction['id']=$rideRequest->user_id;
					$transaction['transaction_id']=$rideRequest->id;
					$transaction['transaction_alias']=$rideRequest->booking_id;
					$transaction['company_id']=$rideRequest->company_id;
					$transaction['transaction_msg']='transport deduction';
					$transaction['admin_service']=$rideRequest->admin_service;
					$transaction['country_id']=$rideRequest->country_id;


					(new Transactions)->userCreditDebit($transaction,0);


				}

			} else {
				if($rideRequest->payment_mode == 'CASH'){
					$Payment->round_of = round($payable_amount)-abs($payable_amount);
					$Payment->total = $Total;
					$Payment->payable = round($payable_amount);
				}
				else{
					$Payment->total = abs($Total);
					$Payment->payable = abs($payable_amount);   
				}               
			}

			$Payment->tax = $Tax;

			$Payment->tax_percent = $tax_percentage;

			if($Payment->wallet > 0 && $Payment->is_partial != 1)
			{
				$Payment->payment_mode = 'WALLET';
			}
			
			$Payment->save();

			return $Payment;

		/*} catch (\Throwable $e) {
			$newRequest = RideRequest::findOrFail($rideRequest->id);
			$newRequest->status = "PICKEDUP";
			$newRequest->save();
			return false;
		}*/
	}

	public function total_waiting($id){

		$waiting = RideRequestWaitingTime::where('ride_request_id', $id)->whereNotNull('ended_at')->sum('waiting_mins');

		$uncounted_waiting = RideRequestWaitingTime::where('ride_request_id', $id)->whereNull('ended_at')->first();

		if($uncounted_waiting != null) {
			$waiting += (Carbon::parse($uncounted_waiting->started_at))->diffInSeconds(Carbon::now());
		}

		return $waiting;
	}
}