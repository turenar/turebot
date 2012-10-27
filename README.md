turebot
=======

Forked from [EasyBotter][] v2.05.

EasyBotterと互換性はほとんどありません。下の互換性の項をご覧ください。

EasyBotterとできるだけ動作が同じになるようにしてはいるものの、上級者向きです。

[EasyBotter]: http://pha22.net/twitterbot/ "プログラミングができなくても作れるTwitter botの作り方"


Requirements
------------

* PHP&gt;=5.0 (json enabled) / **Propose: &gt;=5.2**


EasyBotterとの互換性
--------------------

EasyBotterをextendsして機能を追加するような拡張プログラムは動きませんし、
関数の返り値を使用する拡張プログラムも動かないでしょう。

* 期待通り動く関数

  * \#autoFollow()

  * \#postRandom($dataFile)

* 仕様が変わってる関数

  * \#reply($interval, $dataFile, $dataPatternFile)

     * $intervalは無視されます

  * \#replyTimeline($interval, $dataPatternFile)

     * $intervalは無視されます

* 実装してない関数

  * \#postRotation($dataFile)

     * 別に\#postRandom($dataFile)使えばいいと思うので... 気が向いたら実装

* 追加されている関数

  * \#post($statusText)

     * $statusText: 投稿するテキスト


利用法
------

bot.phpを参照 (EasyBotter由来のコードたち)。
