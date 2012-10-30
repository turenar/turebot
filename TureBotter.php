<?php
define("ERR_ID__NOTHING_TO_TWEET", 1);
define("ERR_ID__FAILED_API", 2);
define("ERR_ID__ILLEGAL_FILE", 3);
define("ERR_ID__ILLEGAL_JSON", 4);
define("TWITTER_API_BASE_URL","https://api.twitter.com/1.1/");


/**
 * TureBotterクラス。
 * @author turenar <snswinhaiku dot lo at gmail dot com>
*/
class TureBotter
{
	protected	$consumer;
	private	$tweet_data;
	protected	$screen_name;
	private	$cache_file;
	protected	$cache_data;
	protected	$footer;
	protected	$log_pointer;

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

		$logfp = fopen("{$config_file}.log", 'a');
		if(flock($logfp, LOCK_EX | LOCK_NB)==false){
			$this->log('E', 'bot', 'Unable to obtain lock...');
			throw new Exception('同時起動を避けるため起動を中止します。');
		}

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
		$this->log_pointer = $logfp;
	}

	function __destruct(){
		file_put_contents($this->cache_file, serialize($this->cache_data));
		flock($this->log_pointer, LOCK_UN);
		fclose($this->log_pointer);
	}

	/**
	 * ログ出力
	 * @param string $level EとかWとかIとか
	 * @param string $category カテゴリ名
	 * @param string $text ログテキスト
	 */
	public function log($level, $category, $text){
		echo $level.':'.$text."\n";

		$puttext = sprintf("%s:[%s %s] %s\n", $level, date('Y/m/d H:i:s'), $category, $text);
		fputs($this->log_pointer, $puttext);
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

	/**
	 * 指定した配列がerror配列かどうかを返す。
	 * @param array $arr このクラスの関数の返り値
	 * @return bool error配列かどうか
	 */
	public function is_error(array $arr){
		return isset($arr['error']);
	}

	protected function make_error($errid, $message, $extended = NULL){
		return array(
				'result' => 'error',
				'error'=>array('eid'=>$errid, 'message'=>$message, 'extended'=>$extended));
	}

	protected function make_success(array $response){
		return array(
				'result' => 'success',
				'response'=> $response
		);
	}

	/**
	 * ツイートをフォーマットしたりとか
	 * @param string $text テキスト
	 * @param array $info フォーマット情報<br>
	 *   bool	['footer_disable']: フッタをつけない
	 *   array	['in_reply_to']: リプライ先ツイート
	 * @return string 置き換え済みステータステキスト
	 */
	protected function make_tweet($text, array $info=array()){
		if(preg_match('@{.+?}@', $text) == 1){
			$text = str_replace('{year}', date('Y'), $text);
			$text = str_replace('{month}', date('n'), $text);
			$text = str_replace('{day}', date('j'), $text);
			$text = str_replace('{hour}', date('G'), $text);
			$text = str_replace('{minute}', date('i'), $text);
			$text = str_replace('{second}', date('s'), $text);

			$reply = $this->_get_value($info, 'in_reply_to');
			if(strpos($text, '{id}') !== FALSE){
				if($reply != NULL){
					$text = str_replace('{id}', $reply['user']['screen_name'], $text);
				}
			}
			if(strpos($text, '{name}') !== FALSE){
				if($reply != NULL){
					$text = str_replace('{name}', $reply['user']['name'], $text);
				}
			}
			if(strpos($text, '{tweet}') !== FALSE){
				if($reply != NULL){
					$tweet_text = preg_replace('/\.?@[a-zA-Z0-9\-_]+\s/u', "", $reply['text']);
					$text = str_replace('{tweet}', $tweet_text, $text);
				}
			}
		}

		if($this->_get_value($info, 'footer_disable', false) === false){
			$text .= $this->footer;
		}
		return $text;
	}

	/**
	 * ツイートをフィルタする。古いやつの除去、自分のツイートの除去、RTの除去。
	 * @param array $tweets ツイートの配列
	 * @param bool $filter_reply @が含まれるツイートを除去するかどうか
	 * @return array フィルタ済み配列
	 */
	protected function filter_tweets($tweets, $filter_reply){
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
			// $filter_reply==TRUEのとき@が含まれるツイートを除去
			if($filter_reply && strpos($text, '@') !== false){
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
		$status = $this->make_tweet($status, array('in_reply_to'=>$reply));
		$in_reply_to = $reply['id_str'];

		return array('status'=>$status, 'in_reply_to_status_id'=>$in_reply_to);
	}

	/**
	 * 自動フォロー返しをする
	 * @return array
	 *   <p>following/followerが取得できなかったときはerror配列。</p>
	 *   <p>取得できた場合はつぎの配列の配列: フォローできた時該当ユーザーのuser配列、フォローできなかったときはエラー配列</p>
	 */
	public function autoFollow(){
		$followers = $this->twitter_get_followers_all();
		if($this->is_error($followers)){
			return $followers; // API Error Array
		}

		$following = $this->twitter_get_followings_all();
		if($this->is_error($following)){
			return $following; // API Error Array
		}

		$follow_list = array_diff($followers, $following);
		$results = array();
		foreach($follow_list as $id){
			$response = $this->twitter_follow_user($id);
			if($this->is_error($response)){
				$this->log('W', 'follow', "Failed following user (id=$id): {$response['error']['message']}");
			}else{
				$this->log('I', 'follow', "Followed user: {$response['screen_name']}");
			}
			$results[] = $response;
		}
		return $results;
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
			$this->log('W', 'post', $message);
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
			$this->log('E', 'reply', $message);
			return $this->make_error(ERR_ID__ILLEGAL_FILE, $message);
		}

		$from = $this->_get_value($this->cache_data, 'replied_max_id');

		$response = $this->twitter_get_replies($from);
		if(count($response)===0 || $this->is_error($response)){
			return $response;
		}


		// 受け取ったIDを記録
		$this->cache_data['replied_max_id'] = $response[0]['id_str'];

		$replies = $this->filter_tweets($response, false);

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
			if($this->is_error($response)){
				$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
				$this->log('E', 'post', $message);
				$result[]= $this->make_error(ERR_ID__FAILED_API, $message, $response);
			}else{
				$this->log('I', 'reply', "updated status: {$replyTweet['status']}");
				$result[]= $this->make_success($response);
			}
		}
		return $result;
	}

	/**
	 * タイムラインに反応する。
	 * @param int $ignore 無視される (EasyBotterとの互換用)
	 * @param string $replyPatternFile リプライパターンファイル
	 * @return array 実際に投稿したもののAPIデータを配列で返す。
	 */
	public function replyTimeline($ignore=2, $replyPatternFile='reply_pattern.php'){
		if(preg_match('/\.php$/', $replyPatternFile) == 0){
			$message = "replyPatternFile はPHPファイルでなければなりません: {$replyPatternFile}";
			$this->log('E', 'replyTL', $message);
			return $this->make_error(ERR_ID__ILLEGAL_FILE, $message);
		}

		$from = $this->_get_value($this->cache_data, 'replied_max_id');

		$response = $this->twitter_get_timeline($from);
		if(count($response)===0 || $this->is_error($response)){
			return $response;
		}

		// 受け取ったIDを記録
		$this->cache_data['replied_timeline_max_id'] = $response[0]['id_str'];

		$timeline = $this->filter_tweets($response, true);

		$result = array();
		foreach($timeline as $tweet){
			$replyTweet = $this->make_reply_tweet($tweet, NULL, $replyPatternFile);
			if($replyTweet===NULL){
				continue;
			}
			$parameter = array(
					'status' => $replyTweet['status'],
					'in_reply_to_status_id' => $replyTweet['in_reply_to_status_id']);
			$response = $this->twitter_update_status($parameter);
			if($this->is_error($response)){
				$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
				$this->log('E', 'post', $message);
				$result[]= $this->make_error(ERR_ID__FAILED_API, $message, $response);
			}else{
				$this->log('I', 'replyTL', "updated status: {$replyTweet['status']}");
				$result[]= $this->make_success($response);
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
		if($this->is_error($response)){
			$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
			$this->log('E', 'post', $message);
			return $this->make_error(ERR_ID__FAILED_API, $message);
		}else{
			$this->log('I', 'post', "updated status: $status");
			return $this->make_success($response);
		}
	}

	/**
	 * Twitter v1.1 APIを呼び出す
	 * @param string $request_type POST or GET
	 * @param string $endpoint エンドポイント名。例: statuses/update
	 * @param array $value パラメータ
	 * @return array 成功時はjson_decodeされた配列。エラー時はmake_errorされた配列。
	 */
	protected function twitter_api($request_type, $endpoint, $value=array()){
		$response = $this->consumer->sendRequest(TWITTER_API_BASE_URL.$endpoint.'.json', $value, $request_type)->getResponse();
		$json = json_decode($response->getBody(), true);
		if($response === NULL){
			$this->log('E', 'api', "twitter returned illegal json: $response");
			return $this->make_error(
					ERR_ID__ILLEGAL_JSON, $response->getStatus(), $response);
		}else if($response->getStatus()>=300){
			$this->log('E', 'api', "twitter (endpoint=$endpoint) returned {$response->getStatus()}: ".print_r($json, true));
			return $this->make_error(
					ERR_ID__FAILED_API, $response->getStatus().":".$response->getReasonPhrase(),
					$response);
		}else{
			return $json;
		}
	}

	/**
	 * ツイートを投稿する
	 * @param array $parameters パラメータ
	 * @return array
	 */
	protected function twitter_update_status($parameters){
		return $this->twitter_api('POST', 'statuses/update', $parameters);
	}

	protected function twitter_follow_user($id){
		$parameters = array('user_id'=>$id);
		return $this->twitter_api('POST', 'friendships/create', $parameters);
	}

	/**
	 * リプライを取得する
	 * @param string $since_id パラメータsince_id。
	 * @return array
	 */
	protected function twitter_get_replies($since_id=NULL){
		$parameters=array();
		$parameters['count'] = 200;
		if($since_id!==NULL){
			$parameters['since_id'] = $since_id;
		}
		return $this->twitter_api('GET', 'statuses/mentions_timeline', $parameters);
	}

	/**
	 * タイムラインを取得する
	 * @param string $since_id パラメータsince_id。
	 * @return array
	 */
	protected function twitter_get_timeline($since_id=NULL){
		$parameters=array();
		$parameters['count'] = 200;
		if($since_id!==NULL){
			$parameters['since_id'] = $since_id;
		}
		return $this->twitter_api('GET', 'statuses/home_timeline', $parameters);
	}

	/**
	 * フォロワーを取得する
	 * @param string $screen_name フォロワーを取得するユーザー
	 * @param string $cursor カーソル値 (default: -1)
	 * @return array
	 */
	protected function twitter_get_followers($screen_name, $cursor='-1'){
		// stringify_idsはuser idを文字列として取得するために必要。
		// とりあえず数年はintで大丈夫だと思われるが、念の為。
		$parameters = array('screen_name'=>$screen_name, 'cursor'=>$cursor, 'stringify_ids'=>1);
		return $this->twitter_api('GET', 'followers/ids', $parameters);
	}

	/**
	 * ログインしているユーザーのフォロワーをすべて取得する
	 * @return array フォロワーの<b>固有id</b>の<b>文字列型</b>配列
	 */
	protected function twitter_get_followers_all(){
		$followers = array();
		$screen_name = $this->screen_name;
		$cursor = -1;
		do{
			$response = $this->twitter_get_followers($screen_name, $cursor);
			if($this->is_error($response)){
				return $response;
			}
			$followers = array_merge($followers, $response['ids']);
			$cursor = $response['next_cursor_str'];
		} while($cursor !== '0');
		return $followers;
	}

	/**
	 * フォロイーを取得する
	 * @param string $screen_name フォロイーを取得するユーザー
	 * @param string $cursor カーソル値 (default: -1)
	 * @return array
	 */
	protected function twitter_get_followings($screen_name, $cursor='-1'){
		// stringify_idsはuser idを文字列として取得するために必要。
		// とりあえず数年はintで大丈夫だと思われるが、念の為。
		$parameters = array('screen_name'=>$screen_name, 'cursor'=>$cursor, 'stringify_ids'=>1);
		return $this->twitter_api('GET', 'friends/ids', $parameters);
	}

	/**
	 * ログインしているユーザーのフォロイーをすべて取得する
	 * @return array フォロイーの<b>固有id</b>の<b>文字列型</b>配列
	 */
	protected function twitter_get_followings_all(){
		$followings = array();
		$screen_name = $this->screen_name;
		$cursor = '-1';
		do{
			$response = $this->twitter_get_followings($screen_name, $cursor);
			if($this->is_error($response)){
				return $response;
			}
			$followings = array_merge($followings, $response['ids']);
			$cursor = $response['next_cursor_str'];
		} while($cursor !== '0');
		return $followings;
	}
}
