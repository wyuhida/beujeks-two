<?php

namespace App\Jobs;

use Pushok\AuthProvider;
use Pushok\Client;
use Pushok\Notification;
use Pushok\Payload;
use Pushok\Payload\Alert;

use Edujugon\PushNotification\PushNotification;

class PushNotificationJob extends Job
{
	protected $topic, $push_message, $title, $data, $user, $settings, $type;

	/**
	 * Create a new job instance.
	 *
	 * @return void
	 */
	public function __construct($topic, $push_message, $title, $data, $user, $settings, $type)
	{
		$this->topic = $topic;
		$this->push_message = $push_message;
		$this->title = $title;
		$this->data = $data;
		$this->user = $user;
		$this->settings = $settings;
		$this->type = $type;
	}

	/**
	 * Execute the job.
	 *
	 * @return void
	 */
	public function handle()
	{
		if($this->user->device_type == 'IOS') {

			$pem = app()->basePath('storage/app/public/'.$this->user->company_id.'/apns' ).'/private_key.p8';

			if(file_exists($pem)) {

	                $options = [
					    'key_id' => $this->settings->site->ios_push_key_id, // The Key ID obtained from Apple developer account
					    'team_id' => $this->settings->site->ios_push_team_id, // The Team ID obtained from Apple developer account
					    'app_bundle_id' => $this->settings->site->ios_push_user_bundle, // The bundle ID for app obtained from Apple developer account
					    'private_key_path' => $pem, // Path to private key
					    'private_key_secret' => null // Private key secret
					];


                    $authProvider = AuthProvider\Token::create($options);


                    $alert = Alert::create()->setTitle($this->title);
                    $alert = $alert->setBody($this->push_message);

                    $payload = Payload::create()->setAlert($alert);

                    //set notification sound to default
                    $payload->setSound('default');
					//$payload->setBadge(1);

                    //$payload->setPushType('voip');
                    //add custom value to your notification, needs to be customized
                    $payload->setCustomValue('custom', 'My custom data');

                    $notification = new Notification($payload, $this->user->device_token);


                    // If you have issues with ssl-verification, you can temporarily disable it. Please see attached note.
                    // Disable ssl verification
                    // $client = new Client($authProvider, $production = false, [CURLOPT_SSL_VERIFYPEER=>false] );
                    $client = new Client($authProvider, $this->settings->site->environment == "production" ? true : false);
                    $client->addNotification($notification);

                    $responses = $client->push(); // returns an array of ApnsResponseInterface (one Response per Notification)

					return $responses;

			}
			

		}elseif($this->user->device_type == 'ANDROID'){

			if($this->settings->site->android_push_key != "") {


				$push = new PushNotification('fcm');

	            $push->setMessage([
	                'notification' => [
	                        'title'=> $this->title,
	                        'body'=> $this->push_message,
	                        'sound' => 'default'
	                        ],
	                'data' => [
	                        'extraPayLoad1' => 'value1',
	                        'extraPayLoad2' => 'value2'
	                        ]
	                ])
	                ->setApiKey($this->settings->site->android_push_key)
	                ->setDevicesToken($this->user->device_token)
	                ->send()
	                ->getFeedback();

				/*$config = [
					'environment' => $this->settings->site->environment,
					'apiKey'      => $this->settings->site->android_push_key, 
					'service'     => 'gcm'
				];*/   
			}

		}

		/*$message = \PushNotification::Message($this->push_message, array(
			'badge' => 1,
			'sound' => 'default',
			'custom' => [ "message" => [ "topic" => $this->topic, "notification" => [ "body" => $this->push_message, "title" => $this->title ], "data" => $this->data ] ]
		));

		if(isset($config) && count($config) > 0 ) {
			return \PushNotification::app($config)
				->to($this->user->device_token)
				->send($message);
		}*/
		
	}
}
