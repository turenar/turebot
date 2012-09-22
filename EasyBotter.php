<?php
//======================================================================
//EasyBotter Ver2.04beta
//updated 2010/02/28
//======================================================================
define("SQL_COMMAND_USERDATA", "CREATE TABLE `user_data` (
`userid` VARCHAR( 32 ) NOT NULL ,
`key` VARCHAR( 32 ) NOT NULL ,
`value` VARCHAR( 255 ) NULL ,
PRIMARY KEY ( `userid` , `key` )
) ENGINE = MYISAM CHARACTER SET utf8");

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
		
		private $_limitFollowUsers;
		private $_limitFollowRatio;

		private $_dbhandle; // get_handler()を使用。
		private $_db_name;
		private $_db_user;
		private $_db_pass;
        
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

		$this->_limitFollowUsers = $limitFollowUsers;
		$this->_limitFollowRatio = $limitFollowRatio;
		
		$this->_db_name = $database;
		$this->_db_user = $databaseUser;
		$this->_db_pass = $databasePassword;
        
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
        if($this->_dbhandle !== NULL){
			mysql_close($this->_dbhandle); // not necessary?
		}
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
        $status = $this->rento_check($datafile);                
        if(empty($status)){
            $message = "投稿するメッセージがないようです。<br />";
            echo $message;
            return array("error"=> $message);
        }else{                
            //idなどの変換
			//フッターを付けるためにコメントアウト
            // if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status);
            // }            
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
                //if(preg_match("@{.+?}@",$status) == 1){
                    $status = $this->convertText($status);
                //}            
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
					if(isset($re["in_reply_to_status_id"]))
	                    $value = array("status"=>$re["status"],'in_reply_to_status_id'=>$re["in_reply_to_status_id"]);    
					else
	                    $value = array("status"=>$re["status"]);    
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
    
	//■■ランダムPOST・重複チェック■■
	function rento_check($file){
       if(empty($this->_tweetData[$file])){
			$this->_tweetData[$file] = $this->readDataFile($file);
		}
		$rento_limit = 10; // n個前まで投稿を記録し、二重投稿を回避する
		$twit_logfile = "twit_log.txt";
		//$twit_logfileは存在するか？
		if(!file_exists($twit_logfile)){
			touch($twit_logfile) or die('ファイル作成に失敗\n');
			chmod($twit_logfile, 0606) or die('権限変更に失敗\n');//※パーミッションは鯖によって違います
		}
		$Posttweets = file_get_contents($twit_logfile); // 読み込み
		$p_tw = explode("\n", $Posttweets); // 配列に格納
		do{
			//発言をランダムに一つ選ぶ
			$status = $this->_tweetData[$file][array_rand($this->_tweetData[$file])];
		}while(in_array($status, $p_tw));

		$p_tw2[0] = $status;//投稿ログをローテート
		for( $i = 1; $i < $rento_limit; $i++ ){ //1から$rento_limit直前まで
			if(isset($p_tw[$i-1])) {
				$p_tw2[$i] = $p_tw[$i-1]; //古いのを送る。
			}else{ break; } //投稿が少ない時は抜ける
		}
		$p_tw_output = join("\n",$p_tw2); //配列結合
		$fp = fopen('twit_log.txt', 'r+'); //ファイルオープン
		flock($fp, LOCK_EX); // ファイルのロック（排他制御）
		ftruncate($fp, 0);
		fwrite($fp,$p_tw_output); //ファイル書き込み
		fclose($fp); //ファイルクローズ
		return $status; //違う文を戻り値として返す
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
            //if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status, $reply);
            //}
            $reply_name = (string)$reply->user->screen_name;        
            $in_reply_to_status_id = (string)$reply->id;
            if(stristr($status, "[[AUTOFOLLOW]]")){
				$status = str_replace("[[AUTOFOLLOW]]", "", $status);
				if(in_array($reply->user->screen_name, $this->limitFollowUsers))
					continue; // フォロー制限対象者は無視
				$followReq = $this->followUser($reply_name);
				if($followReq->error) continue; // 失敗したときはとりあえず無視
			}else if(stristr($status, "[[AUTOREMOVE]]")){
				$status = str_replace("[[AUTOREMOVE]]", "", $status);
				$removeReq = $this->consumer->sendRequest("http://api.twitter.com/friendships/destroy/$reply_name.json", array(), "POST");
				if($followReq->error) continue; // 失敗したときはｒｙ
			}
			if(stristr($status, "[[TLH]]")){
				$status = str_replace("[[TLH]]", "", $status);
				$re["status"] = $status;
			}else if(stristr($status, "[[TLRT]]")){
				$status = str_replace("[[TLRT]]", "", $status);
				$status = "$status RT @{$reply_name}: {$reply->text}";
				$re["status"] = substr($status, 0, 140); // 140文字に制限
			}else{
				$re["status"] = "@".$reply_name." ".$status;
            	$re["in_reply_to_status_id"] = $in_reply_to_status_id;
			}
            
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
            //if(preg_match("@{.+?}@",$status) == 1){
                $status = $this->convertText($status, $tweet);
            //}            
            $reply_name = (string)$tweet->user->screen_name;        
            $in_reply_to_status_id = $tweet->id;
			if(stristr($status, "[[TLH]]")){
				$status = str_replace("[[TLH]]", "", $status);
				$re["status"] = $status;
			}else if(stristr($status, "[[TLRT]]")){
				$status = str_replace("[[TLRT]]", "", $status);
				$status = "$status RT @{$reply_name}: {$reply->text}";
				$re["status"] = substr($status, 0, 140); // 140文字に制限
			}else{
				$re["status"] = "@".$reply_name." ".$status;
            	$re["in_reply_to_status_id"] = $in_reply_to_status_id;
			}
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
            if( $follow == "false"
			 && ($user->friends_count / (double) $user->followers_count) <= $this->_limitFollowRatio + 0.00001 // doubleの誤差を見越して
			 && !in_array($user->screen_name, $this->_limitFollowUsers)){
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
        if(preg_match("@{.+?}@",$text) == 1){
			// 静的に置き換えできるものは前もって置き換え
            $text = str_replace("{year}",date("Y"),$text);
            $text = str_replace("{month}",date("n"),$text);
            $text = str_replace("{day}",date("j"),$text);
            $__hour = date("G");
            $text = str_replace("{hour}",$__hour,$text);
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
                $text = str_replace("{timeline_id}", $randomTweet->user->screen_name,$text);        
            }
            if(strpos($text, "{timeline_name}") !== FALSE){
                $randomTweet = $this->getRandomTweet();
                $text = str_replace("{timeline_name}",$randomTweet->user->name,$text);        
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
                $tweet = preg_replace("@\.?\@[a-zA-Z0-9-_]+\s@u","",$reply->text);            
                $text = str_replace("{tweet}",$tweet,$text);                                   
            }            

			while( FALSE !== ($syntax_start = strrpos($text, "{"))){
				$syntax_end = strpos($text, "}", $syntax_start);
				if($syntax_end === FALSE){
					/*ERROR*/echo "\{と\}の数が合わないか、位置が正しくありません";
					break;
				}
				$func_separator = strpos($text, ":", $syntax_start);
				$func = substr($text, $syntax_start, $func_separator-$syntax_start);
				
				switch($func){
					case "{ifhour": /* {ifhour: <hour>[-<hour>] : <text>} */
                		/*int*/$offset = $syntax_start + strlen("{ifhour:"); // 文字分だけスキップ
                		/*int*/$separator_position = strpos($text, ":", $offset);
                		/*str*/$hours_string = substr($text, $offset, $separator_position-$offset);
                		/*bool*/$expand_flag = FALSE;
                		if( FALSE !== ($rangepos = strpos($hours_string, "-")) ){ // <hour>-<hour>の書式のとき
                		    /*int*/$start = intval(trim(substr($hours_string, 0, $rangepos)));
                    		/*int*/$end = intval(trim(substr($hours_string, $rangepos+1)));
                    		if($start<0||$start>23||$end<0||$end>23){
                    		    $message = "<p>Syntax warning: {ifhour:&lt;hour&gt;-&lt;hour&gt;:&lt;text&gt;}の&lt;hour&gt;は、";
                    		    $message = "0-23の範囲で指定するようにして下さい</p>";
                    		    /*WARN*/echo $message;
                    		}
							/*DEBUG*/echo "<blockquote>ifhour: $start - $end</blockquote>";
                    		$expand_flag = ($start<=$end? ($start<=$__hour&&$__hour<=$end):
                    		                              ($start<=$__hour||$__hour<=$end));
                		}else{
                		    $expand_flag = intval($hours_string) == $__hour;
                		}
		
						if($expand_flag){
							$text = substr_replace($text, 
												   substr($text,$separator_position+1,$syntax_end-$separator_position-1),
												   $syntax_start, 
												   $syntax_end-$syntax_start+1);
						}else{
							$text = substr_replace($text, "", $syntax_start, $syntax_end-$syntax_start+1);
						}
						/*DEBUG*/echo "<blockquote>ifhour: replaced: $text</blockquote>";
						break;

					case "{store": /* {store: <key> : <value>} */
						/*int*/$offset = $syntax_start + strlen("{store:"); // 文字分だけスキップ
                		/*int*/$separator_position = strpos($text, ":", $offset);
                		/*str*/$key = trim(substr($text, $offset, $separator_position-$offset));
						/*str*/$val = trim(substr($text,$separator_position+1, $syntax_end-$separator_position-1));
						$this->storeUserData((empty($reply) ? "__system" : $reply->user->id), $key, $val);
						$text = substr_replace($text, "", $syntax_start, $syntax_end-$syntax_start+1);
						break;

					case "{get": /* {get: <key> : <default>} */
						/*int*/$offset = $syntax_start + strlen("{get:"); // 文字分だけスキップ
                		/*int*/$separator_position = strpos($text, ":", $offset);
                		/*str*/$key = trim(substr($text, $offset, $separator_position-$offset));
						/*str*/$default = trim(substr($text,$separator_position+1, $syntax_end-$separator_position-1));
						/*str*/$val = $this->getUserData((empty($reply) ? "__system" : $reply->user->id), $key, $default);
						$text = substr_replace($text, $val, $syntax_start, $syntax_end-$syntax_start+1);
						break;
        		}
			}
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

	// [ture7] MySQLに接続する
	function get_dbhandle(){
		if($this->_dbhandle == NULL){
			$error_message = NULL;
			$this->_dbhandle = mysql_connect("localhost", $this->_db_user, $this->_db_pass)
				or die("<p style='color:red;'>Failed open database: $error_message</p>");
			mysql_select_db($this->_db_name, $this->_dbhandle);
			mysql_set_charset('utf8');
		}
		return $this->_dbhandle;
	}
	
	// [ture7] MySQLのデータベースを作成する
	function initdb(){
		$result = $this->execute_sql(SQL_COMMAND_USERDATA);
		if($result){
			echo "Successfully created table";
		}else{
			echo "Failed creating table";
		}
	}

	// [ture7] MySQLでSQLコマンドを実行する
	function execute_sql($sql){
		$dbhandle = $this->get_dbhandle();
		$result = mysql_query($sql, $dbhandle);
		if($result === FALSE){
			$error_message = mysql_error($dbhandle);
			echo "<p>Could not execute sql($sql): $error_message</p>";
		}
		return $result;
	}

	// [ture7] MySQLを使ってKV型を追加する
	function storeUserData($userid, $key, $value){
		$dbhandle = $this->get_dbhandle();
		$sql = sprintf("REPLACE INTO `user_data` ( `userid`, `key`, `value` ) values ( '%s', '%s', '%s' )"
						, mysql_real_escape_string($userid, $dbhandle), mysql_real_escape_string($key, $dbhandle)
						, mysql_real_escape_string($value, $dbhandle));
		$this->execute_sql($sql);
	}
    
	// [ture7] MySQLを使ってKV型を取得する
	function getUserData($userid, $key, $default = ""){
		$dbhandle = $this->get_dbhandle();
		$sql = sprintf("SELECT `value` FROM `user_data` WHERE `userid` = '%s' AND `key` = '%s'"
						, mysql_real_escape_string($userid, $dbhandle), mysql_real_escape_string($key, $dbhandle));
		$result = $this->execute_sql($sql);
		if(mysql_num_rows($result) > 0){
			$row = mysql_fetch_row($result);
			return $row[0];
		} else {
			return $default;
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
    
