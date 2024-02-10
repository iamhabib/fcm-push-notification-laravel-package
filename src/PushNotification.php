<?php

namespace FcmPushNotification\FcmPushNotification;
use Cache;
use Log;

class PushNotification {

    private string $private_key_path;
    private string $project_id;
    private string $push_type;

    const NOTIFICATION      = 'NOTIFICATION';
    const DATA              = 'DATA';
    const BOTH              = 'BOTH';

    public function __construct(string $push_type = self::BOTH){
        $this->private_key_path     = config('fcm_push_notification.fcm_private_key_path');
        $this->project_id           = config('fcm_push_notification.fcm_project_id');
        $this->push_type            = $push_type;
    }

    // sending push message to single user by firebase reg id
    public function sendToOne(string $token, string $title, string $message, string $image = '', array $payload = null) {
        try{
            $fields['message'] = ['token' => $token];
            if($this->push_type == self::NOTIFICATION || $this->push_type == self::BOTH){
                $fields['message']['notification'] = $this->getPushMessageNotificationModel(title: $title, body: $message);
            }
            if($this->push_type == self::DATA || $this->push_type == self::BOTH){
                $fields['message']['data'] = $this->getPushMessageDataModel($title, $message, $image, $payload);
            }
            return $this->sendPushNotification($fields);
        }catch(\Exception $ex){
            Log::error('PushNotification Issue at sendToOne => ' . $ex->getMessage());
        }
        return false;
    }

    // Sending message to a topic by topic name
    public function sendToTopic(string $topic, string $title, string $message, string $image = '', array $payload = null) {
        try{
            $fields['message'] = ['topic' => $topic];
            if($this->push_type == self::NOTIFICATION || $this->push_type == self::BOTH){
                $fields['message']['notification'] = $this->getPushMessageNotificationModel(title: $title, body: $message);
            }
            if($this->push_type == self::DATA || $this->push_type == self::BOTH){
                $fields['message']['data'] = $this->getPushMessageDataModel($title, $message, $image, $payload);
            }
            return $this->sendPushNotification($fields);
        }catch(\Exception $ex){
            Log::error('PushNotification Issue at sendToTopic => ' . $ex->getMessage());
        }
        return false;
    }

    // Sending message to a topic by topic global
    public function sendToAll(string $title, string $message, string $image = '', array $payload = null) {
        try{
            $fields['message'] = ['topic' => 'global'];
            if($this->push_type == self::NOTIFICATION || $this->push_type == self::BOTH){
                $fields['message']['notification'] = $this->getPushMessageNotificationModel(title: $title, body: $message);
            }
            if($this->push_type == self::DATA || $this->push_type == self::BOTH){
                $fields['message']['data'] = $this->getPushMessageDataModel($title, $message, $image, $payload);
            }
            return $this->sendPushNotification($fields);
        }catch(\Exception $ex){
            Log::error('PushNotification Issue at sendToAll => ' . $ex->getMessage());
        }
        return false;
    }

    // sending push message to multiple users by firebase registration ids
    public function sendMultiple(array $registration_ids, string $title, string $message, string $image = '', array $payload = null) {
        try{
            $res = [];
            $collection = array_chunk($registration_ids, 500);
            foreach ($collection as $chunk){
                $fields['message'] = ['tokens' => $chunk];
                if($this->push_type == self::NOTIFICATION || $this->push_type == self::BOTH){
                    $fields['message']['notification'] = $this->getPushMessageNotificationModel(title: $title, body: $message);
                }
                if($this->push_type == self::DATA || $this->push_type == self::BOTH){
                    $fields['message']['data'] = $this->getPushMessageDataModel($title, $message, $image, $payload);
                }
                $res[] = $this->sendPushNotification($fields);
            }
            return $res;
        }catch(\Exception $ex){
            Log::error('PushNotification Issue at sendMultiple => ' . $ex->getMessage());
        }
        return false;
    }

    // function makes curl request to firebase servers
    private function sendPushNotification($fields) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/v1/projects/' . $this->project_id . '/messages:send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $this->getGoogleAccessToken(), 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

        $result = curl_exec($ch);
        if ($result === FALSE) {
            die('Curl failed: ' . curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    private function getPushMessageDataModel($title, $message, $image, $payload) {
        return [
            'title'         => $title,
            'description'   => $message,
            'image'         => $image,
            'payload'       => $payload
        ];
    }

    private function getPushMessageNotificationModel($title, $body) {
        return [
            'title'         => $title,
            'body'          => $body
        ];
    }

    private function getGoogleAccessToken(){
        try{
            return Cache::remember('HRHABIB_FCM_GOOGLE_ACCESS_TOKEN', 3500, function () {
                $authConfig = json_decode(file_get_contents(base_path().'/'.$this->private_key_path));
                $secret = openssl_get_privatekey($authConfig->private_key);

                // Create the token header
                $header = json_encode([
                    'typ' => 'JWT',
                    'alg' => 'RS256'
                ]);

                $time = time();
                $payload = json_encode([
                    "iss"       => $authConfig->client_email,
                    "scope"     => "https://www.googleapis.com/auth/firebase.messaging",
                    "aud"       => "https://oauth2.googleapis.com/token",
                    "exp"       => $time - 60 + 3600,
                    "iat"       => $time - 60
                ]);

                $base64UrlHeader = $this->base64UrlEncode($header);
                $base64UrlPayload = $this->base64UrlEncode($payload);
                // Create Signature Hash
                $result = openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $secret, OPENSSL_ALGO_SHA256);
                $base64UrlSignature = $this->base64UrlEncode($signature);

                $param = [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($param));

                $response = curl_exec($ch);
                if ($response === FALSE) {
                    die('Curl failed: ' . curl_error($ch));
                }
                curl_close($ch);

                $response = json_decode($response);
                $token = $response->access_token;
                return $token;
            });
        }catch (\Exception $ex){
            Log::error('PushNotification Issue at getGoogleAccessToken => ' . $ex->getMessage());
        }
        return '';
    }

    private function base64UrlEncode($text)
    {
        return str_replace(
            ['+', '/', '='],
            ['-', '_', ''],
            base64_encode($text)
        );
    }

}
