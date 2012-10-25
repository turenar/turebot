<?php
define("SQL_COMMAND_USERDATA", "CREATE TABLE `user_data` (
		`userid` VARCHAR( 32 ) NOT NULL ,
		`key` VARCHAR( 32 ) NOT NULL ,
		`value` VARCHAR( 255 ) NULL ,
		PRIMARY KEY ( `userid` , `key` )
		) ENGINE = MYISAM CHARACTER SET utf8");
define("ERR_ID__NOTHING_TO_TWEET", 1);
define("ERR_ID__FAILED_API", 2);
define("ERR_ID__ILLEGAL_FILE", 3);
define("TWITTER_API_BASE_URL","https://api.twitter.com/1.1/");

class TureBotter
{
	protected	$consumer;
	private	$tweet_data;
	protected	$screen_name;
	private	$cache_file;
	protected	$cache_data;
	protected	$footer;

	/**
	 * インスタンスの作成
	 * @param string $config_file 設定ファイル
	 */
	public function __construct($config_file="setting.php"){
		$peardir = dirname(__FILE__)."/PEAR";
		set_include_path(get_include_path().PATH_SEPARATOR.$peardir);
		date_default_timezone_set("Asia/Tokyo");
		header("Content-Type: text/plain");

		require_once($config_file);

		require_once('HTTP/OAuth/Consumer.php');
		$consumer = new HTTP_OAuth_Consumer($consumer_key, $consumer_secret);
		$http_request = new HTTP_Request2();
		$http_request->setConfig('ssl_verify_peer',false);
		$consumer_request = new HTTP_OAuth_Consumer_Request();
		$consumer_request->accept($http_request);
		$consumer->accept($consumer_request);
		$consumer->setToken($access_token);
		$consumer->setTokenSecret($access_token_secret);

		$cache_file = "{$config_file}.cache";
		if(file_exists($cache_file)){
			$cache_data = unserialize(file_get_contents($cache_file));
		} else {
			$cache_data = array();
		}

		$this->consumer = $consumer;
		$this->tweet_data = array();
		$this->screen_name = $screen_name;
		$this->cache_file = $cache_file;
		$this->cache_data = $cache_data;
		$this->footer = $footer;
	}

	function __destruct(){
		file_put_contents($this->cache_file, serialize($this->cache_data));
	}

	/**
	 * ログ出力
	 * @param string $level EとかWとかIとか
	 * @param string $category カテゴリ名
	 * @param string $text ログテキスト
	 */
	protected function output($level, $category, $text){
		echo $level.':'.$text."\n";
	}

	protected function _get_value(array $arr, $index, $default=NULL){
		return isset($arr[$index]) ? $arr[$index] : $default;
	}

	/**
	 * ツイートデータを読み込む
	 *
	 * @param string $file ツイートファイル
	 */
	protected function load_tweet($file){
		if(isset($this->tweet_data[$file])){
			return;
		}

		if(preg_match('/\.php$/', $file) == 1){
			$data = array();
			require_once($file);
			$this->tweet_data[$file] = $data;
		}else{
			$this->tweet_data[$file] = file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
		}
	}
	/**
	 * 次のツイートを取得する
	 * @param string $file ツイートファイル名
	 * @return NULL|string ツイート文字列
	 */
	protected function get_next_tweet($file){
		$this->load_tweet($file);
		$tweet_data = $this->tweet_data[$file];

		if(count($tweet_data)==0){
			return null;
		}else{
			return $tweet_data[array_rand($tweet_data)];
		}
	}

	protected function make_error($errid, $message){
		return array(
				"result" => "error",
				"error"=>array("eid"=>$errid, "message"=>$message));
	}

	/**
	 * ツイートをフォーマットしたりとか
	 * @param string $text テキスト
	 * @param array $info フォーマット情報<br>
	 *   bool ['footer_disable']: フッタをつけない
	 * @return mixed
	 */
	protected function make_tweet($text, array $info=array()){
		if(preg_match('@{.+?}@', $text) == 1){
			$text = str_replace('{year}', date('Y'), $text);
			$text = str_replace('{month}', date('n'), $text);
			$text = str_replace('{day}', date('j'), $text);
			$text = str_replace('{hour}', date('G'), $text);
			$text = str_replace('{minute}', date('i'), $text);
			$text = str_replace('{second}', date('s'), $text);
		}

		if($this->_get_value($info, 'footer_disable', false) === false){
			$text .= $this->footer;
		}
		return $text;
	}

	/**
	 * ツイートをフィルタする。古いやつの除去、自分のツイートの除去、RTの除去。
	 * @param array $tweets ツイートの配列
	 * @return array フィルタ済み配列
	 */
	protected function filter_tweets($tweets){
		$result = array();
		$limitTime = time() - 60*60; // あまりにも古いやつは捨てる
		foreach($tweets as $tweet){
			// 古いやつは捨てる
			if($limitTime >= strtotime($tweet['created_at'])){
				continue;
			}
			// 自分自身のツイートを除外
			if($this->screen_name == $tweet['user']['screen_name']){
				continue;
			}
			// RT, QTを除外
			$text = $tweet['text'];
			if(strpos($text, 'RT') !== false || strpos($text, 'QT') !== false){
				continue;
			}
			$result[] = $tweet;
		}
		return $result;
	}

	/**
	 * リプライツイートを作る
	 * @param array $reply
	 * @param string $reply_file 既定リプライデータファイル
	 * @param string $reply_pattern_file リプライパターンファイル
	 * @return NULL|array
	 *   NULLの場合は返すツイートがない。その他の場合は、
	 *   [status], [in_reply_to_status_id]の配列を返す。
	 */
	protected function make_reply_tweet(array $reply, $reply_file, $reply_pattern_file){
		$this->load_tweet($reply_pattern_file);
		$pattern_data = $this->tweet_data[$reply_pattern_file];
		$text = $reply['text'];
		foreach($pattern_data as $pattern => $res){
			if(count($res)!=0){
				if(preg_match('@'.$pattern.'@u', $text, $matches) === 1){
					$status = $res[array_rand($res)];
					for($i=count($matches)-1; $i>=1; $i--){
						$status = str_replace('$'.$i, $matches[$i], $status);
					}
					break;
				}
			}
		}
		// パターンになかった場合
		if(empty($status) && !empty($reply_file)){
			$status = $this->get_next_tweet($reply_file);
		}
		if(empty($status) || $status == '[[END]]'){
			return NULL;
		}
		$screen_name = $reply['user']['screen_name'];
		$status = '@' . $screen_name . ' ' . $status;
		$status = $this->make_tweet($status, array('reply_to'=>$reply));
		$in_reply_to = $reply['id_str'];

		return array('status'=>$status, 'in_reply_to_status_id'=>$in_reply_to);
	}

	/**
	 * ランダムにポストする
	 * @param string $datafile データファイル名
	 * @return array [result]="error|success"
	 */
	public function postRandom($datafile = "data.txt"){
		$status_text = $this->get_next_tweet($datafile);
		if($status_text === NULL || trim($status_text) === ""){
			$message = "投稿するメッセージがありません";
			$this->output('E', 'post', $message);
			return $this->make_error(ERR_ID__NOTHING_TO_TWEET, $message);
		}else{
			return $this->post($status_text);
		}
	}

	/**
	 * リプライを行う
	 * @param int $ignore 無視される (EasyBotterとの互換用)
	 * @param string $replyFile リプライデータファイル
	 * @param string $replyPatternFile リプライパターンファイル
	 * @return array 実際に投稿したもののAPIデータを配列で返す。
	 */
	public function reply($ignore=2, $replyFile='data.txt', $replyPatternFile='reply_pattern.php'){
		if(preg_match('/\.php$/', $replyPatternFile) == 0){
			$message = "replyPatternFile はPHPファイルでなければなりません: {$replyPatternFile}";
			$this->output('E', 'reply', $message);
			return $this->make_error(ERR_ID__ILLEGAL_FILE, $message);
		}
		$from = isset($this->cache_data['replied_max_id'])?$this->cache_data['replied_max_id']:NULL;
		$response = $this->twitter_get_replies($from);
		if(count($response)===0){
			return array();
		}
		// 受け取ったIDを記録
		$this->cache_data['replied_max_id'] = $response[0]['id_str'];

		$replies = $this->filter_tweets($response);
		$result = array();
		foreach($replies as $reply){
			$replyTweet = $this->make_reply_tweet($reply, $replyFile, $replyPatternFile);
			if($replyTweet===NULL){
				continue;
			}
			$parameter = array(
					'status' => $replyTweet['status'],
					'in_reply_to_status_id' => $replyTweet['in_reply_to_status_id']);
			$response = $this->twitter_update_status($parameter);
			if(isset($response['error'])){
				$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
				$this->output('E', 'post', $message);
				$result[]= $this->make_error(ERR_ID__FAILED_API, $message);
			}else{
				$this->output('I', 'reply', "updated status: {$replyTweet['status']}");
				$result[]=array('result'=>'success', 'response'=>$response);
			}
		}
		return $result;
	}

	/**
	 * 指定した文字列を投稿する
	 * @param string $status_text 投稿する文字列
	 */
	public function post($status_text){
		$status = $this->make_tweet($status_text);
		$response = $this->twitter_update_status(array("status" => $status));
		if(isset($response['error'])){
			$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
			$this->output('E', 'post', $message);
			return $this->make_error(ERR_ID__FAILED_API, $message);
		}else{
			$this->output('I', 'post', "updated status: $status");
			return array("result"=>"success","response"=>$response);
		}
	}

	/**
	 * APIを呼び出す
	 * @param unknown_type $request_type POST or GET
	 * @param unknown_type $endpoint エンドポイント名。例: statuses/update
	 * @param unknown_type $value パラメータ
	 * @return array
	 */
	protected function twitter_api($request_type, $endpoint, $value=array()){
		$response = $this->consumer->sendRequest(TWITTER_API_BASE_URL.$endpoint.'.json', $value, $request_type)->getResponse();
		$json = json_decode($response->getBody(), true);
		if($response->getStatus()>=400){
			$this->output('E', 'api', "twitter returned {$response->getStatus()}: ".print_r($json, true));
			return $this->make_error(ERR_ID__FAILED_API, $response->getStatus().":".$response->getReasonPhrase());
		}
		return $json;
	}

	/**
	 * ツイートを投稿する
	 * @param array $parameters パラメータ
	 * @return array
	 */
	protected function twitter_update_status($parameters){
		return $this->twitter_api('POST', 'statuses/update', $parameters);
	}

	/**
	 * リプライを取得する
	 * @param string $since_id パラメータsince_id。
	 * @return array
	 */
	protected function twitter_get_replies($since_id=NULL){
		$parameters=array();
		if($since_id!==NULL){
			$parameters['since_id'] = $since_id;
		}
		return $this->twitter_api('GET', 'statuses/mentions_timeline', $parameters);
	}
}
