<?php
define("ERR_ID__NOTHING_TO_TWEET", 1);
define("ERR_ID__FAILED_API", 2);
define("ERR_ID__ILLEGAL_FILE", 3);
define("ERR_ID__ILLEGAL_JSON", 4);
define("TWITTER_API_BASE_URL","https://api.twitter.com/1.1/");
define('_TUREBOT__FOLLOW_TWEET_VFN', '__follow.virtual');


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
	private	$lock_pointer;
	private	$lock_file;
	private	$log_buffer;
	protected	$log_debug_enabled;
	protected	$config;

	/**
	 * インスタンスの作成
	 * @param string $config_file 設定ファイル
	 */
	public function __construct($config_file="setting.php"){
		$peardir = dirname(__FILE__)."/PEAR";
		set_include_path(get_include_path().PATH_SEPARATOR.$peardir);
		date_default_timezone_set("Asia/Tokyo");
		header("Content-Type: text/plain");

		$cfg = array();
		require_once($config_file);
		$_consumer_key = $this->_get_cfg_value($cfg, 'consumer_key');
		$_consumer_secret = $this->_get_cfg_value($cfg, 'consumer_secret');
		$_access_token = $this->_get_cfg_value($cfg, 'access_token');
		$_access_token_secret = $this->_get_cfg_value($cfg, 'access_token_secret');
		$_footer = $this->_get_cfg_value($cfg, 'footer');
		$_flocktype = $this->_get_cfg_value($cfg, 'locktype', 'flock', array('flock','file','none'));
		$_log_debug_enabled = $this->_get_cfg_value($cfg, 'debug_logging', false, array(true,false));
		$lock_file = null;
		$lockfp = null;

		if($_flocktype == 'file'){
			$lock_file = "{$config_file}.lock";
			$lockfp = @fopen($lock_file, 'x');
			if($lockfp===FALSE){
				throw new Exception('同時起動を避けるため起動を中止します。');
			}
		}
		$logfp = fopen("{$config_file}.log", 'at');
		if($_flocktype=='flock' && flock($logfp, LOCK_EX|LOCK_NB)==false){
			$this->log('E', 'bot', 'Unable to obtain lock...');
			throw new Exception('同時起動を避けるため起動を中止します。');
		}

		require_once('HTTP/OAuth/Consumer.php');
		$consumer = new HTTP_OAuth_Consumer($_consumer_key, $_consumer_secret);
		$http_request = new HTTP_Request2();
		$http_request->setConfig('ssl_verify_peer',false);
		$consumer_request = new HTTP_OAuth_Consumer_Request();
		$consumer_request->accept($http_request);
		$consumer->accept($consumer_request);
		$consumer->setToken($_access_token);
		$consumer->setTokenSecret($_access_token_secret);

		$cache_file = "{$config_file}.cache";
		if(file_exists($cache_file)){
			$cache_data = json_decode(file_get_contents($cache_file), true);
		} else {
			$cache_data = array();
		}

		$this->consumer = $consumer;
		$this->tweet_data = array();
		$this->cache_file = $cache_file;
		$this->cache_data = $cache_data;
		$this->footer = $_footer;
		$this->log_pointer = $logfp;
		$this->lock_pointer = $lockfp;
		$this->lock_file = $lock_file;
		$this->log_buffer = array();
		$this->log_debug_enabled = $_log_debug_enabled;
		$this->config = $cfg;

		$user_info = $this->get_user_information();
		if($user_info === NULL){
			$this->log('E', 'api', 'Could not retrieve login-ed user information. Please check credentials!');
			die;
		}
		$this->screen_name = $user_info['screen_name'];
	}

	function __destruct(){
		file_put_contents($this->cache_file, json_encode($this->cache_data));
		if($this->lock_pointer === NULL){
			flock($this->log_pointer, LOCK_UN);
		}
		fclose($this->log_pointer);
		if($this->lock_pointer !== NULL){
			fclose($this->lock_pointer);
			unlink($this->lock_file);
		}
	}

	/**
	 * ログ出力
	 * @param string $level EとかWとかIとか
	 * @param string $category カテゴリ名
	 * @param string $text ログテキスト
	 */
	public function log($level, $category, $text){
		echo $level.':'.$text."\n";

		if($level === 'D'){
			return;
		}

		$puttext = sprintf("%s:[%s %s] %s\n", $level, date('Y/m/d H:i:s'), $category, $text);
		if($this->log_pointer === NULL){
			$this->log_buffer[] = $puttext;
		}else{
			foreach($this->log_buffer as $text){
				fputs($this->log_pointer, $text);
			}
			$this->log_buffer = array();
			fputs($this->log_pointer, $puttext);
		}
	}

	/**
	 * デバッグ出力
	 * @param string $category カテゴリ
	 * @param string $text デバッグテキスト
	 */
	protected function debug($category, $text){
		if($this->log_debug_enabled){
			$this->log('D', $category, $text);
		}
	}
	/**
	 * 配列から値を取得する。$indexが存在しないとき$defaultを返す
	 * @param array $arr 配列
	 * @param mixed $index キー
	 * @param mixed $default デフォルト値
	 * @return mixed $arr[$index]または$default
	 */
	protected function _get_value(array $arr, $index, $default=NULL){
		return isset($arr[$index]) ? $arr[$index] : $default;
	}

	/**
	 * 配列から値を取得する (値のチェックを行う)。$indexが存在しないとき$defaultを返す。
	 * @param array $arr 配列
	 * @param mixed $index キー
	 * @param mixed $default デフォルト値
	 * @return mixed $arr[$index]または$default
	 */
	protected function _get_cfg_value(array $arr, $index, $default=NULL, array $vallist=NULL){
		if(isset($arr[$index]) === false){
			return $default;
		}
		$value = $arr[$index];
		if($vallist !== NULL){
			if(in_array($value, $vallist) === false){
				$this->log('W', 'config', "Illegal config value (key=$index): $value. Use default ($default)");
				$value = $default;
			}
		}
		return $value;
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
	protected function get_next_tweet($file, array &$reject_tweets=array()){
		$this->load_tweet($file);
		$tweet_data = $this->tweet_data[$file];

		$data_count = count($tweet_data);
		if($data_count == 0){
			return null;
		}
		// 一番最初にarray_diffしないのは処理が重いと怖いので(ただし要検証)
		if($data_count <= count($reject_tweets)){
			// できるだけ重複しないようにテキストを選ぶ
			$tweet_data = array_diff($tweet_data, $reject_tweets);
			if(count($tweet_data) == 0){
				// 選べるツイートがない時はduplicateエラーが返されなさそうな
				// テキストを選ぶことにする
				$text = array_shift($reject_tweets);
				$reject_tweets[] = $text;
				return $text;
			}
		}

		do{
			$text = $tweet_data[array_rand($tweet_data)];
			$hash = md5($text);
		} while(in_array($hash, $reject_tweets) !== false);
		$reject_tweets[] = $hash;
		return $text;
	}

	/**
	 * 指定した配列がerror配列かどうかを返す。
	 * @param array $arr このクラスの関数の返り値
	 * @return bool error配列かどうか
	 */
	public function is_error(array $arr){
		return isset($arr['error']);
	}

	/**
	 * エラー情報の作成
	 * @param int $errid エラーID
	 * @param string $message エラーメッセージ
	 * @param mixed $extended 拡張オブジェクト
	 * @return array エラーオブジェクト
	 */
	protected function make_error($errid, $message, $extended = NULL){
		return array(
				'result' => 'error',
				'error'=>array('eid'=>$errid, 'message'=>$message, 'extended'=>$extended));
	}

	/**
	 * エラー無しでの完了情報の作成
	 * @param array $response レスポンス
	 * @return array 成功情報
	 */
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
		if($this->_get_value($info, 'footer_disable', false) === false){
			$text .= $this->footer;
		}

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
		$replied_text = html_entity_decode($reply['text'], ENT_COMPAT, 'UTF-8');
		foreach($pattern_data as $pattern => $res){
			if(count($res)!=0){
				if(preg_match('@'.$pattern.'@u', $replied_text, $matches) === 1){
					$text = $res[array_rand($res)];
					for($i=count($matches)-1; $i>=1; $i--){
						$text = str_replace('$'.$i, $matches[$i], $text);
					}
					break;
				}
			}
		}
		// パターンになかった場合
		if(empty($text) && !empty($reply_file)){
			$text = $this->get_next_tweet($reply_file);
		}
		if(empty($text) || $text == '[[END]]'){
			return NULL;
		}
		$screen_name = $reply['user']['screen_name'];

		if(strpos($text, "[[AUTOFOLLOW]]") !== false){
			$this->log('I', 'follow', " detected [[AUTOFOLLOW]]: (to $screen_name) $text");
			$text = str_replace('[[AUTOFOLLOW]]', '', $text);

			$follow_req = $this->twitter_follow_user($reply['user']['id_str']);
			if($this->is_error($follow_req)){
				$this->log('W', 'follow', " Failed follow user: $screen_name");
			}
		}else if(strpos($text, "[[AUTOREMOVE]]") !== false){
			$this->log('I', 'remove', " detected [[AUTOREMOVE]]: (to $screen_name) $text");
			$text = str_replace('[[AUTOREMOVE]]', '', $text);

			$follow_req = $this->twitter_remove_user($reply['user']['id_str']);
			if($this->is_error($follow_req)){
				$this->log('W', 'remove', " Failed remove user: $screen_name");
			}
		}
		$text = $this->make_tweet($text, array('in_reply_to'=>$reply));

		$status = array();
		if(strpos($text, '[[TLRT]]') !== FALSE){
			$text = str_replace('[[TLRT]]', '', $text);
			$text = sprintf('%s RT @%s: %s', $text, $screen_name, $replied_text);
			$text = mb_substr($text, 0, 140, 'UTF-8');
		}else{
			$text = sprintf('@%s %s', $screen_name, $text);
			$status['in_reply_to_status_id'] = $reply['id_str'];
		}
		$status['status'] = $text;
		return $status;
	}

	/**
	 * 自動フォロー返しをする
	 * @return array
	 *   <p>following/followerが取得できなかったときはerror配列。</p>
	 *   <p>取得できた場合はつぎの配列の配列: フォローできた時該当ユーザーのuser配列、フォローできなかったときはエラー配列</p>
	 */
	public function autoFollow(){
		$this->debug('follow', '#autoFollow()');
		$this->debug('follow', ' Getting followers...');
		$followers = $this->twitter_get_followers_all();
		if($this->is_error($followers)){
			return $followers; // API Error Array
		}

		$this->debug('follow', ' Getting followings...');
		$following = $this->twitter_get_followings_all();
		if($this->is_error($following)){
			return $following; // API Error Array
		}

		$follow_list = array_diff($followers, $following);
		$results = array();

		if(count($follow_list) !== 0){
			$status_texts = $this->_get_cfg_value($this->config, 'text_when_autoFollow', array());
			$this->tweet_data[_TUREBOT__FOLLOW_TWEET_VFN] = (array) $status_texts;
		}
		foreach($follow_list as $id){
			$response = $this->twitter_follow_user($id);
			if($this->is_error($response)){
				$this->log('W', 'follow', " Failed following user (id=$id): {$response['error']['message']}");
			}else{
				$screen_name = $response['screen_name'];
				$this->log('I', 'follow', " Followed user: $screen_name");
				$status_text = $this->get_next_tweet(_TUREBOT__FOLLOW_TWEET_VFN);
				if($status_text){
					$status_text = sprintf('@%s %s', $screen_name, $status_text);
					$status_text = $this->make_tweet($status_text);
					$status = $this->twitter_update_status(array('status'=>$status_text));
					if($this->is_error($status)){
						$this->log('W', 'follow', " Failed updating status: $status_text");
					}
				}
			}
			$results[] = $response;
		}
		$this->debug('follow', 'Finish');
		return $results;
	}

	/**
	 * ランダムにポストする
	 * @param string $datafile データファイル名
	 * @return array [result]="error|success"
	 */
	public function postRandom($datafile = "data.txt"){
		$this->debug('post', '#postRandom');
		$tweeted = $this->_get_value($this->cache_data, 'tweeted', array());
		$status_text = $this->get_next_tweet($datafile, $tweeted);
		if(count($tweeted) > 10){
			$tweeted = array_slice($tweeted, -10, 10); //10個のみ保存
		}
		$this->cache_data['tweeted'] = $tweeted;

		if($status_text === NULL || trim($status_text) === ""){
			$message = "投稿するメッセージがありません";
			$this->log('I', 'post', $message);
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
		$this->debug('reply', '#reply');

		$from = $this->_get_value($this->cache_data, 'replied_max_id');

		$response = $this->twitter_get_replies($from);
		if(count($response)===0 || $this->is_error($response)){
			$this->debug('reply', ' got nothing as replies');
			$this->debug('reply', 'Finish');
			return $response;
		}


		$this->debug('reply', sprintf(' got %dtweets as replies', count($response)));
		// 受け取ったIDを記録
		$this->cache_data['replied_max_id'] = $response[0]['id_str'];

		$replies = $this->filter_tweets($response, false);
		$this->debug('reply', sprintf(' target: %dtweet', count($replies)));

		$result = array();
		foreach($replies as $reply){
			$replyTweet = $this->make_reply_tweet($reply, $replyFile, $replyPatternFile);
			if($replyTweet===NULL){
				continue;
			}
			$response = $this->twitter_update_status($replyTweet);
			if($this->is_error($response)){
				$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
				$this->log('E', 'post', $message);
				$result[]= $this->make_error(ERR_ID__FAILED_API, $message, $response);
			}else{
				$this->log('I', 'reply', "updated status: {$replyTweet['status']}");
				$result[]= $this->make_success($response);
			}
		}
		$this->debug('reply', 'Finish');
		return $result;
	}

	/**
	 * タイムラインに反応する。
	 * @param int $ignore 無視される (EasyBotterとの互換用)
	 * @param string $replyPatternFile リプライパターンファイル
	 * @return array 実際に投稿したもののAPIデータを配列で返す。
	 */
	public function replyTimeline($ignore=2, $replyPatternFile='reply_pattern.php'){
		$this->debug('replyTL', '#replyTimeline');
		if(preg_match('/\.php$/', $replyPatternFile) == 0){
			$message = "replyPatternFile はPHPファイルでなければなりません: {$replyPatternFile}";
			$this->log('E', 'replyTL', $message);
			return $this->make_error(ERR_ID__ILLEGAL_FILE, $message);
		}

		$from = $this->_get_value($this->cache_data, 'replied_timeline_max_id');

		$response = $this->twitter_get_timeline($from);
		if(count($response)===0 || $this->is_error($response)){
			$this->debug('replyTL', ' got nothing as timeline');
			$this->debug('replyTL', 'Finish');
			return $response;
		}
		$this->debug('replyTL', sprintf(' got %dtweets as timeline', count($response)));

		// 受け取ったIDを記録
		$this->cache_data['replied_timeline_max_id'] = $response[0]['id_str'];

		$timeline = $this->filter_tweets($response, true);
		$this->debug('replyTL', sprintf(' target: %dtweet', count($timeline)));

		$result = array();
		foreach($timeline as $tweet){
			$replyTweet = $this->make_reply_tweet($tweet, NULL, $replyPatternFile);
			if($replyTweet===NULL){
				continue;
			}
			$response = $this->twitter_update_status($replyTweet);
			if($this->is_error($response)){
				$message = "Twitterへの投稿に失敗: {$response['error']['message']}";
				$this->log('E', 'post', $message);
				$result[]= $this->make_error(ERR_ID__FAILED_API, $message, $response);
			}else{
				$this->log('I', 'replyTL', "updated status: {$replyTweet['status']}");
				$result[]= $this->make_success($response);
			}
		}
		$this->debug('replyTL', 'Finish');
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
		if($json === NULL){
			$this->log('E', 'api', "twitter returned illegal json: $response");
			return $this->make_error(
					ERR_ID__ILLEGAL_JSON, $response->getStatus(), $response->getBody());
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
	protected function twitter_update_status(array $parameters){
		return $this->twitter_api('POST', 'statuses/update', $parameters);
	}

	/**
	 * ユーザーをフォローする
	 * @param string $id <b>ユーザー固有ID</b>の文字列型
	 * @return array
	 */
	protected function twitter_follow_user($id){
		$parameters = array('user_id'=>$id);
		return $this->twitter_api('POST', 'friendships/create', $parameters);
	}

	/**
	 * ユーザーをリムーブする
	 * @param string $id <b>ユーザー固有ID</b>の文字列型
	 * @return array
	 */
	protected function twitter_remove_user($id){
		$parameters = array('user_id'=>$id);
		return $this->twitter_api('POST', 'friendships/destroy', $parameters);
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
	 * アカウント情報の取得
	 * @return array
	 */
	protected function twitter_verify_credentials(){
		$this->debug('api', 'Fetching account information...');
		return $this->twitter_api('GET', 'account/verify_credentials', array('skip_status'=>1));
	}

	/**
	 * アカウント情報の取得。使用可能ならばキャッシュを返す
	 * @return NULL|array
	 */
	public function get_user_information(){
		$data = $this->_get_value($this->cache_data, 'logining_user');
		$cfg = $this->config;
		if($data !== NULL && $data['access_token'] != $cfg['access_token']) {
			$data = NULL; // cached data may be different from logined user's
		}
		if($data === NULL || $data['updated_date']+3600 <= time()) {
			$user = $this->twitter_verify_credentials();
			if($this->is_error($user)){
				$user = $this->twitter_verify_credentials();
				if($this->is_error($user)){
					return $data===NULL ? NULL : $data['user']; // expired cache or NULL
				}
			}

			$data = array(
				'user' => $user,
				'updated_date' => time(),
				'access_token' => $cfg['access_token']);
			$this->cache_data['logining_user'] = $data;
		}
		return $data['user'];
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

	/**
	 * 受信したダイレクトメッセージを取得する
	 * @return array
	 */
	protected function twitter_direct_messages($since_id=NULL){
		$parameters = array('skip_status'=>1);
		if($since_id !== NULL){
			$parameters['since_id'] = $since_id;
		}
		return $this->twitter_api('GET', 'direct_messages', $parameters);
	}
	/**
	 * ダイレクトメッセージを送る
	 * @param string $user_id ユーザー固有ID (id_str)
	 * @param string $text テキスト
	 * @return array
	 */
	protected function twitter_direct_message_send($user_id, $text){
		$parameters = array('user_id'=>$user_id, 'text'=>$text);
		return $this->twitter_api('POST', 'direct_messages/new', $paramaters);
	}
}
