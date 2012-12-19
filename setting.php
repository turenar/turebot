<?php
// Access Tokenの設定
$cfg['consumer_key'] = "";
$cfg['consumer_secret'] = "";
$cfg['access_token'] = "";
$cfg['access_token_secret'] = "";

// フッタの設定。フッタの中でも{hour}などは時刻などに置き換えられます。
$cfg['footer'] = "";

// #autoFollow() において自動フォローした時に送るリプライの内容。
// NULLの場合は送らない。
$cfg['text_when_autoFollow'] = array('フォローしたよ！');
# $cfg['text_when_autoFollow'] = NULL;

// MySQLデータベースの設定
// {store:<key>:<value>}や{get:<key>:<defalut>}を使用しなければ必要ありません
$cfg['mysql_db'] = '';
$cfg['mysql_user'] = '';
$cfg['mysql_password'] = '';

// ==== 高度な設定 ====

// ロック形式の指定。
// flockを指定した場合flock()を使用して排他処理を行います。
// fileを指定した場合ロックファイルを作成して排他処理を行います。
// noneを指定した場合排他処理を行いません。
//   [値: (flock|file|none)]
$cfg['locktype'] = 'flock';

// デバッグメッセージの表示
$cfg['debug_logging'] = false;
