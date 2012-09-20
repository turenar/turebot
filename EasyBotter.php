<?php
//======================================================================
//EasyBotter Ver2.04beta
//updated 2010/02/28
//======================================================================
class EasyBotter
{    
        private $_screen_name;
        private $_consumer_key;
        private $_consumer_secret;
        private $_access_token;
        private $_access_token_secret;
        
        private $_replyLoopLimit;
        private $_footer;
        private $_dataSeparator;
        
        private $_tweetData;        
        private $_replyPatternData;
        
        private $_repliedReplies;
        private $_logDataFile;
        private $_latestReply;
        
    function __construct()
    {                        
        $dir = getcwd();
        $path = $dir."/PEAR";
        set_include_path(get_include_path() . PATH_SEPARATOR . $path);
        $inc_path = get_include_path();
        chdir(dirname(__FILE__));
        date_default_timezone_set("Asia/Tokyo");        
        
        require_once("setting.php");
        $this->_screen_name = $screen_name;
        $this->_consumer_key = $consumer_key;
        $this->_consumer_secret = $consumer_secret;
        $this->_access_token = $access_token;
        $this->_access_token_secret = $access_token_secret;
        
        $this->_replyLoopLimit = $replyLoopLimit;
        $this->_footer  = $footer;
        $this->_dataSeparator = $dataSeparator;
        
        $this->_repliedReplies = array();
        $this->_logDataFile = "log.dat";
        $this->_latestReply = file_get_contents($this->_logDataFile);
        
        require_once 'HTTP/OAuth/Consumer.php';  
        $this->consumer = new HTTP_OAuth_Consumer($this->_consumer_key, $this->_consumer_secret);    
        $http_request = new HTTP_Request2();  
        $http_request->setConfig('ssl_verify_peer', false);  
        $consumer_request = new HTTP_OAuth_Consumer_Request;  
        $consumer_request->accept($http_request);  
        $this->consumer->accept($consumer_request);  
        $this->consumer->setToken($this->_access_token);  
        $this->consumer->setTokenSecret($this->_access_token_secret);        
        
        $this->printHeader();
    }
       
   function __destruct(){
        $this->printFooter();        
    }
    
    //どこまでリプライしたかを覚えておく
    function saveLog(){
        rsort($this->_repliedReplies);
        return file_put_contents($this->_logDataFile,$this->_repliedReplies[0]);
    }
        
    //表示用HTML
    function printHeader(){
        $header = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        $header .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="ja" xml:lang="ja">';
        $header .= '<head>';
        $header .= '<meta http-equiv="content-language" content="ja" />';
        $header .= '<meta http-equiv="content-type" content="text/html; charset=UTF-8" />';
        $header .= '<title>EasyBotter</title>';
        $header .= '</head>';
        $header .= '<body><pre>';
        print $header;
    }

    //表示用HTML
    function printFooter(){
        echo "</body></html>";
    }
    
    //ランダムにポスト
    function postRandom($datafile = "data.txt"){        
        $status = $this->makeTweet($datafile);                
        if(empty($status)){
            $message = "投稿するメッセージがないようです。<br />";
            echo $message;
            return array("error"=> $message);
        }else{                
            //idなどの変換
            if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status);
            }            
            $response = $this->setUpdate(array("status"=>$status));
            return $this->showResult($response);            
        }    
    }    
    
    //順番にポスト
    function postRotation($datafile = "data.txt", $lastPhrase = FALSE){        
        $status = $this->makeTweet($datafile,0);                
        if($status !== $lastPhrase){
            $this->rotateData($datafile);        
            if(empty($status)){
                $message = "投稿するメッセージがないようです。<br />";
                echo $message;
                return array("error"=> $message);
            }else{                
                //idなどの変換
                if(preg_match("@{.+?}@",$status) == 1){
                    $status = $this->convertText($status);
                }            
                $response = $this->setUpdate(array("status"=>$status));
                return $this->showResult($response);            
            }
        }else{
            $message = "終了する予定のフレーズ「".$lastPhrase."」が来たので終了します。<br />";
            echo $message;
            return array("error"=> $message);
        }
    }    
    
    //リプライする
    function reply($cron = 2, $replyFile = "data.txt", $replyPatternFile = "reply_pattern.php"){
        $replyLoopLimit = $this->_replyLoopLimit;
        //リプライを取得
        $response = $this->getReplies();        
        $response = $this->getRecentTweets($response, $cron * $replyLoopLimit * 3);
        $replies = $this->getRecentTweets($response, $cron);
        $replies = $this->selectTweets($replies);
        $replies = $this->removeRepliedTweets($replies);
        
        if(count($replies) != 0){                           
            //ループチェック
            $replyUsers = array();
            foreach($response as $r){
                $replyUsers[] = (string)$r->user->screen_name;                
            }
            $countReplyUsers = array_count_values($replyUsers);
            $replies_ = array();
            foreach($replies as $r){
                $userName = (string)$r->user->screen_name;
                if($countReplyUsers[$userName] < $replyLoopLimit){
                    $replies_[] = $r;
                }
            }            
            //古い順にする
            $replies = array_reverse($replies_);                                
            if(count($replies) != 0){            
                //リプライの文章をつくる
                $replyTweets = $this->makeReplyTweets($replies, $replyFile, $replyPatternFile);                
                foreach($replyTweets as $re){
                    $value = array("status"=>$re["status"],'in_reply_to_status_id'=>$re["in_reply_to_status_id"]);    
                    $response = $this->setUpdate($value);      
                    $result = $this->showResult($response);
                    $results[] = $result;
                    if($response->in_reply_to_status_id){
                        $this->_repliedReplies[] = (string)$response->in_reply_to_status_id;
                    }
                }
            }        
        }else{
            $message = $cron."分以内に受け取った@はないようです。<br /><br />";
            echo $message;
            $results[] = $message;
        }
        if(!empty($this->_repliedReplies)){
            $this->saveLog();
        }
        return $results;
    }
    
    //タイムラインに反応する
    function replyTimeline($cron = 2, $replyPatternFile = "reply_pattern.php"){
        //タイムラインを取得
        $timeline = $this->getFriendsTimeline();        
        $timeline = $this->getRecentTweets($timeline, $cron);   
        $timeline = $this->selectTweets($timeline);
        $timeline = $this->removeRepliedTweets($timeline);
        $timeline = array_reverse($timeline);        
        if(count($timeline) != 0){
            //リプライを作る        
            $replyTweets = $this->makeReplyTimelineTweets($timeline, $replyPatternFile);
            if(count($replyTweets) != 0){
                foreach($replyTweets as $re){
                    $value = array("status"=>$re["status"],'in_reply_to_status_id'=>$re["in_reply_to_status_id"]);    
                    $response = $this->setUpdate($value);      
                    $result = $this->showResult($response);
                    $results[] = $result;
                    if($response->in_reply_to_status_id){
                        $this->_repliedReplies[] = (string)$response->in_reply_to_status_id;
                    }                }
            }else{
                $message = $cron."分以内のタイムラインに反応する単語がないようです。<br /><br />";
                echo $message;
                $results = $message;
            }
        }
        if(!empty($this->_repliedReplies)){
            $this->saveLog();
        }
        return $results;        
    }
    
    //発言を作る
    function makeTweet($file, $number = FALSE){    
        if(empty($this->_tweetData[$file])){
            $this->_tweetData[$file] = $this->readDataFile($file);        
        }
        //発言をランダムに一つ選ぶ
        if($number === FALSE){
            $status = $this->_tweetData[$file][array_rand($this->_tweetData[$file])];
        }else{
            $status = $this->_tweetData[$file][$number];            
        }       
        return $status;
    }    
    //リプライを作る
    function makeReplyTweets($replies, $replyFile, $replyPatternFile){
        if(empty($this->_replyPatternData[$replyPatternFile]) && !empty($replyPatternFile)){
            $this->_replyPatternData[$replyPatternFile] = $this->readPatternFile($replyPatternFile);
        }
        $replyTweets = array();
        foreach($replies as $reply){
            $status = "";
            //リプライパターンと照合
            if(!empty($this->_replyPatternData[$replyPatternFile])){
                foreach($this->_replyPatternData[$replyPatternFile] as $pattern => $res){
                    if(preg_match("@".$pattern."@u",$reply->text, $matches) === 1){                                        
                        $status = $res[array_rand($res)];
                        for($i=1;$i <count($matches);$i++){
                            $p = "$".$i;
                            $status = str_replace($p,$matches[$i],$status);
                        }
                        break;
                    }
                }            
            }                         
            //パターンになかった場合はランダムに
            if(empty($status) && !empty($replyFile)){
                $status = $this->makeTweet($replyFile);
            }        
            if(empty($status) || $status == "[[END]]"){
                continue;
            }            
            if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status, $reply);
            }
            $reply_name = (string)$reply->user->screen_name;        
            $in_reply_to_status_id = (string)$reply->id;
            if(stristr($status, "[[AUTOFOLLOW]]")){
				$status = str_replace("[[AUTOFOLLOW]]", "", $status);
				$followReq = $this->followUser($reply_name);
				if($followReq->error) continue; // 失敗したときはとりあえず無視
			}else if(stristr($status, "[[AUTOREMOVE]]")){
				$status = str_replace("[[AUTOREMOVE]]", "", $status);
				$removeReq = $this->consumer->sendRequest("http://api.twitter.com/friendships/destroy/$reply_name.json", array(), "POST");
				if($followReq->error) continue; // 失敗したときはｒｙ
			}
			$re["status"] = "@".$reply_name." ".$status;
            $re["in_reply_to_status_id"] = $in_reply_to_status_id;
            
            $replyTweets[] = $re;
        }                        
        return $replyTweets;    
    }
    
    //タイムラインへの反応を作る
    function makeReplyTimelineTweets($timeline, $replyPatternFile){
        if(empty($this->_replyPatternData[$replyPatternFile])){
            $this->_replyPatternData[$replyPatternFile] = $this->readPatternFile($replyPatternFile);
        }
        $replyTweets = array();
        foreach($timeline as $tweet){
            $status = "";
            $re = array();
            $text = (string)$tweet->text;
            //リプライパターンと照合
            foreach($this->_replyPatternData[$replyPatternFile] as $pattern => $res){
                if(preg_match("@".$pattern."@u",$text, $matches) === 1 && !preg_match("/\@/i",$text)){                                        
                    $status = $res[array_rand($res)];
                    for($i=1;$i <count($matches);$i++){
                        $p = "$".$i;
                        $status = str_replace($p,$matches[$i],$status);
                    }
                    break;
                    
                }                
            }
            if(empty($status)){
                continue;
            }
            if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status, $tweet);
            }            
            $reply_name = (string)$tweet->user->screen_name;        
            $in_reply_to_status_id = (string)$tweet->id;
            $re["status"] = "@".$reply_name." ".$status;
            $re["in_reply_to_status_id"] = $in_reply_to_status_id;               
            $replyTweets[] = $re;
        }                        
        return $replyTweets;    
    }        
    
    //ログの順番を並び替える
    function rotateData($file){
        $tweetsData = file_get_contents($file);
        $tweets = explode("\n", $tweetsData);
        $tweets_ = array();
        for($i=0;$i<count($tweets) - 1;$i++){
            $tweets_[$i] = $tweets[$i+1];
        }
        $tweets_[] = $tweets[0];
        $tweetsData_ = "";
        foreach($tweets_ as $t){
            $tweetsData_ .= $t."\n";
        }
        $tweetsData_ = trim($tweetsData_);        
        $fp = fopen($file, 'w');
        fputs($fp, $tweetsData_);
        fclose($fp);            
    }
    
    //タイムラインの最近20件の呟きからランダムに一つを取得
    function getRandomTweet(){
        $response = $this->getFriendsTimeline();                
        for($i=0;$i<99;$i++){ 
            $randomTweet = $response->status[rand(0,count($response->status))];                        
            if($randomTweet->user->screen_name != $this->_screen_name){
                return $response->status[rand(0,count($response->status))];                
            }
        }
        return false;
    }

    //つぶやきの中から$minute分以内のものと、最後にリプライしたもの以降のものだけを返す
    function getRecentTweets($response,$minute){    
        $tweets = array();
        $now = strtotime("now");
        $limittime = $now - $minute * 70; //取りこぼしを防ぐために10秒多めにカウントしてる    
        foreach($response as $tweet){
            //var_dump($tweet);
            $time = strtotime($tweet->created_at);    
            //            echo $time." = ".$limittime."<Br />";
            $tweet_id = (string)$tweet->id;
            if($limittime <= $time && $this->_latestReply < $tweet_id){                    
                $tweets[] = $tweet;                
            }else{
                break;                
            }
        }    
        return $tweets;    
    }
    
    //必要なつぶやきのみに絞る
    function selectTweets($response){    
        $replies = array();
        foreach($response as $reply){
            //自分自身のつぶやきを除外する
            $replyName = (string)$reply->user->screen_name;
            if($this->_screen_name == $replyName){
                continue;
            }                        
            //RT, QTを除外する
            $text = (string)$reply->text;
            if(strpos($text,"RT") != FALSE || strpos($text,"QT") != FALSE){
                continue;
            }                        
            $replies[] = $reply;                            
        }    
        return $replies;    
    }
    
    //リプライ一覧から自分が既に返事したものを除く
    function removeRepliedTweets($response){
        $replies = array();
        foreach($response as $reply){
            $id = (string)$reply->id;
            if(in_array($id, $this->_repliedReplies) === FALSE){
                $replies[] = $reply;
            }
        }    
        return $replies;        
    }
                        
    //自動フォロー返し
    function autoFollow(){    
        $response = $this->getFollowers();
        $followList = array();
        foreach($response as $user){
            $follow = (string)$user->following;
            if($follow == "false"){
                $followList[] = (string)$user->screen_name;
            }
        }
        foreach($followList as $screen_name){    
            $response = $this->followUser($screen_name);
			if(!$response->error){
				$postText = array("status"=>"@{$screen_name} フォローしたよ！");
				$response = $this->setUpdate($postText);
				$result = $this->showResult($response);
			}
        }            
    }
    
    //つぶやきデータを読み込む
    function readDataFile($file){
        if(preg_match("@\.php$@", $file) == 1){
            require_once($file);
            return $data;
        }else{
            $tweets = file_get_contents($file);
            $tweets = trim($tweets);
            $tweets = preg_replace("@".$this->_dataSeparator."+@",$this->_dataSeparator,$tweets);
            $data = explode($this->_dataSeparator, $tweets);
            return $data;
        }
    }    
    //リプライパターンデータを読み込む
    function readPatternFile($file){
        $data = array();
        require_once($file);
        if(count($data) != 0){
            return $data;
        }else{
            return $reply_pattern;            
        }
    }
    
    //文章を変換する
    function convertText($text, $reply = FALSE){
        $text = str_replace("{year}",date("Y"),$text);
        $text = str_replace("{month}",date("n"),$text);
        $text = str_replace("{day}",date("j"),$text);
        $text = str_replace("{hour}",date("G"),$text);
        $text = str_replace("{minute}",date("i"),$text);
        $text = str_replace("{second}",date("s"),$text);    
        
        //ランダムな一人のfollowingデータを取る    
        if(strpos($text, "{following_id}") !== FALSE){
            $response = $this->getFriends();
            $id = $response->user[rand(0,count($response->user))]->screen_name;
            $text = str_replace("{following_id}",$id,$text);        
        }
        if(strpos($text, "{following_name}") !== FALSE){
            $response = $this->getFriends();
            $name = $response->user[rand(0,count($response->user))]->name;
            $text = str_replace("{following_name}",$name,$text);        
        }
        
        //ランダムな一人のfollowerデータを取る    
        if(strpos($text,"{follower_id}") !== FALSE){
            $response = $this->getFollowers();
            $id = $response->user[rand(0,count($response->user))]->screen_name;
            $text = str_replace("{follower_id}",$id,$text);        
        }
        if(strpos($text, "{follower_name}") !== FALSE){
            $response = $this->getFollowers();
            $name = $response->user[rand(0,count($response->user))]->name;
            $text = str_replace("{follower_name}",$name,$text);        
        }
        
        //タイムラインからランダムに最近発言した人のデータを取る
        if(strpos($text,"{timeline_id}") !== FALSE){
            $randomTweet = $this->getRandomTweet();
            $text = str_replace("{timeline_id}", $randomTweet->user->name,$text);        
        }
        if(strpos($text, "{timeline_name}") !== FALSE){
            $randomTweet = $this->getRandomTweet();
            $text = str_replace("{timeline_name}",$randomTweet->user->screen_name,$text);        
        }
                
        //使うファイルによって違うやつ
        //リプライの場合は相手のid、そうでなければfollowしているidからランダム
        if(strpos($text,"{id}") !== FALSE){
            if(!empty($reply)){
                $text = str_replace("{id}",$reply->user->screen_name,$text);                
            }else{
                $randomTweet = $this->getRandomTweet();
                $text = str_replace("{id}",$randomTweet->user->screen_name,$text);        
            }
        }
        if(strpos($text,"{name}") !== FALSE){
            if(!empty($reply)){
                $text = str_replace("{name}",$reply->user->name,$text);                
            }else{
                $randomTweet = $this->getRandomTweet();
                $text = str_replace("{name}",$randomTweet->user->name,$text);        
            }
        }
        if(strpos($text,"{tweet}") !== FALSE && !empty($reply)){
            $tweet = preg_replace("@\.?\@[a-zA-Z0-9-_]+\s@u","",$reply->status);            
            $text = str_replace("{tweet}",$tweet,$text);                                   
        }            
        //フッターを追加
        $text .= $this->_footer;
        return $text;
    }    
    
    //結果を表示する
    function showResult($response){
        if(!$response->error){
            $message = "Twitterへの投稿に成功しました。<br />";
            $message .= "@<a href='http://twitter.com/".$response->user->screen_name."' target='_blank'>".$response->user->screen_name."</a>";
            $message .= "に投稿したメッセージ：".$response->text;
            $message .= " <a href='http://twitter.com/".$response->user->screen_name."/status/".$response->id."' target='_blank'>http://twitter.com/".$response->user->screen_name."/status/".$response->id."</a><br /><br />";
            echo $message;
            //var_dump($response);
            return array("result"=> $message);
        }else{
            $message = "Twitterへの投稿に失敗しました。<br />";
            $message .= "ユーザー名：@<a href='http://twitter.com/".$this->_screen_name."' target='_blank'>".$this->_screen_name."</a><br /><br />";
            echo $message;
            var_dump($response);
            return array("error" => $message);
        }
    }
    
    //基本的なAPIを叩く
    function _setData($url, $value = array()){                
        $response = $this->consumer->sendRequest($url, $value, "POST");  
        $response = simplexml_load_string($response->getBody());                
        return $response;
    }    
    function _getData($url){                
        $response = $this->consumer->sendRequest($url,array(),"GET");  
        $response = simplexml_load_string($response->getBody());                
        return $response;
    }    
    function setUpdate($value){        
        $url = "https://twitter.com/statuses/update.xml";
        return $this->_setData($url,$value);
    }            
    function getFriendsTimeline(){
        $url = "http://twitter.com/statuses/friends_timeline.xml";
        return $this->_getData($url);                
    }
    function getReplies($page = false)
    {
        $url = "http://twitter.com/statuses/replies.xml";        
        if ($page) {
            $url .= '?page=' . intval($page);
        }
        return $this->_getData($url);
    }        
    function getFriends($id = null)
    {
        $url = "http://twitter.com/statuses/friends.xml";        
        return $this->_getData($url);
    }    
    function getFollowers()
    {
        $url = "http://twitter.com/statuses/followers.xml";        
        return $this->_getData($url);
    }    
    function followUser($screen_name)
    {    
        $url = "http://twitter.com/friendships/create/".$screen_name.".xml";    
        return $this->_setData($url);
    }
}    
?>    
    
