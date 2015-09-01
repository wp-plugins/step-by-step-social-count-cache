<?php

/*
 * プラグインを有効時に走らせようかと思ったが、
 * Facebookのtokenやfeedlyのrssをカスタマイズする設定を付けたため、
 * 正確な値を取得するためにも設定画面でプリロードボタンを付ける
 * 
 * 公開されている投稿のSNSのカウントをキャッシュする
 * 全ての投稿のキャッシュを取得すると停止する
 * 
 * 全てのSNSのカウントを取得するには1ページでも1～2秒かかる
 * そのため5つ連続して取得すると10秒程度はかかる
 * キャッシュの高速化というよりも、ランキングページ等のためのプリロードなので
 * 1度に多くのキャッシュを取得することよりも小刻みに少量ずつ取得する
 * 反映速度よりもサーバへの負荷を少なくすることを大切にする
*/
define( 'SBS_ID_FILE', dirname(__FILE__) . "/ids.csv" );
define( 'SBS_POS_FILE', dirname(__FILE__) . "/ids.pos" );
define( 'SBS_GET_LIMIT', 5 );




class SBS_Cron {




	public function __construct() {

		// SBS_Cronクラス側でコンストクトに登録してもアクティベートでは有効にできなかった
		// cronのアクションで使うカスタムの「実行間隔」を登録
		add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

		// cronのアクションを登録
		add_action( 'sbs_preload_cron', array( $this, 'preload_cron' ) );

	}




	/**
	 * cron用の5分で実行されるイベントを登録
	 *
	 */
	public function add_cron_interval( $schedules ) {
	    $schedules['5minutes'] = array( // ここのKeyがwp_schedule_eventで指定する実行感覚の添字となる
	            'interval'  => 300, // テスト用で60秒
	            'display'   => __( 'Every 5 Minutes' )
	    );
	    return $schedules;
	}




	/**
	 * cronで実行する関数を定義
	 * 
	 * @global	object		wpdb
	 * 
	 * IDを記録したCSVを取得
	 * posファイルを読み込み、CSVをposファイルの番号で切り取る
	 * 切り取ったCSVファイルに記載されたIDから、$get_limitの数だけカウントを取得
	 * 既に対応するページIDのキャッシュがある場合飛ばして次のIDへ
	 * 
	 * posファイルを$get_limit + 飛ばした分インクリメント
	 * 
	 * posファイルの値がCSVの最後の項目まできたらcronを停止する
	 */
	public function preload_cron() {

		global $wpdb;

		require_once dirname(__FILE__) . '/../sbs-social-count-cache.php';
		$SBS_SCC = new SBS_SocialCountCache();

		$data = file_get_contents( SBS_ID_FILE );
		$id_arr = explode( ",", $data );
		$id_all_count = count( $id_arr );

		$pos_num = file_get_contents( SBS_POS_FILE );
		$id_list = array_slice( $id_arr, $pos_num );
		$pos_count = $pos_num;

		foreach ( $id_list as $postid ) {
			$table_name = $wpdb->prefix . "socal_count_cache";
			$query = "SELECT postid FROM {$table_name} WHERE postid = {$postid}";
			$result = $wpdb->get_row( $query );
			$pos_count++; // posファイル用のカウントはキャッシュがあっても無くても進める

			// 当該IDのキャッシュがない場合
			if( ! isset( $result ) ){
				$url = get_permalink( $postid );
				$SBS_SCC->add_cache( $postid, $url, 'all' );
				$count++;
			}

			// キャッシュの取得数がリミットと等しくなったらcronを1度終了する
			if( $count == SBS_GET_LIMIT ){
				break;
			}
		}

		file_put_contents( SBS_POS_FILE, $pos_count );

		// 公開の投稿数と、posファイルのカウントが一致したらcronを解除する
		if ( $id_all_count == $pos_count ){
			// プリロード終了時はthisが使えないのか関数は実行できないので直接記述した
			wp_clear_scheduled_hook( 'sbs_preload_cron' );
		}
	}




	/**
	 * cronを開始、ids.csvファイル、ids.posファイルを作成
	 * インストール時に1度だけ実行
	 * 
	 * @global	object		wpdb
	 * 
	 * ids.csvはpostsテーブルのステータスが公開のIDを取得してCSV形式で書き出す
	 * optionに保存したいが、get_option()で取得可能なのが400KBらしいので却下
	 * 10000postのIDで45KB。5万ポストくらいまでは余裕でOKか…
	 * （オブジェクトキャッシュも1MB以下の規定あり）
	 * 
	 * ids.posは投稿をどこまで取得したかを記録するポジションファイル
	 */
	public function start_cron() {

		global $wpdb;

		// 公開中かつパスワードで保護されていない投稿に限定
		$table_name_posts = $wpdb->prefix . "posts";
		$query = "SELECT ID FROM {$table_name_posts} WHERE post_status = 'publish' AND post_password = ''";
		$result = $wpdb->get_results( $query );

		$result_arr = json_decode( json_encode( $result ), true ); // オブジェクトを連想配列に変換

		foreach( $result_arr as $posts ) {
			foreach( $posts as $id ) {
				$ids[] .= $id;
			}	
		}
		$ids = implode(",", $ids);

		file_put_contents( SBS_ID_FILE, $ids );
		file_put_contents( SBS_POS_FILE, 0 );

		// 引数は最初に実行する時間、実行間隔、実行する処理のフック名
		// register_activation_hookで呼び出すとコーデックスにある!wp_next_scheduledをかませると上手くいかないので注意）
		if ( ! wp_next_scheduled( 'sbs_preload_cron' ) ) {
			wp_schedule_event( time(), '5minutes', 'sbs_preload_cron' );
		}
	}




	/**
	 * cronを停止する
	 * アンインストール時に実行
	 * （プリロード終了時はthisが使えないのか関数は実行できないので直接記述した）
	 */
	public function stop_cron() {
		wp_clear_scheduled_hook( 'sbs_preload_cron' );
	}
} // SBS_Cron



