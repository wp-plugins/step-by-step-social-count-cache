=== Step by Step Social Count Cache ===
Contributors: oxynotes
Donate link: https://wordpress.org/plugins/step-by-step-social-count-cache/
Tags: cache, count, sns, social
Requires at least: 4.2.4
Tested up to: 4.3
Stable tag: 1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

SNSのカウントを3段階に分けてキャッシュするプラグインです。

== Description ==

Step by Step Social Count CacheはSNSのカウントをキャッシュするプラグインです。

投稿の最終更新日から「**1日**」「**1日～1週間**」「**1週間以降**」の3つの段階で、キャッシュの有効期限を設定することができます。

カウントを取得できるSNSは**twitter**、**Facebook**、**Google+**、**はてなブックマーク**、**Pocket**、**feedly**の6つです。

デフォルトの有効期限は「**1日以内**」の場合は**30分**。
「**1日～7日以内**」の場合は**1日**。
「**7日以降**」の場合は**1週間**となっています。
それぞれの有効期限はオプションページで変更が可能です。

詳しい使い方や解説は[作者の解説ページ](http://oxynotes.com/?p=9200)をご覧ください。

= カウントを表示する方法 =

**投稿のキャッシュを全て取得して書き出す方法（こちらがおすすめ）**

`<?php
	$socal_count = sbs_get_all();
	echo $socal_count["twitter"];
	echo $socal_count["facebook"];
	echo $socal_count["google"];
	echo $socal_count["hatena"];
	echo $socal_count["pocket"];
	echo $socal_count["feedly"];
?>`

**もしくは個別に取得して書き出す方法**

`<?php
	echo sbs_get_twitter();
	echo sbs_get_facebook();
	echo sbs_get_google();
	echo sbs_get_hatena();
	echo sbs_get_pocket();
	echo sbs_get_feedly();
?>`

= カウントの多い投稿のIDを取得する方法 =

SNSのカウントが多い順に投稿を表示する際に利用してください。

<?php
	sbs_get_pp_all( $page, $post_type );
	sbs_get_pp_twitter( $page, $post_type );
	sbs_get_pp_facebook( $page, $post_type );
	sbs_get_pp_google( $page, $post_type );
	sbs_get_pp_hatena( $page, $post_type );
	sbs_get_pp_pocket( $page, $post_type );
?>

Facebookのいいねを取得する際に、Facebook API 2.4を利用するためApp Tokenの入力が必要です。

feedlyでカウントするフィードはRSS2です。カスタムのFeedを使用したい場合は設定画面で指定することができます。

設定画面でキャッシュのプリロードが可能です。


== Installation ==

1. プラグインの新規追加ボタンをクリックして、検索窓に「SBS Social Count Cache」と入力して「今すぐインストール」をクリックします。
1. もしくはこのページのzipファイルをダウンロードして解凍したフォルダを`/wp-content/plugins/`ディレクトリに保存します。
1. 設定画面のプラグインで **SBS Social Count Cache** を有効にしてください。

== Frequently asked questions ==

-

== Screenshots ==

1. Option page.

== Changelog ==

1.2
カスタムのRSSを入力できるように変更。プリロード機能を追加。カウントの多い順に投稿のIDを取得できるように変更。

1.1.1
APCモジュールが無効になっている場合のバグを修正。

1.1
feedlyでカウントするフィードを選択可能に変更。APCもしくはAPCuが有効な場合、クエリをメモリ上に展開。他、バグ修正。

1.0
初めのバージョン。


== Upgrade notice ==

-