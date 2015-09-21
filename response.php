<?php
/* Part of the codes are modified from https://github.com/jonsuh/jQuery-Ajax-Call-to-PHP-Script-with-JSON-Return */

/* 安全性，檢查是否為 AJAX、是否為 POST 方法，並檢查 $action 是否正確*/
if (is_ajax()) {
  if (isset($_POST["action"]) && !empty($_POST["action"])) { //Checks if action value exists
    $action = $_POST["action"];
    switch($action) { //Switch case for value of action
      case "action_check_ptt": mainJob(); break;
    }
  }
}

//Function to check if the request is an AJAX request
function is_ajax() {
  return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}


/*
  Return the HTML content of the URL, return false if 404.
  e.g. https://www.ptt.cc/bbs/not_exist_board/not_exist_page.html is not valid
*/
function get_url_content($url){
  $handle = curl_init($url);
  curl_setopt($handle,  CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($handle, CURLINFO_HEADER_OUT, true);

  /* Get the HTML or whatever is linked in $url. */
  $response = curl_exec($handle);

  /* Check for 404 (file not found).
     https://stackoverflow.com/questions/408405/easy-way-to-test-a-url-for-404-in-php
     https://stackoverflow.com/questions/10227879/php-curl-http-code-return-0
     https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
  */
  $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
  if($httpCode == 404) {
      /* Handle 404 here. */
      return "false";
  }

  /*switch($httpCode) {
      case 404:
        curl_close($handle);
        return false;
  }*/

  curl_close($handle);
  /* Handle $response here. */
  return $response;
}


function get_next_article_metadata($content, $start_from){
  /*
    我們要的資訊(作者、看板、標題、時間)在的：
      作者</span><span class="article-meta-value">uu26793 (Neural Damping)</span></div><div class="article-metaline-right"><span class="article-meta-tag">
      看板</span><span class="article-meta-value">C_Chat</span></div><div class="article-metaline"><span class="article-meta-tag">
      標題</span><span class="article-meta-value">Re: [閒聊] 台北市資訊局你....</span></div><div class="article-metaline"><span class="article-meta-tag">
      時間</span><span class="article-meta-value">Wed Aug 12 10:43:59 2015</span>
  */
  $posValueStart = strpos($content, "article-meta-value", $start_from) + 20;  // add 20 to reach the "value"
  $posValueEnd = strpos($content, "</span>", $posValueStart);
  // http://www.w3schools.com/php/func_string_strpos.asp
  // http://www.w3schools.com/php/func_string_substr.asp
  return substr($content, $posValueStart, ($posValueEnd - $posValueStart) );
}


function mainJob(){
  $url = $_POST["check_ptt_url"];

  /*
    如果沒有 404 的話，$content 的內容會是「整個 HTML」
    e.g.
    <html>
      <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width">
        <meta name="robots" content="all" />
        ...
  */
  $content = get_url_content($url);
  if($content=="false"){
    // TODO echo alert when 404
    echo json_encode(array('error' => 'An error occurred when parsing the url. (404 page not found)'));
    return;
  }

  // 抓出 PTT 文章的作者、看板、標題、時間
  $posMetaDataStart = strpos($content, "main-content");
  $content = substr($content, $posMetaDataStart);
  $article_author = get_next_article_metadata($content, 0);
  $article_board = get_next_article_metadata($content, strpos($content, $article_author));
  $article_title = get_next_article_metadata($content, strpos($content, $article_board));
  $article_date = get_next_article_metadata($content, strpos($content, $article_title));

  // 抓出最後一篇推文
  $postLastPushStart = strrpos($content, "push-content") + 16;
  $postLastPushEnd = strrpos($content, "</span><span", $postLastPushStart);
  $article_last_push = substr($content, $postLastPushStart, ($postLastPushEnd-$postLastPushStart) );

  // 建立回傳的 JSON 物件
  $return = $_POST;
  $return["article_author"] = $article_author;
  $return["article_board"] = $article_board;
  $return["article_title"] = $article_title;
  $return["article_date"] = $article_date;
  $return["article_last_push"] = $article_last_push;

  $return["add_date"] = $article_last_push;

  $return["message"] = save_to_database($return);

  // 回傳 AJAX
  echo json_encode($return);

  // TODO: 存到 Database 裡面

}


function save_to_database($data){
  $db_configs_json = file_get_contents("db_config.json");
  $db_configs      = json_decode($db_configs_json, true);
  $servername      = $db_configs['servername'];
  $dbname          = $db_configs['dbname'];
  $username        = $db_configs['username'];
  $password        = $db_configs['password'];

  $debug_message = "";
  try {
    // Open a Connection to MySQL
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    // set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES 'utf8';");
    $debug_message = "Connected successfully";

    $sql =
    "INSERT INTO main_table (url, title, author, board, published_date, period, email, last_check, remaining, last_push)
      VALUES ('" .
        $data['check_ptt_url'] ."', '" .
        $data['article_title'] . "',  '" .
        $data['article_author'] . "',  '" .
        $data['article_board'] . "',  '" .
        $data['article_date'] . "',  '" .
        // $data[''] . "',  '" .
        $data['check_period'] . "',  '" .
        $data['email'] . "',  '" .
        $data['None'] . "',  '" .
        $data['check_persist_days'] . "',  '" .
        $data['article_last_push'] . "')";

    // use exec() because no results are returned
    $conn->exec($sql);
    $debug_message = "New record created successfully";

    }
  catch(PDOException $e)
    {
    $debug_message = "Connection failed: " . $e->getMessage();
    }

  // Close the Connection
  $conn = null;

  return $debug_message;
}

?>
