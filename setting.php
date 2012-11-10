<?php
// Access Tokenの設定
$consumer_key = "";
$consumer_secret = "";
$access_token = "";
$access_token_secret = "";

// ユーザー名 (@以降) を指定。
$screen_name = "";

// フッタの設定
$footer = "";


// ==== 高度な設定 ====

// ロック形式の指定。
// flockを指定した場合flock()を使用して排他処理を行います。
// fileを指定した場合ロックファイルを作成して排他処理を行います。
// noneを指定した場合排他処理を行いません。
//   [値: (flock|file|none)]
$locktype = 'flock';
