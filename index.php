<?php
session_start();
require 'dbconnect.php';

if (isset($_SESSION['id']) && $_SESSION['time'] + 3600 > time()) {
    $_SESSION['time'] = time();
    $members = $db->prepare(
        'SELECT * 
        FROM members 
        WHERE id=?'
    );
    $members->execute(array($_SESSION['id']));
    $member = $members->fetch();
} else {
    header('Location: login.php');
    exit();
}

  //投稿をDBに投稿する
if (!empty($_POST)) {
    if ($_POST['message'] !== '') {
        $message = $db->prepare(
            'INSERT INTO posts 
            SET member_id=?
            , message=?
            ,reply_message_id=?
            , created=NOW()'
        );
        $message->execute(
            array(
             $member['id'],
             $_POST['message'],
             $_POST['reply_post_id'],
            )
        );
                    header('Location: index.php');
                    exit();
    }
}

$page = $_REQUEST['page'];
if ($page == '') {
    $page = 1;
}
$page = max($page, 1);

//投稿は5個ずつ表示させる
$counts = $db->query(
    'SELECT 
    COUNT(*) AS cnt 
    FROM posts'
);
$cnt = $counts->fetch();
$maxPage = ceil($cnt['cnt'] / 5);
$page = min($page, $maxPage);
$start = ($page -1) * 5;

//投稿一覧を表示する
$posts = $db->prepare(
    'SELECT 
    m.name
    , m.picture
    , p.*
    ,r2.retweet_user_name
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
    left outer join retweet r2
    on r2.id = p.retweet_posts
    WHERE m.id=p.member_id
    AND p.deleteflag = 0
    GROUP BY p.id
    ORDER BY p.id DESC LIMIT ?
    , 5'
);
$posts->bindParam(1, $start, PDO::PARAM_INT);
$posts->execute();

//reのリンクがクリックされた場合の処理：指定されたIDが存在しているか確認する
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

//課題：いいね機能実装
if (isset($_REQUEST['like'])) {
    $like = $db->prepare(
        'SELECT 
        COUNT(*) AS cnt 
        FROM liked 
        WHERE like_posts_id=? 
        AND like_user=?'
    );
    $like->execute(
        array(
          $_REQUEST['like'],
          $member['id'],
        )
    );
    $pushCount = $like->fetch();

    //$pushCountの戻り件数を調べる 戻り値が0件の場合は新規いいね
    //$pushCountの検索結果が1件→ 既存いいね削除
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
           header('Location: index.php');
           exit();
}

//ログインしている人がつけたいいねの投稿を表示
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
while ($likeUserPost = $likeUser->fetch()) {
    $likeUserPostAll[] = $likeUserPost;
}

//課題：リツイート機能実装
if (isset($_REQUEST['retweet'])) {
    $retweet = $db->prepare(
    'SELECT 
    COUNT(*) AS cnt 
    FROM retweet 
    WHERE retweet_posts_id=?
    AND retweet_user_name = (SELECT name FROM members WHERE id = ?)'
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
        if ($pushRetweet['cnt'] < 1) {
            //リツイートした投稿と人を管理する
            $newRetweet = $db->prepare(
                'INSERT INTO retweet
                SET retweet_posts_id=?
                , retweet_user_name = (SELECT name FROM members WHERE id = ?)
                , created=NOW()'
            );
            $newRetweet->execute(
                array(
                   $_REQUEST['retweet'],
                   $member['id'],
                )
            );
            //リツイートした投稿をpostテーブルに追加する
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
               AND r.retweet_user_name = (SELECT name FROM members WHERE id = ?)
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
                WHERE r.retweet_posts_id=?
                AND r.retweet_user_name = (SELECT name FROM members WHERE id = ?);'
            );
            $resetRetweet->execute(
                array(
                $_REQUEST['retweet'],
                $member['id'],
                )
            );
             //リツイートが解除されたらretweetテーブルから削除
            $resetRetweet = $db->prepare(
                'DELETE 
                FROM retweet 
                WHERE retweet_posts_id=? 
                AND retweet_user_name = (SELECT name FROM members WHERE id = ?)'
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
            $db->rollback();
            throw $e;
  }
            header('Location:index.php');
            exit();
}

//ログインしている人がつけたリツイートの投稿を表示させる
$retweetUser = $db->prepare(
    'SELECT 
    r.id
    , r.retweet_posts_id
    FROM retweet r
    WHERE retweet_user_name = (SELECT name FROM members WHERE id = ?)
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

<!-- 投稿一覧を表示させる -->
<?php foreach ($posts as $post): ?>
    <div class="msg">
    <img src="member_picture/<?php print(htmlspecialchars(
        $post['picture'], ENT_QUOTES
    )); ?>" width="48" height="48" alt="" />

     <p><?php print(htmlspecialchars($post['message'], ENT_QUOTES)); ?>
    <span class="name">（<?php print(htmlspecialchars($post['name'], ENT_QUOTES)); ?>）

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

      <!--p.retweet_postsが0以上だったら、リツーイトしたメッセージなので「リツーイト」を表示させる -->
      <?php if ($post['retweet_posts'] > 0 ) : ?>
    <span class="retweeted"><?php
    print(htmlspecialchars($post['retweet_user_name'], ENT_QUOTES)); ?>さんがリツイートしました</span>
      <?php endif; ?>
      
      <!-- いいね機能追加 -->
        <?php
        $pushCount = 0;
        if(!empty($likeUserPostAll)) {
            foreach($likeUserPostAll as $likeUserPostOne){
                foreach ($likeUserPostOne as $likeUserPostId) {
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

    <!-- 削除できるのは自分の投稿だけ-->
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