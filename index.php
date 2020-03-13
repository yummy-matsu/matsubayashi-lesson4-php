<?php
session_start();
require 'dbconnect.php';

//loginでmemberからセッションに代入したIDとTIMEを引きついで、
//1時間後にログアウトさせる
if (isset($_SESSION['id']) && $_SESSION['time'] + 3600 > time()) {
    //（なにもアクションがなかったら）現在の時刻から～というログアウトの条件を指定する
    $_SESSION['time'] = time();
    $members = $db->prepare(
        'SELECT * 
        FROM members 
        WHERE id=?'
    );
    //ログインが成功していれば、DBからメンバーIDを取得
    $members->execute(array($_SESSION['id']));
    //ログインしているユーザーの情報を引き出す
    $member = $members->fetch();
    //ログインしていない場合にログイン画面に移行させる＝（クッキー保存していないブラウザでログインしようとしてもはいれない）
} else {
    header('Location: login.php');
    exit();
}

  //投稿をDBに投稿する＝投稿するボタンが押されたとき（空投稿はNG）
if (!empty($_POST)) {
    if ($_POST['message'] !== '') {
        //どのリプに対するメッセージなのかreply_message_id=?を追加してDBに保存させる
        $message = $db->prepare(
            'INSERT INTO posts 
            SET member_id=?
            , message=?
            ,reply_message_id=?
            , created=NOW()'
        );
        $message->execute(
            array(
             //member['id'] = session['id']は同じ
             //※member['id']の方がデータベースから情報をとってくるため確実な情報。member['id']で記載
             $member['id'],
             $_POST['message'],
             //hiddenで渡しているname = reply_post_id'
             $_POST['reply_post_id'],
            )
        );
                    //再読み込みのメッセージ重複を避ける
                    //メッセージを入れたあとに、index.phpの素の状態に戻る処理
                    header('Location: index.php');
                    exit();
    }
}

$page = $_REQUEST['page'];
//1:-1ページなど0より小さい値を入力されることを防ぐ
if ($page == '') {
    $page = 1;
}
//2:$pageと1を比べて、大きい方を$pageにいれるのでページ数は1以下にならない
$page = max($page, 1);

//最終ページを設定する
$counts = $db->query(
    'SELECT 
    COUNT(*) AS cnt 
    FROM posts'
);
$cnt = $counts->fetch();
$maxPage = ceil($cnt['cnt'] / 5);
//maxpage以上の数は指定できない※minが2つのパラメーターを持つ場合、その中で最も小さいものを返す
$page = min($page, $maxPage);

//5の倍数ずつ表示させる
$start = ($page -1) * 5;

//投稿済みのメッセージをすべて表示させる。
$posts = $db->prepare(
    'SELECT 
    m.name
    , m.picture
    , p.*
    ,(SELECT count(l.like_posts_id) 
    FROM liked l
    WHERE l.like_posts_id = p.id)
    AS likecnt,
    (SELECT count(r.retweet_posts_id)
    FROM retweet r
    WHERE r.retweet_posts_id = p.id)
    AS retweetcnt
    FROM members m
    , posts p
    WHERE m.id=p.member_id
    AND p.deleteflag = 0
    GROUP BY p.id
    ORDER BY p.id DESC LIMIT ?
    , 5'
);
//数字として入れる必要があるので、戻り値を返すexecuteは使わない
$posts->bindParam(1, $start, PDO::PARAM_INT);
$posts->execute();

//reのリンクがクリックされた場合の処理指定されたIDが存在しているか確認する
if (isset($_REQUEST['res'])) {
    $response = $db->prepare(
        'SELECT 
        m.name
        , m.picture
        , p.*
        FROM members m
        ,posts p
        WHERE m.id=p.member_id 
        AND p.id=?'
    );
        $response->execute(
            array(
            $_REQUEST['res']
              )
        );
    $table = $response->fetch();
    $message = '@' . $table['name'] . ' ' .$table['message'];
}

//いいね機能つける いいねボタンを押したときのpost[id]（投稿）を受けっとて、liketableの中に存在するか調べる
//$memberの中にはログイン時に使用したmember['id']が入っているので利用する
//likeのPOST＝いいね押した投稿 && ログインユーザー＝いいねした人という条件でデータの中を検索する
//$pushCountに条件にあった、DBデータを代入して、取り出せるようにする
//$pushCountがfalseでかえってきた場合、新規いいね、の動作だったということ
if (isset($_REQUEST['like'])) {
    $like = $db->prepare(
        'SELECT 
        COUNT(*) AS cnt 
        FROM liked 
        WHERE like_posts_id=? 
        AND like_user=?'
    );
    //連番方式でプレースホルダ（?のこと）にバインドする
    //※エスケープ処理してくれる（''）シングルクォートの妨害操作（SQLインジェクション）を取り除く
    //executeで直接プレースホルダに値をいれることができる（この場合、bindValueが省略可能）※ただしPDO::PARAM_STR扱いになる
    $like->execute(
        array(
          $_REQUEST['like'],
          $member['id'],
        )
    );
    //executeによってDBから抽出された該当するデータを1件のみ配列として返す
    //該当がない場合はFALSE 取得したデータを変数$pushに入れる
    //fetchはデフォルトがPDO::FETCH_BOTH（意味：フィールド名と 0 から始まる添字を付けた配列を返す）
    $pushCount = $like->fetch();

    //if条件で$pushCountの戻り件数を調べる
    //$pushCountの結果が0件→ 新規いいねだった場合、liketableにデータを登録する
    //$pushCountの検索結果が1件→ liketableから削除する
    if ($pushCount['cnt'] < 1) {
        $newPush = $db->prepare(
            'INSERT 
            INTO liked 
            SET like_posts_id=?
            , like_user=?
            , created=NOW()'
        );
        $newPush->execute(
            array(
            $_REQUEST['like'],
            $member['id'],
            )
        );

    } else {
        //いいねが解除されたら、DBから削除する
        $reset = $db->prepare(
            'DELETE 
            FROM liked 
            WHERE like_posts_id=? 
            AND like_user=?'
        );
            $reset->execute(
                array(
                  $_REQUEST['like'],
                  $member['id'],
                  )
            );
    }
        //URLパラメータが?like~~~のままでは再読み込みした際に、またいいね押されてしまうので、通常画面に戻る
           header('Location: index.php');
           exit();
}

//$likeUserに代入して、ログインしている人がつけたいいねの投稿を表示させる
$likeUser = $db-> prepare(
    'SELECT like_posts_id
    FROM liked 
    WHERE like_user=?'
);
$likeUser->execute(
      array(
      $_SESSION['id']
      )
);
//likeUser_postに結果をいれる
//cntでカウントされる SELECTでデータを引っ張ると初投稿の古いlike_posts_idをもってくる
//いいねした投稿のデータをすべて表示させるために、繰り返し構文をする
//$likeUser_postに入っている値は1つのidに対して「like_posts_id」と「like_user」が入っている
//多次元配列（2次元配列）
while ($likeUserPost = $likeUser->fetch()) {
      //多次元配列で上記の結果を受けとる。※多次元配列を受け取る場合、
      //[](配列)を用意しないとwhileで繰り返している間に上書き保存されてしまう
    $likeUserPostAll[] = $likeUserPost;
}

//リツイートボタンをおしたら、postがすでに存在するか確認し、２重投稿を防ぐ
if (isset($_REQUEST['retweet'])) {
    $retweet = $db->prepare(
    'SELECT 
    COUNT(*) AS cnt 
    FROM retweet 
    WHERE retweet_posts_id=?
    AND retweet_user=?'
    );
        $retweet->execute(
            array(
            $_REQUEST['retweet'],
            $member['id'],
            )
        );
          $pushRetweet = $retweet->fetch();

          $db->beginTransaction();
    try{
        //上記でまだリツイートされていなかったら、retweetテーブルにデータを保存
        if ($pushRetweet['cnt'] < 1) {
            $newRetweet = $db->prepare(
                'INSERT INTO retweet
                SET retweet_posts_id=?
                , retweet_user=?
                , created=NOW()'
            );
            $newRetweet->execute(
                array(
                   $_REQUEST['retweet'],
                   $member['id'],
                )
            );

              // 登録したデータのIDを取得して出力 PDOで最後に登録したデータのIDを取得する
              //   $lastId = $db->lastInsertId();
                  //その後、POSTテーブルにもリツーイトした投稿を保存
            $newRetweet = $db->prepare(
                'INSERT INTO posts(
                message
                , member_id
                , reply_message_id
                , retweet_posts
                , deleteflag
                , created
                , modified
                )
               SELECT message
               , member_id
               , reply_message_id
               , (SELECT 
               r.id 
               FROM retweet r
               LEFT OUTER JOIN posts p
               ON r.retweet_posts_id = p.id
               WHERE p.id =?
               AND r.retweet_user =?
               )
               , deleteflag
               , created AS created
               , modified
               FROM posts 
               WHERE id=? '
            );
                $newRetweet->execute(
                    array(
                          $_REQUEST['retweet'],
                          $member['id'],
                          $_REQUEST['retweet'],
                    )
                );

    } else {
            //リツイートが解除されたらPOSTテーブルから削除
            $resetRetweet = $db->prepare(
                'DELETE p
                FROM posts AS p
                LEFT OUTER JOIN retweet r
                ON r.id = p.retweet_posts
                WHERE r.retweet_posts_id=?'
            );
            $resetRetweet->execute(
                array(
                $_REQUEST['retweet'],
                )
            );
             //リツイートが解除されたらretweetテーブルから削除
            $resetRetweet = $db->prepare(
                'DELETE 
                FROM retweet 
                WHERE retweet_posts_id=? 
                AND retweet_user=?'
            );
            $resetRetweet->execute(
                array(
                $_REQUEST['retweet'],
                $member['id'],
                )
            );
    }
        //コミット
              $db->commit();

  } catch (Exception $e) {
            //エラーが起きたらロールバック
            $db->rollback();
            throw $e;
  }
            header('Location:index.php');
            exit();
}

//ログインしている人がつけたリツイートの投稿を表示させる
//元ツイートには「〇〇さんがリツーイトしています」を表示させない
$retweetUser = $db->prepare(
    'SELECT 
    r.id
    , r.retweet_posts_id
    FROM retweet r
    WHERE retweet_user=?
    GROUP BY r.id'
);
$retweetUser->execute(
    array(
    $_SESSION['id']
    )
);
while ($retweetUserPost = $retweetUser->fetch()) {
    $retweetUserPostAll[] = $retweetUserPost;
}

// TODO上記の$postsでリツーイトした人を表示させる
// やりたいことは下記の$retweetNameをforeachで繰り返し表示させて、$posts一覧のように
//リツイートした人を表示させたかったのですが、条件を$post['retweet_posts'] > 0)にするとnullになる。
// 
if ($post['retweet_posts'] >= 0) {
    $retweetName = $db->prepare(
    'SELECT r.id ,m.name 
    FROM retweet r,members m 
    WHERE m.id = r.retweet_user'
    );
    $retweetName->execute();
    while ($retweetNamePost = $retweetName->fetch()) {
        $retweetNameAll[] = $retweetNamePost;
    }
}
foreach ($retweetNameAll as $key => $value) {
    echo $value['name'];
    //出力結果：ねこねこねこねこはむこはむこ
    //一人ずつ取り出せていない
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>ひとこと掲示板</title>

  <link rel="stylesheet" href="style.css" />
  <link href="https://use.fontawesome.com/releases/v5.6.1/css/all.css"
  rel="stylesheet">
</head>

<body>
<div id="wrap">
  <div id="head">
    <h1>ひとこと掲示板</h1>
  </div>
  <div id="content">
    <div style="text-align: right"><a href="logout.php">ログアウト</a></div>
    <form action="" method="post">
      <dl>
        <dt><?php print(htmlspecialchars($member['name'], ENT_QUOTES)); ?>
        さん、メッセージをどうぞ</dt>
        <dd>
          <textarea name="message" cols="50" rows="5"><?php
            print(htmlspecialchars($message, ENT_QUOTES)); ?></textarea>
          <!-- どのメッセージへの返信かわからないのでhiddnでフォームに渡す
        inputはPOSTに自動で入れられる -->
          <input type="hidden" name="reply_post_id"
          value="<?php print(htmlspecialchars($_REQUEST['res'], ENT_QUOTES)); ?>"/>
        </dd>
      </dl>
      <div>
        <p>
          <input type="submit" value="投稿する" />
        </p>
      </div>
    </form>

<!-- 繰り返し、配列の処理を精査しながら繰り返し表示させる -->
<?php foreach ($posts as $post): ?>
    <div class="msg">
    <img src="member_picture/<?php print(htmlspecialchars(
        $post['picture'], ENT_QUOTES
    )); ?>" width="48" height="48" alt="" />

     <p><?php print(htmlspecialchars($post['message'], ENT_QUOTES)); ?>
    <span class="name">（<?php print(htmlspecialchars($post['name'], ENT_QUOTES)); ?>）

      <!-- re機能は誰のメッセージIDに対してres=?パラメータをつけたのか表示させる -->
  </span>[<a href="index.php?res=<?php
    print(htmlspecialchars($post['id'], ENT_QUOTES)); ?>">Re</a>]</p>
  <p class="day"><a href="view.php?id=<?php
    print(htmlspecialchars($post['id'])); ?>">
        <?php print(htmlspecialchars($post['created'], ENT_QUOTES)); ?></a>

        <?php if ($post['reply_message_id'] > 0) : ?>
    <a href="view.php?id=<?php
    print(htmlspecialchars($post['reply_message_id'], ENT_QUOTES)); ?>">
返信元のメッセージ</a>
        <?php endif; ?>

      <!-- リツーイトされた元の投稿にはメッセージを表示させない
      p.retweet_postsが0以上だったら、リツーイトしたメッセージなので、リツーイト注釈を表示させる -->
      <!-- TODO上記の340で取り出した$value['name']を投稿一覧のように表示させたい-->
      <?php if ($post['retweet_posts'] > 0 ) : ?>
    <span class="retweeted"><?php
    print(htmlspecialchars($value['name'], ENT_QUOTES)); ?>さんがリツイートしました</span>
      <?php endif; ?>

<!-- いいね機能追加 -->
<!-- ログインしている人のいいねすべてを$likeUser_post_allに入れているので、
foreachで繰り返し表示させる条件：POSTid＝like_posts_idだったら-->
        <?php
        $pushCount = 0;
        if(!empty($likeUserPostAll)) {
              //多次元配列を取り出すので1行目の配列を$likeUser_post_one（配列）で取り出す
            foreach($likeUserPostAll as $likeUserPostOne){
                //$likeUser_post_one（配列）からさらに$likeUser_post_として2つ目の配列をとりだす
                foreach ($likeUserPostOne as $likeUserPostId) {
                        //$likeUser_post_idの中からいいねした投稿のidを取り出しイコールだったら
                        //$pushCountに1を代入して、ハートの切り替えしをさせる
                        //foreachで繰り返しているので、$pushCountで入った1を0に戻す
                        //対象がなくなるまで繰り返す
                    if ($likeUserPostId == $post['id']) {
                            $pushCount = 1;
                    }
                }
            }
        }
        ?>
        <?php if($pushCount < 1 ) : ?>
  <a href="index.php?like=<?php print(htmlspecialchars($post['id']))?>">
  <i class="far fa-heart like-btn-unlike"></i></a>
<?php else: ?>
  <a href="index.php?like=<?php print(htmlspecialchars($post['id']))?>">
    <i class="fas fa-heart like-btn-like"></i></a>
    <!--いいねされた数を表示させる  -->
<?php endif; ?>
    <span><?php print(htmlspecialchars($post['likecnt'])); ?></span>

<!--リツイート機能
    もしリツイートされたら、リツイートアイコンを表示させる-->
        <?php
        $pushRetweet = 0;
        if(!empty($retweetUserPostAll)) {
            foreach($retweetUserPostAll as $retweetUserPostOne) {
                foreach($retweetUserPostOne as $retweetUserPostId) {
                    if ($retweetUserPostId == $post['id']) {
                            $pushRetweet = 1;
                    }
                }
            }
        }
        ?>

    <?php if($pushRetweet < 1) : ?>
<a href="index.php?retweet=<?php print(htmlspecialchars($post['id']))?>">
<i class="fas fa-redo retweet"></i></a>
<?php else: ?>
<a href="index.php?retweet=<?php print(htmlspecialchars($post['id']))?>">
<i class="fas fa-redo-alt retweeted"></i></a>
<?php endif ; ?>
<!--リツイートされた数を表示させる  -->
<span><?php print(htmlspecialchars($post['retweetcnt'])); ?></span>

    <!-- 自分の投稿だけ削除する 今ログインしている人のIDが$postのメンバーIDと一致していたら
    同じ人であると判断 リツイートした投稿は削除機能を消す-->
        <?php if ($_SESSION['id'] == $post['member_id'] && $post['retweet_posts'] < 1) : ?>
[<a href="delete.php?id=<?php print(htmlspecialchars($post['id'])); ?>"
style="color: #F33;">削除</a>]
        <?php endif; ?>
    </p>
    </div>
<?php endforeach ; ?>

<ul class="paging">
<?php if ($page > 1) : ?>
<li><a href="index.php?page=<?php print(htmlspecialchars($page - 1)); ?>">
前のページへ</a></li>
<?php else: ?>
  <li>前のページへ</li>
<?php endif; ?>

<?php if ($page < $maxPage) : ?>
<li><a href="index.php?page=<?php print(htmlspecialchars($page + 1)); ?>">
次のページへ</a></li>
<?php else:?>
  <li>次のページへ</li>
<?php endif;?>

</ul>
  </div>
</div>
</body>
</html>