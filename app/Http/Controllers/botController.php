<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Redis;
use DateTime;
use DateTimezone;
use DB;


class botController extends Controller
{
    //
    public function checkBot(){
        Redis::set('inbox_count', '0');
        $id = Redis::get('inbox_count');

        //$id = $id+1;
        //$bot_key = $_ENV['BOT_NAME'];
        return $id;
    }


    public function check_redis_inbox(){
        
        $inbox_count = Redis::get('inbox_count');
        $last_update_id = Redis::get('last_update_id');

        $inbox = Redis::rpop('inbox');

        echo $inbox;
        for($i = 0;$i<$inbox_count; $i++){
            $inbox = Redis::rpop('inbox');


            echo $inbox;

        }
        echo "<br>";
        echo $inbox_count;
        echo "<br>";
        echo $last_update_id;
        echo "<br>";
        echo "Finish";

    }
    public function archive_db(){
        $inbox_count = Redis::get('inbox_count');
        $last_update_id = Redis::get('last_update_id');

        $insert_query = "INSERT INTO telegram_db.message_table (update_id,chat_id,fname,lname,message,reply,message_date,reply_date) 
                        VALUES";
        
        if($inbox_count >0){
            for($i = 0;$i<$inbox_count; $i++){
                $inbox = Redis::rpop('inbox');
    
                $obj_json = json_decode($inbox,true);
    
                $update_id = $obj_json['update_id'];
                $chat_id = $obj_json['chat_id'];
                $fname = addslashes($obj_json['fname']);
                $lname = addslashes($obj_json['lname']);
                $message = addslashes($obj_json['message']);
                $reply = addslashes($obj_json['reply']);
                $message_date = $obj_json['message_date'];
                $reply_date = $obj_json['reply_date'];
    
                $insert_query .= "('"
                                    .$update_id."'"
                                    .",'".$chat_id."'"
                                    .",'".$fname."'"
                                    .",'".$lname."'"
                                    .",'".$message."'"
                                    .",'".$reply."'"
                                    .",'".$message_date."'"
                                    .",'".$reply_date."'"
                                ."),";
    
    
            }
            $insert_query = trim($insert_query,",");
            DB::insert( DB::raw($insert_query));
     
            Redis::set('inbox_count', '0');
            $insert_last_update_id = "UPDATE telegram_db.track_update_table SET last_update_id = $last_update_id,created_at = now() 
                                    WHERE id=1";
            DB::update( DB::raw($insert_last_update_id));

            return "Successfully archived data from redis to mysql";
        }
        else{
            return "Inbox Was Empty";
        }
        
    }
    public function chatup(){
        //-------------------Get Last Update ID------------------------------------------
        $last_update_id_temp = Redis::get('last_update_id');
        $last_update_id = $last_update_id_temp + 1;
        $bot_token = $_ENV['BOT_TOKEN'];
        //------------------ Get New Updates -------------------------------------------
        $get_update_json = file_get_contents("https://api.telegram.org/bot$bot_token/getUpdates?offset=$last_update_id");
        $msg_updates = json_decode($get_update_json,true);
        $msg_array = $msg_updates['result'];
    
        If(count($msg_array)>0){
            //------------------ Read Updates ----------------------------------------------
            foreach($msg_array as $msg ){
                if($msg['update_id']){
                    $update_id_temp = $msg['update_id'];    
                }
                else{
                    $update_id_temp = 0;
                }
                if($msg['message']['chat']['id']){
                    $chat_id_temp = $msg['message']['chat']['id'];
                }
                else{
                    $chat_id_temp = 0;
                }
                if($msg['message']['from']['first_name']){
                    $first_name_temp = $msg['message']['from']['first_name'];
                }else{
                    $first_name_temp = 'NA';
                }
                if(array_key_exists('last_name', $msg['message']['from'])){
                    $last_name_temp = $msg['message']['from']['last_name'];
                }
                else{
                    $last_name_temp = 'NA';
                }
                
                if($msg['message']['text']){
                    $message_temp = $msg['message']['text'];
                }else{
                    $message_temp = 'NA';
                }
                if($msg['message']['date']){
                    $date = $msg['message']['date'];
                    //date_default_timezone_set("Asia/Dhaka");
                    $dt = new DateTime("@$date",new DateTimezone('Asia/Dhaka'));  // convert UNIX timestamp to PHP DateTime
                    $date_temp = $dt->format('Y-m-d H:i:s');
                }else{
                    $date_temp = '0000-00-00 00:00:00';
                }
                //--------------- Prepare Reply -------------------------
                $reply = $this->prepare_reply($message_temp);
                //-------------- Send Reply -----------------------------
                $this->sendMessage($chat_id_temp,$reply, $bot_token);
                //-------------Insert Log------------------------

                $current_time = date("Y-m-d H:i:s");

                $message_obj = array();
                $message_obj['update_id'] = $update_id_temp;
                $message_obj['chat_id'] = $chat_id_temp;
                $message_obj['fname'] = $first_name_temp;
                $message_obj['lname'] = $last_name_temp;
                $message_obj['message'] = $message_temp;
                $message_obj['reply'] = $reply;
                $message_obj['message_date'] = $date_temp;
                $message_obj['reply_date'] = $current_time;

                $msg_json = json_encode($message_obj);
                
                Redis::lpush('inbox',$msg_json);                
                $inbox_count = Redis::get('inbox_count');
                $inbox_count += 1;
                Redis::set('inbox_count', $inbox_count);
                Redis::set('last_update_id', $update_id_temp);                
            }

            $inbox_count = Redis::get('inbox_count');
            $capacity = $_ENV['REDIS_INBOX_CAPACITY'];
            if($inbox_count > $capacity){
                $this->archive_db();
            }
        }
        else{
            echo "No new Message";
        }
    }

    //------------------- Utility Functions ----------------------
    public function sendMessage($chatID, $messaggio, $token) {
        echo "<br>";
        echo "sending message to " . $chatID;


        $url = "https://api.telegram.org/bot" . $token . "/sendMessage?chat_id=" . $chatID;
        $url = $url . "&text=" . urlencode($messaggio);
        $ch = curl_init();
        $optArray = array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true
        );
        curl_setopt_array($ch, $optArray);
        $result = curl_exec($ch);
        curl_close($ch);
    }

    public function prepare_reply($msg){
        if($msg == 'Hi'){
            return "I dnt have time for HI/Hello";
        }
        elseif($msg == 'Hellow'){
            return "I dnt have time for HI/Hello";
        }
        elseif($msg == 'How are you?'){
            return "I am not well";
        }
        else{
            return "stop this bullshit !!";
        }

    }

}
