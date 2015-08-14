=== Step by Step Social Count Cache ===
Contributors: oxynotes
Donate link: https://wordpress.org/plugins/step-by-step-social-count-cache/
Tags: cache, count, sns, social
Requires at least: 4.2.4
Tested up to: 4.2.4
Stable tag: 1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

SNSのカウントを3段階に分けてキャッシュするプラグインです。

== Description ==

Step by Step Social Count CacheはSNSのカウントをキャッシュするプラグインです。
投稿の最終更新日から「1日」「1日～1週間」「1週間以降」の3つの段階で、キャッシュの有効期限を設定することができます。

Facebookのいいねを取得する際APIのバージョン2.4を利用するためオプションページでApp Tokenの入力が必要です。

カウントを取得できるSNSはtwitter、Facebook、Google+、はてなブックマーク、Pocket、feedlyの6つです。

デフォルトの有効期限は「1日以内」の場合は30分。
「1日～7日以内」の場合は1日。
「7日以降」の場合は1週間となっています。
それぞれの有効期限はオプションページで変更が可能です。

使い方はUsageにある通り、sbs_get_all()というタグを表示したい投稿のループ内に記述します。カウントは配列になっているので、必要なSNSの添字を加えて出力してください。

もしくはsbs_get_twitter()など、個別のカウントを取得するタグも用意しています。

= 使い方 =

1. 管理画面から「設定 ＞ SBS Social Count Cache」を選択します。
1. FacebookのApp Token、カウントをキャッシュするSNS、SNSのカウントをキャッシュする期間をそれぞれ設定してください。
1. テンプレートファイルのループ内で以下のように記述してください。

**投稿のキャッシュを全て取得して書き出す方法**

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

== Installation ==

1. プラグインの新規追加ボタンをクリックして、検索窓に「SBS Social Count Cache」と入力して「今すぐインストール」をクリックします。
1. もしくはダウンロードして解凍したフォルダを`/wp-content/plugins/`ディレクトリに保存します。
1. 設定画面のプラグインで **SBS Social Count Cache** を有効にしてください。
1. 管理画面から「設定 ＞ SBS Social Count Cache」を選択します。
1. FacebookのApp Token、カウントをキャッシュするSNS、SNSのカウントをキャッシュする期間をそれぞれ設定してください。

== Frequently asked questions ==

-

== Screenshots ==

1. Option page.

== Changelog ==

1.0
初めのバージョン。

== Upgrade notice ==

-