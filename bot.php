<?php
require_once("TureBotter.php");
$tb = new TureBotter();

// =============================
// botの動作をここに書いてください
// 先頭に//または#がある行はコメント扱いになり実行されません。
// 実行したい行の頭の//を削除してください。
// =============================
//$tb->autoFollow();
//$tb->post("あいうえお");
//$tb->postRandom("data.txt");
//$tb->reply(2,"data.txt","reply_pattern.php");
//$tb->replyTimeline(2,"reply_pattern.php");


/*
// ==============================
// ここから下は解説になります。
// cronなどでこのbot.phpを実行することになると思いますが、動作の指定の仕方はこんな感じです。

// 用意したデータをランダムにポストしたい
$tb->postRandom("データを書き込んだファイル名");

// @付きで話しかけられたときに反応を返したい
$tb->reply(0, "データを書き込んだファイル名", "パターン反応を書き込んだファイル名 (PHPファイルじゃないと怒ります)");
// 0はEasyBotterとの互換用で無視されます。

// タイムライン (@付きを除く) の単語に反応してリプライしたい
$tb->replyTimeline(0, "パターン反応を書き込んだファイル名 (PHPファイルじゃないと怒ります");
// 0はEasyBotterとの互換用で無視されます。

// 自動でフォロー返ししたい
$tb->autoFollow();

// 固定文をツイートしたい
$tb->post("ツイートの内容");

// ==============================
// 実行するたびに毎回実行するのではなく、
// 実行する頻度を変えたい場合は以下のとおりです。
// なお、PHPのdate()については以下のURLを参照ください。
// http://php.net/manual/ja/function.date.php
// ==============================

// bot.phpを実行したときに毎回実行する
$tb->postRandom("data.txt");

// bot.phpを実行したときに、5回に1回ランダムに実行する
if(rand(0, 4) == 0){
	$tb->postRandom("data.txt");
}

// bot.phpを実行したときに、0分、15分、30分、45分だったら実行される
if(date("i") % 15 == 0){
	$tb->postRandom("data.txt");
}

// bot.phpを実行したときに、午前だったらgozen.txtのデータを、午後だったらgogo.txtのデータを使う
if(date("G") < 12){
	$tb->postRandom("gozen.txt");
}else{
	$tb->postRandom("gogo.txt");
}

// bot.phpを実行したときに、7月7日のみtanabata.txtのデータを、それ以外はdata.txtのデータを使う
if(date("n") == 2 && date("j") == 14){
	$tb->postRandom("tanabata.txt");
}else{
	$tb->postRandom("data.txt");
}

// 0時0分になったら「よるほー」と呟く
if(date("n") == 0 && date("j") == 0){
	$tb->post("よるほー");
}

*/
