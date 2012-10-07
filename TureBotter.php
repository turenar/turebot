<?php
define("SQL_COMMAND_USERDATA", "CREATE TABLE `user_data` (
		`userid` VARCHAR( 32 ) NOT NULL ,
		`key` VARCHAR( 32 ) NOT NULL ,
		`value` VARCHAR( 255 ) NULL ,
		PRIMARY KEY ( `userid` , `key` )
		) ENGINE = MYISAM CHARACTER SET utf8");
define("ERR_ID__NOTHING_TO_TWEET", 1);
define("ERR_ID__FAILED_API", 2);
define("TWITTER_API_BASE_URL","https://api.twitter.com/1.1/");

class TureBotter
{
	private $consumer;
	private $tweet_data;

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

		$this->consumer = $consumer;
		$this->tweet_data = array();
	}

	protected function output($level, $category, $text){
		echo $level.':'.$text."\n";
	}
	/**
	 * ツイートデータを読み込む
	 *
	 * @param string $file ツイートファイル
	 */
	protected function load_tweet($file){
		if(preg_match('/\.php$/', $file) == 1){
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
		if(isset($this->tweet_data[$file]) == false){
			$this->load_tweet($file);
		}
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

	protected function make_tweet($text){
		if(preg_match('@{.+?}@', $text) == 1){
			$text = str_replace('{year}', date('Y'), $text);
			$text = str_replace('{month}', date('n'), $text);
			$text = str_replace('{day}', date('j'), $text);
			$text = str_replace('{hour}', date('G'), $text);
			$text = str_replace('{minute}', date('i'), $text);
			$text = str_replace('{second}', date('s'), $text);
		}
		return $text;
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
	 * 指定した文字列を投稿する
	 * @param string $status 投稿する文字列
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
		$response = $this->consumer->sendRequest(TWITTER_API_BASE_URL.$endpoint.'.json', $value, "POST")->getResponse();
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
}
