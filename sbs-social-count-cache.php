<?php
/*
Plugin Name: SBS Social Count Cache
Plugin URI: https://wordpress.org/plugins/step-by-step-social-count-cache/
Description: ソーシャルブックマークのカウントをキャッシュするプラグイン
Version: 1.1.1
Author: oxynotes
Author URI: http://oxynotes.com
License: GPL2

// お決まりのGPL2の文言（省略や翻訳不可）
Copyright 2015 oxy (email : oxy@oxynotes.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/




/*
 * 正式名称「Step by Step Social Count Cache」
 * 
 * 1.各種SNSのカウント数を取得し、キャッシュをMySQLに保存する
 * 1.設定画面で設定したキャッシュの有効期限の間はデータベースにキャッシュされたカウントを返す
 * 1.キャッシュの有効期間は投稿の更新から「1日以内」「1日～1週間まで」「それ以降」の3段階
 *   投稿日からでなく、更新日からの点に注意
 * 
 * テンプレートのループ内で以下のように記述する。
 * <?php
 * $socal_count = sbs_get_all();
 * echo $socal_count["twitter"];
 * echo $socal_count["facebook"];
 * echo $socal_count["google"];
 * echo $socal_count["hatena"];
 * echo $socal_count["pocket"];
 * echo $socal_count["feedly"];
 * ?>
 * 
 * もしくは個別に取得して書き出す方法
 * 
 * <?php
 * echo sbs_get_twitter();
 * echo sbs_get_facebook();
 * echo sbs_get_google();
 * echo sbs_get_hatena();
 * echo sbs_get_pocket();
 * echo sbs_get_feedly();
 * ?>
 */




// インストールパスのディレクトリが定義されているか調べる（プラグインのテンプレ）
if ( !defined('ABSPATH') ) {exit();}




/**
 * インストール時と、停止時に実行される関数の定義
 * 
 * activate_plugin関数という特別な関数で呼び出されるため、
 * この関数の実行時点ではグローバル変数にアクセス権を持たない
 * インスタンスも作成されているわけではないので$thisも使えない
 * そのため初期設定の値を取りたい場合は静的変数で処理する（$sbs_db_versionなど）
 * 直接呼び出す関数public、間接的に呼び出す関数はprivateで大丈夫
 * 
 * ちなみにプラグインの更新時に呼び出されないので注意
 */
register_activation_hook( __FILE__, array( 'SBS_SocialCountCache', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SBS_SocialCountCache', 'deactivate' ) );



// クラスが定義済みか調べる
if ( !class_exists('SBS_SocialCountCache') ) {

class SBS_SocialCountCache {

	// FacebookのApp Token用
	public $sbs_facebook_app_token = "";

	// 設定画面のキャシュ期間と有効なSNSに関するユーザー設定
	public $sbs_user_settings = "";

	// テーブルアップデート用テーブルバージョンの指定
	public static $sbs_db_version = "1.0";




	/**
	 * 初期設定
	 * 
	 * cache有効期限（日数）
	 * 設定ページで入力した数値を取得
	 * 
	 * FacebookのApp Tokenも取得
	 * 
	 * add_action系もコンストラクタ内で処理する
	 */
	public function __construct() {

		$this->sbs_facebook_app_token = get_site_option('sbs_facebook_app_token');
		$this->sbs_active_sns = get_site_option('sbs_active_sns');
		$this->sbs_cache_time = get_site_option('sbs_cache_time');

		add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_mysettings' ) );

	}




	// インストール時の初期設定
	public function activate() {

		// カウントを保存するためのテーブルを作る（既にテーブルがある場合は作らない）
		self::create_tables();

		// インストールされているテーブルのバージョンを調べて、異なればアップデートする
		self::update_tables();

		// オプションのデフォルト値を保存する
		self::set_user_settings();

	}




	// アンインストール時の設定
	public static function deactivate() {

		// データベースとオプションを削除する
		self::uninstall();

	}




	/**
	 * テーブルを作る関数（アクティベーション用関数）
	 * 既にテーブルがある場合は作らない
	 */
	private static function create_tables() {
		global $wpdb;

		$sql = "";
		$charset_collate = "";

		// 接頭辞の追加（socal_count_cache）
		$table_name = $wpdb->prefix . 'socal_count_cache';

		// charsetを指定する
		if ( !empty($wpdb->charset) )
			$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset} ";

		// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
		if ( !empty($wpdb->collate) )
			$charset_collate .= "COLLATE {$wpdb->collate}";

		// SQL文でテーブルを作る
		$sql = "
			CREATE TABLE {$table_name} (
				postid bigint(20) NOT NULL,
				day datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				twitter_count bigint(20) DEFAULT 0,
				facebook_count bigint(20) DEFAULT 0,
				google_count bigint(20) DEFAULT 0,
				hatena_count bigint(20) DEFAULT 0,
				pocket_count bigint(20) DEFAULT 0,
				feedly_count bigint(20) DEFAULT 0,
				PRIMARY KEY  (postid)
			) {$charset_collate};";

		// 現在のテーブル構造を走査し比較して作成・更新してくれるdbDeltaを読み込みSQLを実行する
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);

		// オプションでテーブルーのバージョンを指定
		// 解説ではadd_option()となっているが、
		// update_optionを使えば、対応する名前のオプションが無い場合add_option()と同じように作成してくれるのでこちらを使う
		// ちなみにオプションは<接頭辞>optionsテーブルに保存される
		update_option( 'sbs_db_version', self::$sbs_db_version );

	} // end __create_tables




	/**
	 * テーブルアップデート用関数
	 */
	private static function update_tables() {
		global $wpdb;

		$installed_ver = get_option( "sbs_db_version" );

		// セットされているデータベースのバージョンと、
		// プラグインの先頭で指定したデータベースのバージョンを照らし合わせる
		if ( $installed_ver != self::$sbs_db_version ) {

			$sql = "";
			$charset_collate = "";

			// 接頭辞の追加（socal_count）
			$table_name = $wpdb->prefix . 'socal_count_cache';

			// charsetを指定する
			if ( !empty($wpdb->charset) )
				$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset} ";

			// 照合順序を指定する（ある場合。通常デフォルトのutf8_general_ci）
			if ( !empty($wpdb->collate) )
				$charset_collate .= "COLLATE {$wpdb->collate}";

			// SQL文でテーブルを作る
			$sql = "
				CREATE TABLE {$table_name} (
					postid bigint(20) NOT NULL,
					day datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
					twitter_count bigint(20) DEFAULT 0,
					facebook_count bigint(20) DEFAULT 0,
					google_count bigint(20) DEFAULT 0,
					hatena_count bigint(20) DEFAULT 0,
					pocket_count bigint(20) DEFAULT 0,
					feedly_count bigint(20) DEFAULT 0,
					PRIMARY KEY  (postid)
				) {$charset_collate};";

			// 現在のテーブル構造を走査し比較して作成・更新してくれるdbDeltaを読み込みSQLを実行する
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);

			// オプションでテーブルーのバージョンを指定
			update_option( 'sbs_db_version', self::$sbs_db_version );

		} // if ( $installed_ver != $jal_db_version )
	} // end __update_tables()




	/**
	 * オプションのデフォルト値をセットする
	 * 
	 * 設定を無効にした時点で削除されるので、
	 * インストール時には値があることは通常無い
	 */
	private static function set_user_settings() {

		$sbs_cache_time = get_site_option('sbs_cache_time');
		if ( !$sbs_cache_time ) {

			// 設定のデフォルトの値
			$default_active_sns = array(
				'twitter' => 1,
				'facebook' => 1,
				'google' => 1,
				'hatena' => 1,
				'pocket' => 1,
				'feedly' => 1,
				'rss_type' => 'RSS2'
			);

			// 設定のデフォルトの値
			$default_cache_time = array(
				'1day' => array(
					'day' => 0,
					'hour' => 0,
					'minute' => 30
				),
				'1week' => array(
					'day' => 1,
					'hour' => 0,
					'minute' => 0
				),
				'after' => array(
					'day' => 7,
					'hour' => 0,
					'minute' => 0
				)
			);

			update_option( 'sbs_active_sns', $default_active_sns );
			update_option( 'sbs_cache_time', $default_cache_time );
		}
	}




	/**
	 * プラグイン削除時の処理
	 * 
	 * 追加したテーブルと、オプションテーブルのカラムを削除
	 */
	private static function uninstall() {

	    global $wpdb;

		// 接頭辞の追加
		$table_name = $wpdb->prefix . 'socal_count_cache';

		// 有効にした時点でテーブルが無いことはありえないが一応IF EXISTSを追加
		$wpdb->query("DROP TABLE IF EXISTS $table_name");

		delete_option('sbs_db_version');
		delete_option('sbs_facebook_app_token');
		delete_option('sbs_active_sns');
		delete_option('sbs_cache_time');
		delete_option('sbs_delete_apc_cache');
	}




	/**
	 * オプションページを追加する
	 * 
	 * 第4引数はオプションページのスラッグ
	 * 第5引数の関数でオプションページを呼び出すコールバック関数を指定している
	 */
	public function add_plugin_admin_menu() {
		add_options_page(
			'SBS Social Count Cache', // page_title
			'SBS Social Count Cache', // menu_title
			'administrator', // capability
			'sbs-social-count-cache', // menu_slug
			array( $this, 'display_plugin_admin_page' ) // function
		);
	}




	/**
	 * オプションページを表示するためのphpファイルとCSSを指定
	 * add_plugin_admin_menu()で呼び出された関数
	 * 
	 * ルートディレクトリにファイルを設置すると以下のエラーで読み込めない謎
	 * Fatal error: Cannot redeclare _wp_menu_output()と出る
	 */
	public function display_plugin_admin_page() {
		include_once( 'views/options.php' );
		wp_enqueue_style( "sbs-cocial-count-cache", plugins_url( 'style/options.css', __FILE__ ) );
	}




	/**
	 * オプションページで追加するオプションの項目を追加
	 * 
	 * admin_initはオプション画面が読み込まれる前に実行される
	 * register_settingの第一引数はオプションページのスラッグ
	 * 第2引数は各オプションのnameと一致させる
	 * delete_apc_cacheという項目は無いがオプションページを更新時のコールバック関数として記述。
	 */
	public function register_mysettings() {
		register_setting( 'sbs-social-count-cache', 'sbs_facebook_app_token', array( $this, 'token_validation' ) );
		register_setting( 'sbs-social-count-cache', 'sbs_active_sns', array( $this, 'active_sns_validation' ) );
		register_setting( 'sbs-social-count-cache', 'sbs_cache_time', array( $this, 'cache_time_validation' ) );
		register_setting( 'sbs-social-count-cache', 'sbs_delete_apc_cache', array( $this, 'delete_apc_cache' ) );
	}




	/**
	 * 開始ナンバーと、終了ナンバーを入れると
	 * 数字の数だけプルダウンメニューを作成する関数
	 *
	 * @since	1.0.0
	 * @param	int		開始number
	 * @param	int		開始number
	 * @param	int		初期選択値
	 * @param	str		最初の項目
	 */
	private function time_loop( $start, $end, $first = NULL, $str ){	
		echo '<option value="$str">' . $str . '</option>';
		if ( isset($first) ){
			for( $i = $start; $i <= $end; $i++ ){
				if ( $first == $i ) {
					echo '<option value="' . $i . '" selected="selected">' . $i . '</option>';
				} else {
					echo '<option value="' . $i . '">' . $i . '</option>';
				}
			}
		} else {
			for( $i = $start; $i <= $end; $i++ ){
				echo '<option value="' . $i . '">' . $i . '</option>';
			}
		}
	}




	/**
	 * このプラグインで作成したAPCのキャッシュを削除する関数
	 * APCuだとinfoがkeyに変更されているため、APCのみにあるtypeで条件分岐
	 */
	function delete_apc_cache() {
		//すべてのユーザキャッシュを取得する
		if ( function_exists( 'apc_store' ) && ini_get( 'apc.enabled' ) ) { // apcモジュール読み込まれており、更に有効かどうか調べる
			$userCache = apc_cache_info('user');
			$sbs_apc_key = "sbs_db_cache_" . md5( __FILE__ ); // md5で一意性確保（作成時と合わせるべし）

			if ( isset( $userCache["type"] ) ){ // APCの場合

				foreach($userCache['cache_list'] as $key => $cacheList){
					//  キーに$this->sbs_apc_keyが含まれているキャッシュを削除する
					if( strpos( $cacheList['info'], $sbs_apc_key ) !== false ){
						apc_delete( $cacheList['info'] );
					}
				}

			} else { // APCuの場合

				foreach($userCache['cache_list'] as $key => $cacheList){
					//  キーに$this->sbs_apc_keyが含まれているキャッシュを削除する
					if( strpos( $cacheList['key'], $sbs_apc_key ) !== false ){
						apc_delete( $cacheList['key'] );
					}
				}
			}
		}
	}




	/**
	 * SNSのオンオフ用のバリデーション関数
	 * 後にRSSフィードの種類も追加
	 * intval()で数値に変換。誤った値が入ると0になる
	 *
	 * @param	int		1か0、もしくはRSS,RSS2,Atom
	 */
	function active_sns_validation( $input ) {

		foreach( $input as $key => $val ){

			if( $input[$key] == 'RSS' || $input[$key] == 'RSS2' || $input[$key] == 'Atom' ){
				$input[$key] = $input[$key];
			} else {
				$input[$key] = intval( $input[$key] );
			}
		}

		return $input;
	}




	/**
	 * キャッシュ期間用のバリデーション関数
	 * intval()で数値に変換。誤った値が入ると0になる
	 *
	 * @param	int		キャッシュの期間
	 */
	function cache_time_validation( $input ) {

		foreach( $input as $key1 => $val1 ){
			foreach( $val1 as $key2 => $val2 ){
				$input[$key1][$key2] = intval( $input[$key1][$key2] );
			}
		}

		return $input;
	}




	/**
	 * Facebook App Token用のバリデーション関数
	 * 英数字とバーティカルバー以外が入力されているとエラーを返す
	 *
	 * @param	str		Facebook App Token
	 */
	function token_validation( $input ) {

		if( empty( $input ) || preg_match( "/^[a-zA-Z0-9|]+$/", $input ) ) {
			return $input;
		}else{
			return 'validation_error';
		}

	}




	/**
	 * 基準の日時から有効期限を算出する
	 *
	 * @param	int		基準の時間（現在の時間）
	 * @param	int		○日
	 * @param	int		○時間
	 * @param	int		○分
	 * @return	int		返り値はUNIX Time
	 */
	public function exp_time( $current_time, $day, $hour, $minute ) {

			// 0の場合があるので、それぞれ個別に出して足してる
			$u_day = ($day * 24 * 60 * 60);
			$u_hour = ($hour * 60 * 60);
			$u_minute = ($minute * 60);
			return $current_time - ( $u_day + $u_hour + $u_minute );
	}




	/**
	 * twitterのカウントを返す
	 *
	 * @param	str		投稿のURL
	 * @return	int		返り値はカウント
	 */
	public function get_twitter( $url ) {
		$twit_uri = 'http://urls.api.twitter.com/1/urls/count.json?url=' . rawurlencode($url);
		$result = wp_remote_get( $twit_uri, array( 'timeout' => 5 ) );

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			$array = json_decode( $result["body"], true ); // jsonをデコード。trueで連想配列に変換
			return $array["count"];
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}
	}




	/**
	 * facebookのカウントを返す
	 *
	 * @param	str		投稿のURL
	 * @global	str		オプションページで設定したFacebookのApp Token
	 * @return	int		返り値はカウント
	 */
	public function get_facebook( $url ) {

		if ( empty($this->sbs_facebook_app_token) ) {
			return "エラー：設定ページでApp Tokenを入力してください";
		}

		// Facebook APIの2.4を利用した方法。アクセストークンが必要になった。
		$like_uri = 'https://graph.facebook.com/v2.4/' . rawurlencode($url) . '?access_token=' . $this->sbs_facebook_app_token;
		$result = wp_remote_get( $like_uri, array( 'timeout' => 5 ) ); // たまに異常に重い時があるので注意

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			$array = json_decode( $result["body"], true ); // jsonをデコード。trueで連想配列に変換

			// Facebookはカウントが存在しないURLだとNULLを返すので分岐する
			if( is_null( $array["share"]["share_count"] ) ) {
				return 0;
			} else {
				return $array["share"]["share_count"];
			}
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}
	}




	/**
	 * googleのカウントを返す
	 *
	 * @param	str		投稿のURL
	 * @return	int		返り値はカウント
	 */
	public function get_google( $url ){
		// xamppでhttpsにアクセスすると以下のエラーが。php.iniの最後にextension=php_openssl.dllを付けてサーバ再起動で治る
		// Unable to find the wrapper "https" - did you forget to enable it when you configured PHP?
		$result = wp_remote_get( "https://plusone.google.com/_/+1/fastbutton?url=" . $url, array( 'timeout' => 5 ) );

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			$doc = new DOMDocument();
			libxml_use_internal_errors(true); // Warning: DOMDocument::loadHTML():対策
			$doc->loadHTML($result["body"]);
			$counter = $doc->getElementById('aggregateCount');
			return (int) $counter->nodeValue; // 仕組み上文字列になるので数列にキャスト
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}
	}




	/**
	 * はてなのカウントを返す
	 *
	 * @param	str		投稿のURL
	 * @return	int		返り値はカウント
	 */
	public function get_hatena( $url ) {
		$hate_uri = 'http://b.hatena.ne.jp/entry/jsonlite/?url=' . rawurlencode($url); //カウントのみならjsonliteのほうがより高速
		$result = wp_remote_get( $hate_uri, array( 'timeout' => 5 ) );

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			$array = json_decode( $result["body"], true ); // jsonをデコード。trueで連想配列に変換

			// はてなはカウントが存在しないURLだとNULLを返すので分岐する
			if( is_null( $array["count"] ) ) {
				return 0;
			} else {
				return $array["count"];
			}
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}
	}




	/**
	 * Pocketのカウントを返す
	 *
	 * @param	str		投稿のURL
	 * @return	int		返り値はカウント
	 */
	public function get_pocket( $url ) {
		$pocket_uri = 'http://widgets.getpocket.com/v1/button?v=1&count=horizontal&url=' . rawurlencode($url);
		$result = wp_remote_get( $pocket_uri, array( 'timeout' => 5 ) );

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			$dom = new DOMDocument('1.0', 'UTF-8');
			$dom->preserveWhiteSpace = false;
			$dom->loadHTML($result["body"]);
			$xpath = new DOMXPath($dom);
			$content = $xpath->query('//em[@id = "cnt"]')->item(0);
			return (int) $content->nodeValue;
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}

	}




	/**
	 * feedlyのカウントを返す
	 *
	 * @return	int		返り値はカウント
	 */
	public function get_feedly(){

		// 設定したRSSフィードの種類を指定
		if ( $this->sbs_active_sns['rss_type'] == 'RSS' ) {
			$feed_url = rawurlencode( get_bloginfo( 'rss_url' ) );
		} elseif( $this->sbs_active_sns['rss_type'] == 'RSS2' ) {
			$feed_url = rawurlencode( get_bloginfo( 'rss2_url' ) );
		} else {
			$feed_url = rawurlencode( get_bloginfo( 'atom_url' ) );
		}

		$result = wp_remote_get( 'http://cloud.feedly.com/v3/feeds/feed%2F' . $feed_url );
		$array = json_decode( $result["body"], true );

		if ( !is_wp_error( $result ) && $result["response"]["code"] === 200 ) {
			// カウントが無いと[]を返すので対策
			if( !isset( $array['subscribers'] ) ){
				return 0;
			}else{
				return $array['subscribers'];
			}
		} else { // エラー処理が面倒なので0を返す
			return 0;
		}
	}




} // end class

} // if class




// インスタンスの作成（コンストラクタの実行）
$obj = new SBS_SocialCountCache();




/**
 * ソーシャルメディアのカウントを返す
 *
 * 投稿の更新から1日まで、1日～1週間まで、それ以降の三段階で異なるキャッシュの有効期間を持つ
 * キャッシュが有効期間の場合はデータベースに保存したキャッシュを返す
 * キャッシュが有効期限外の場合はそれぞれのAPIを利用してカウントを取得し直す
 * 
 * それぞれのキャッシュは取得日と共に<接頭辞>socal_count_cacheテーブルに保存される
 *
 * @global	object		wpdb
 * @global	array		オプションページで設定したキャッシュ期間の設定
 * @global	array		ユーザーの設定したキャッシュの有効期限
 * @param	str			取得するsnsの指定
 * @return	array|int	返り値は全てのソーシャル名とカウントの配列か数列
 */
function sbs_get_socal_count( $active_sns = null ) {

	global $wpdb;

	// ユーザー設定の取得
	$sbs = new SBS_SocialCountCache();
	$sbs_active_sns = $sbs->sbs_active_sns;
	$sbs_cache_time = $sbs->sbs_cache_time;


	// 投稿のIDとURLを取得する
	$postid = get_the_ID();
	$url = get_permalink( $postid );


/*
	// test用
	$postid = 105;
	$url = "http://oxynotes.com/?p=2933";
*/




	// 設定ページで指定したキャッシュの有効期限を算出する（UNIX time）

	/*
	WordPressではdateやstrtotimeの使用は設定によってずれるので推奨されず、
	代わりにcurrent_timeやget_date_from_gmtの使用が推奨されている。
	また投稿の最終更新日を取得するにはget_the_modified_timeを使う。

	しかしこれらの関数には謎の仕様があり、なぜか+9時間される。
	設定画面でUTC+9にしているが、プラグインで使うと更に+9される？？？
	今回はデータベースの値と、現在の時間が両方+9時間されるので支障ないが、
	厳密に時間を出さないといけない場合は注意が必要だ。
	普通に書くと-9時間されたUTCが返ってくるならわかるが、本当に謎。
	国際化のための使用なのかもしれないが、ありがた迷惑。わざわざソースまで追うのが面倒。
	そしてCodexの当該タグのページにも解説なし。
	*/

	$pfx_date = get_the_modified_time('U'); // 投稿の最終更新日時取得
	$current_time = current_time('timestamp'); // ブログのローカルタイム取得 これも+9時間されるちょっと意味わからん
	$day = $current_time - (1 * 24 * 60 * 60); // ブログのローカルタイムから1日前のUNIXタイムを取得
	$week = $day - (6 * 24 * 60 * 60); // 1週間




	// 最終更新日が1日以内、1週間以内、それ以上の場合で振り分け、現時点での有効期限を算出
	if ( $pfx_date > $day ) { // 最終更新日が1日以内の場合
		$exp_time = $sbs->exp_time($current_time, $sbs_cache_time['1day']['day'], $sbs_cache_time['1day']['hour'], $sbs_cache_time['1day']['minute']);
		// var_dump("最終更新日が1日以内"); // テスト用
	} elseif( $pfx_date > $week ) { // 1週間以内
		$exp_time = $sbs->exp_time($current_time, $sbs_cache_time['1week']['day'], $sbs_cache_time['1week']['hour'], $sbs_cache_time['1week']['minute']);
		// var_dump("最終更新日が1週間以内"); // テスト用
	} else { // それ以上
		$exp_time = $sbs->exp_time($current_time, $sbs_cache_time['after']['day'], $sbs_cache_time['after']['hour'], $sbs_cache_time['after']['minute']);
		// var_dump("最終更新日が1週間以上"); // テスト用
	}

	// 対応する投稿IDのキャッシュをデータベースから取得する
	// acpが有効な場合はapcにデータを保存して再利用する
	$table_name = $wpdb->prefix . "socal_count_cache";

	if ( function_exists( 'apc_store' ) && ini_get( 'apc.enabled' ) ) { // apcモジュール読み込まれており、更に有効かどうか調べる
		$sbs_apc_key = "sbs_db_cache_" . md5( __FILE__ ); // md5で一意性確保
		// var_dump("apc有効");
		if ( apc_fetch( $sbs_apc_key . $postid ) ) { // キャッシュがある場合
			$result = apc_fetch( $sbs_apc_key . $postid );
			// var_dump("apcキャッシュ見つかった");
		} else { // キャッシュがない場合（データベースのキャッシュを取得）
			$query = "SELECT day,twitter_count,facebook_count,google_count,hatena_count,pocket_count,feedly_count FROM {$table_name} WHERE postid = {$postid}";
			$result = $wpdb->get_row($query);
			apc_store( $sbs_apc_key . $postid, $result, strtotime($result->day) - $exp_time); // APCの有効期限は、キャッシュ作成日-有効期限で算出。期限切れの場合はマイナスになるがAPCでは問題ないようだ
			// var_dump("apcキャッシュ見つからない");
		}
	} else {
		$query = "SELECT day,twitter_count,facebook_count,google_count,hatena_count,pocket_count,feedly_count FROM {$table_name} WHERE postid = {$postid}";
		$result = $wpdb->get_row($query);
		// var_dump("apc無効");
	}




	// var_dump("投稿の最終更新日時取得" . date("Y-m-d H:i:s",$pfx_date) . "<br>");
	// var_dump("ブログのローカルタイム取得" . date("Y-m-d H:i:s",$current_time) . "<br>");
	// var_dump("有効期限" . date("Y-m-d H:i:s",$exp_time) . "<br>");
	// var_dump("キャッシュの取得時間" . date("Y-m-d H:i:s",strtotime($result->day)) . "<br>");

	// キャッシュの取得日時が有効期限内の場合
	if ( strtotime($result->day) > $exp_time ) {

		// var_dump("キャッシュが有効期限内"); // テスト用

		// 値を返すSNSを引数から指定
		// オプションで無効なSNSはデータベースに値があっても0を返す
		// 有効期限内なのでデータベースの値をそのまま渡す
		if ( $active_sns == "all") {
			$socials = array();

			if( !empty( $sbs_active_sns['twitter'] ) ) { // !付きemptyなので0の場合もfalse
				$socials['twitter'] = $result->twitter_count;
			} else {
				$socials['twitter'] = 0;
			}

			if( !empty( $sbs_active_sns['facebook'] ) ) {
				$socials['facebook'] = $result->facebook_count;
			} else {
				$socials['facebook'] = 0;
			}

			if( !empty( $sbs_active_sns['google'] ) ) {
				$socials['google'] = $result->google_count;
			} else {
				$socials['google'] = 0;
			}

			if( !empty( $sbs_active_sns['hatena'] ) ) {
				$socials['hatena'] = $result->hatena_count;
			} else {
				$socials['hatena'] = 0;
			}

			if( !empty( $sbs_active_sns['pocket'] ) ) {
				$socials['pocket'] = $result->pocket_count;
			} else {
				$socials['pocket'] = 0;
			}

			if( !empty( $sbs_active_sns['feedly'] ) ) {
				$socials['feedly'] = $result->feedly_count;
			} else {
				$socials['feedly'] = 0;
			}
		} elseif ( $active_sns == 'twitter' ) {
			if( !empty( $sbs_active_sns['twitter'] ) ) {
				$socials = $result->twitter_count;
			} else {
				$socials = 0;
			}
		} elseif ( $active_sns == 'facebook' ) {
			if( !empty( $sbs_active_sns['facebook'] ) ) {
				$socials = $result->facebook_count;
			} else {
				$socials = 0;
			}
		} elseif ( $active_sns == 'google' ) {
			if( !empty( $sbs_active_sns['google'] ) ) {
				$socials = $result->google_count;
			} else {
				$socials = 0;
			}
		} elseif ( $active_sns == 'hatena' ) {
			if( !empty( $sbs_active_sns['hatena'] ) ) {
				$socials = $result->hatena_count;
			} else {
				$socials = 0;
			}
		} elseif ( $active_sns == 'pocket' ) {
			if( !empty( $sbs_active_sns['pocket'] ) ) {
				$socials = $result->pocket_count;
			} else {
				$socials = 0;
			}
		} elseif ( $active_sns == 'feedly' ) {
			if( !empty( $sbs_active_sns['feedly'] ) ) {
				$socials = $result->feedly_count;
			} else {
				$socials = 0;
			}
		}

		return $socials;

	} else { // 有効期限切れの場合

		// var_dump("キャッシュが有効期限切れ"); // テスト用

		// URLからカウントを取得する
		// 一つだけを呼び出した場合でもオプションページで設定したものは全部取得する
		$socials = array();

		if( !empty( $sbs_active_sns['twitter'] ) ) {
			$socials['twitter'] = $sbs->get_twitter($url);
		} else {
			$socials['twitter'] = 0;
		}

		if( !empty( $sbs_active_sns['facebook'] ) ) {
			$socials['facebook'] = $sbs->get_facebook($url);
		} else {
			$socials['facebook'] = 0;
		}

		if( !empty( $sbs_active_sns['google'] ) ) {
			$socials['google'] = $sbs->get_google($url);
		} else {
			$socials['google'] = 0;
		}

		if( !empty( $sbs_active_sns['hatena'] ) ) {
			$socials['hatena'] = $sbs->get_hatena($url);
		} else {
			$socials['hatena'] = 0;
		}

		if( !empty( $sbs_active_sns['pocket'] ) ) {
			$socials['pocket'] = $sbs->get_pocket($url);
		} else {
			$socials['pocket'] = 0;
		}

		if( !empty( $sbs_active_sns['feedly'] ) ) {
			$socials['feedly'] = $sbs->get_feedly();
		} else {
			$socials['feedly'] = 0;
		}

		$now = current_time('mysql'); // ブログ時間を取得するWordPressの関数。（YYYY-MM-DD HH:MM:SS）

		// 取得したカウントを日時とともにデータベースへ書き込み
		// ON DUPLICATE KEY UPDATEでプライマリキーのpostidをフラグに無ければINSERT、あればUPDATE
		// $nowのプレースホルダーを''で囲むの忘れずに
		$result2 = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table_name}
			(postid, day, twitter_count, facebook_count, google_count, hatena_count, pocket_count, feedly_count)
			VALUES (%d, %s, %d, %d, %d, %d, %d, %d)
			ON DUPLICATE KEY UPDATE day = '%2\$s',
			twitter_count = %3\$d,
			facebook_count = %4\$d,
			google_count = %5\$d,
			hatena_count = %6\$d,
			pocket_count = %7\$d,
			feedly_count = %8\$d",
			$postid,
			$now,
			$socials['twitter'],
			$socials['facebook'],
			$socials['google'],
			$socials['hatena'],
			$socials['pocket'],
			$socials['feedly']
		));

		// 値を返すSNSを引数から指定
		// 出力用に整形
		if( $active_sns == "all" ) {
			$socials['twitter'] = $socials['twitter'];
			$socials['facebook'] = $socials['facebook'];
			$socials['google'] = $socials['google'];
			$socials['hatena'] = $socials['hatena'];
			$socials['pocket'] = $socials['pocket'];
			$socials['feedly'] = $socials['feedly'];
		} elseif ( $active_sns == "twitter" ) {
			$socials = $socials['twitter'];
		} elseif ( $active_sns == "facebook" ) {
			$socials = $socials['facebook'];
		} elseif ( $active_sns == "google" ) {
			$socials = $socials['google'];
		} elseif ( $active_sns == "hatena" ) {
			$socials = $socials['hatena'];
		} elseif ( $active_sns == "pocket" ) {
			$socials = $socials['pocket'];
		} elseif ( $active_sns == "feedly" ) {
			$socials = $socials['feedly'];
		}

		return $socials;
	}
}




/**
 * Template tag - sbs_get_socal_count()を使って全てのカウントを返す
 */
function sbs_get_all() {
	return sbs_get_socal_count( 'all' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってtwitterのカウントを返す
 */
function sbs_get_twitter() {
	return sbs_get_socal_count( 'twitter' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってfacebookのカウントを返す
 */
function sbs_get_facebook() {
	return sbs_get_socal_count( 'facebook' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってgoogleのカウントを返す
 */
function sbs_get_google() {
	return sbs_get_socal_count( 'google' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってhatenaのカウントを返す
 */
function sbs_get_hatena() {
	return sbs_get_socal_count( 'hatena' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってpocketのカウントを返す
 */
function sbs_get_pocket() {
	return sbs_get_socal_count( 'pocket' );
}




/**
 * Template tag - sbs_get_socal_count()を使ってfeedlyのカウントを返す
 */
function sbs_get_feedly() {
	return sbs_get_socal_count( 'feedly' );
}






