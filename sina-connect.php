<?php
/*
Plugin Name: 新浪微博登录
Author:  Praise Song
Author URI: http://labs.cross.hk/
Plugin URI: http://labs.cross.hk/html/1484.html
Description: 使用新浪微博账号登录您的 WordPress 博客，并且可以使用新浪微博的头像。发布博客时还可以同步至新浪微博并且记录到指定的日志文件中。
Version: 0.0.1
*/
$sina_consumer_key = '499635422';
$sina_consumer_secret = '07ee8787ff28f4fdd34487250a4a8c66';
$sc_loaded = false;
$post_author = '';
$protocal = 'http';

if ($_SERVER["HTTPS"] == "on"){
        $protocal .= "s";
}

add_action('init', 'sc_init');
function sc_init(){
    do_action('extra_login_del_cache');
    if (session_id() == "") {
        session_start();
    }
    if($_GET['from'] === 'sina'){
      	if(!is_user_logged_in()) {
              if(isset($_GET['code'])){
      			sc_confirm();
              }
          }
    }
}

add_action("login_form", "sina_connect",999);
add_action("register_form", "sina_connect",999);

//add_action('comment_form_must_log_in_after','comment_form_must_log_in_after');

function comment_form_must_log_in_after(){
  global $post;echo
  sina_connect('',get_permalink($post->ID));
}

function sina_connect($id='',$callback_url=null){
    include_once( dirname(__FILE__).'/config.php' );
    include_once( dirname(__FILE__).'/saetv2.ex.class.php' );

    //如果是后台页面跳转
    if(strpos($_GET['redirect_to'],'wp-admin') !== false){
      $_GET['redirect_to'] = $protocal.'://'.$_SERVER['HTTP_HOST'].'/index.php?redirect_to='.$_GET['redirect_to'];
    }

    $o = new SaeTOAuthV2( WB_AKEY , WB_SKEY );
    $callbackurl = $callback_url ? $callback_url : ($_GET['redirect_to'] ? $_GET['redirect_to'] : WB_CALLBACK_URL);

    $code_url = $o->getAuthorizeURL( strpos($callbackurl,'?') !== false? $callbackurl.'&from=sina' : $callbackurl.'?from=sina');

	global $sc_loaded;
	if($sc_loaded) {
		return;
	}
	if(is_user_logged_in() && !is_admin()){
		return;
	}
	$sc_url = WP_PLUGIN_URL.'/'.dirname(plugin_basename (__FILE__));
?>
	<p id="sc_connect" class="sc_button">
	<a href="<?php echo $code_url; ?>"><img src="<?php echo $sc_url; ?>/sina_button.png" alt="使用新浪微博登陆" style="cursor: pointer; margin:10px 20px 10px 0;" /></a>
	</p>
<?php
    $sc_loaded = true;
}

add_filter("get_avatar", "sc_get_avatar",10,4);
function sc_get_avatar($avatar, $id_or_email='',$size='32') {
	global $comment;
	if(is_object($comment)) {
		$id_or_email = $comment->user_id;
	}
	if (is_object($id_or_email)){
		$id_or_email = $id_or_email->user_id;
	}

	$current_user = wp_get_current_user();
	$scid = get_usermeta($id_or_email, 'scid') ;

    //工具条上显示的头像，必须是当前用户的头像
    //新浪用户则用scid，原博客用户直接使用Gavater
    if($size === 16 || $size === 64){
      $scid = get_usermeta($current_user->ID,'scid');
    }

	if($scid){
		$out = 'http://tp3.sinaimg.cn/'.$scid.'/50/1.jpg';
		$avatar = "<img alt='' src='{$out}' class='avatar avatar-{$size}' height='{$size}' width='{$size}' />";
		return $avatar;
	}else {
		return $avatar;
	}
}

function authweibo(){
    if(!class_exists('SaeTOAuthV2')){
        include_once( dirname(__FILE__).'/config.php' );
        include_once( dirname(__FILE__).'/saetv2.ex.class.php' );
    }

    $o = new SaeTOAuthV2( WB_AKEY , WB_SKEY );

    if (isset($_REQUEST['code'])) {
        $keys = array();
        $keys['code'] = $_REQUEST['code'];
        $keys['redirect_uri'] = $_GET['redirect_to'] ? $_GET['redirect_to'] : WB_CALLBACK_URL;
        try {
            $token = $o->getAccessToken( 'code', $keys ) ;
        } catch (OAuthException $e) {
        }
    }

    if ($token) {
      @$_SESSION['token'] = $token;
      @setcookie( 'weibojs_'.$o->client_id, http_build_query($token) );
    }else{
      echo '<script type="text/javascript">alert(\'对不起，新浪微博授权失败。\');</script>';
      return;
    }

      $c = new SaeTClientV2( WB_AKEY , WB_SKEY , $_SESSION['token']['access_token'] );
      $ms  = $c->home_timeline(); // done
      $uid_get = $c->get_uid();
      $uid = $uid_get['uid'];
      $user_message = $c->show_user_by_id( $uid);

      //新浪抛出异常
      if($user_message['error'] && $user_message['error_code']){
        echo '<script type="text/javascript">alert(\'对不起，新浪微博授权失败。授权异常码：'.$user_message['error'].'\');</script>';
        return;
      }

      return $user_message;
}

function sc_confirm(){
	$sinaInfo = authweibo();
	if(!$sinaInfo){return;}

	if((string)$sinaInfo['domain']){
		$sc_user_name = $sinaInfo['domain'];
	} else {
		$sc_user_name = $sinaInfo['id'];
	}

	sc_login($sinaInfo['id'].'|'.$sc_user_name.'|'.$sinaInfo['screen_name'].'|'.$sinaInfo['url'].'|'.$_SESSION['token']['access_token'].'|'.WB_SKEY,$sinaInfo);
}

function sc_login($Userinfo,$sinaInfo) {
	$userinfo = explode('|',$Userinfo);
	if(count($userinfo) < 6) {
		wp_die("An error occurred while trying to contact Sina Connect.");
	}

	$userdata = array(
		'user_pass' => wp_generate_password(),
		'user_login' => 'weibo_'. $userinfo[1],
		'display_name' => $userinfo[2],
		'user_url' => $userinfo[3],
		'user_email' => $userinfo[1].'@weibo.com'
	);

	if(!function_exists('wp_insert_user')){
		include_once( ABSPATH . WPINC . '/registration.php' );
	}

	//检查当前的新浪用户是否存在
	$wpuid = username_exists('weibo_'. $userinfo[1]);

	if(!$wpuid){
		if($userinfo[0]){
			$wpuid = wp_insert_user($userdata);
		
			if($wpuid){
				update_user_meta($wpuid, 'scid', $userinfo[0]);
				$sc_array = array (
					"oauth_access_token" => $userinfo[4],
					"oauth_access_token_secret" => $userinfo[5],
				);
				update_user_meta($wpuid, 'scdata', $sc_array);
			}
		}
	} else {
		update_user_meta($wpuid, 'scid', $userinfo[0]);
		$sc_array = array (
			"oauth_access_token" => $userinfo[4],
			"oauth_access_token_secret" => $userinfo[5],
		);
		update_user_meta($wpuid, 'scdata', $sc_array);
	}

	if($wpuid) {print_r($wpuid);
		wp_set_auth_cookie($wpuid, true, false);
		wp_set_current_user($wpuid);

		do_action('wp_login', $user_login);
        if (function_exists('get_admin_url')) {
            wp_redirect(get_admin_url());
        } else {
            wp_redirect(get_bloginfo('wpurl') . '/wp-admin');
        }
	}
}

if(!function_exists('connect_login_form_login')){
	add_action("login_form_login", "connect_login_form_login");
	add_action("login_form_register", "connect_login_form_login");
	function connect_login_form_login(){
		if(is_user_logged_in()){
			$redirect_to = admin_url('profile.php');
			wp_safe_redirect($redirect_to);
		}
	}
}

add_action('admin_menu', 'sc_options_add_page');

function sc_options_add_page() {
	add_options_page('同步到新浪微博', '同步到新浪微博', 'read', 'sc_options', 'sc_options_do_page');
}

add_option('sina_weibo_log','');
function show_weibo_setting(){
  $value = get_option('sina_weibo_log');
  echo "<div class=\"wrap\"><h2>设置新浪微博发送日志</h2>";
  echo '<form action="options-general.php?page=sc_options" method="post">'.
        '<input type="hidden" name="updated_sina_weibo_log" value="true" />'.
        '<table class="form-table">'.
          '<tr><th>日志地址</th><td><input type="text" name="sina_weibo_log" value="'.$value.'" />(请勿必确保日志文件可写)</td></tr>'.
        '</table>';
  submit_button();
  echo "</form></div>";
}
if($_POST['updated_sina_weibo_log']){
  echo "<div class=\"wrap\"><div class=\"updated\" style=\"padding:10px;\">新浪微博发送日志更新成功。</div></div>";
  update_option('sina_weibo_log',$_POST['sina_weibo_log']);
}

function sc_options_do_page() {
    $sina_id = get_user_meta(get_current_user_id(),'scid',true);

    if( current_user_can('update_core')){
      show_weibo_setting();
      return;
    }

    if(!$sina_id){
      echo "<div class=\"wrap\"><div class=\"error\" style=\"padding:10px;\">当前登录的用户不是新浪微博用户。</div></div>";
      return;
    }

    global $current_user;
    get_currentuserinfo();

    if($_GET['delete']) {
      update_user_meta(get_current_user_id(),'sync_to_weibo',0);
    }
	?>
	<div class="wrap">
		<h2>同步到新浪微博</h2>
		<form method="post" action="options.php">
            <?php
            $sync_to_weibo = get_user_meta(get_current_user_id(),'sync_to_weibo',true);
			if($_GET['delete']){
                if(!$sync_to_weibo){

                  echo '<div class="wrap"><div class="updated" style="padding:10px;">'.$current_user->display_name.'，您已成功取消与新浪微博数据同步。</div></div>';
                }
				 echo '<p><a href="'.menu_page_url('sc_options',false).'">重新绑定或者绑定其他帐号？</a></p>';
			}else if($_GET['code'] || $sync_to_weibo){
				if($sync_to_weibo || $weibo_user = authweibo()){
				    $status = update_user_meta(get_current_user_id(),'sync_to_weibo',1);
				    if($sync_to_weibo || $status){
				      echo '<div class="wrap"><div class="updated" style="padding:10px;">'.$current_user->display_name.'，您已成功设置与新浪微博数据同步。<p>当你的博客更新的时候，会同时更新到新浪微博。</p></div></div>';
				    }
					echo '<p>新浪微博帐号 <a href="http://weibo.com/'.get_user_meta(get_current_user_id(),'scid',true).'" target="_blank">'.$current_user->display_name.'</a> 。<a href="'.menu_page_url('sc_options',false).'&delete=1">取消绑定或者绑定其他帐号？</a></p>';
				}
			}else{
                echo '<p>点击下面的图标，将你的新浪微博客帐号和你的博客绑定，当你的博客更新的时候，会同时更新到新浪微博。</p>';
                sina_connect('',menu_page_url('sc_options',false));
            }
			?>
	</div>
	<?php
}

function update_sina_t($token,$status=null){
    include_once( dirname(__FILE__).'/config.php' );
    $status = urlencode($status);
    @$response = wp_remote_post("https://api.weibo.com/2/statuses/update.json"."?source=".WB_AKEY."&access_token={$token[0]['oauth_access_token']}&status=$status",array('sslverify'=>false));
	$response = (array)$response;

	if(is_wp_error($response)){
	  exit('send sina weibo error.');
	}
	if((int)$response['response']['code'] === 200){
	  writing_weibo_status($status);
	}
}

function writing_weibo_status($status){
  global $post_author;
  $userinfo = get_userdata($post_author);
  $file = get_option('sina_weibo_log');
  if(!$file){return;}

  if(!is_writable($file)){
    chmod($file,0777);
  }

  $fp = fopen($file,'ab');
  fwrite($fp,"微博内容：".urldecode($status)."\n发送时间：".date( "Y-m-d   H:i:m ")."\n新浪用户：".$userinfo->display_name."\n\n\n\n\n");
  fclose($fp);
}

function upload_sina_t($token,$status,$pic){
	if(!$pic) return;
    $access_token = $token[0]['oauth_access_token'];
    include_once( dirname(__FILE__).'/config.php' );
    $status = urlencode($status);

	$response = wp_remote_post("https://api.weibo.com/2/statuses/upload_url_text.json"."?source=".WB_AKEY."&access_token={$token[0]['oauth_access_token']}&status=$status&url=$pic",array('sslverify'=>false));
    if((int)$response['response']['code'] === 200){
      writing_weibo_status($status);
    }
}


add_action('publish_post', 'publish_post_2_sina_t', 0);
function publish_post_2_sina_t($post_ID){
    global $post_author;
    $post_data = get_post($post_ID,'ARRAY_A');
    $post_author = $post_data['post_author'];

    if(!$post_author){return;}

    //如果是新浪用户
    if($scid = get_user_meta($post_author,'scid',true)){
      add_post_meta($post_ID, 'from', $scid, true);
    }else{
      return;
    }

	$sync_to_weibo = get_user_meta($post_author,'sync_to_weibo',true);
	if(!$sync_to_weibo) return;


	$c_post = get_post($post_ID);
	$token = get_user_meta($post_author,'scdata');
	
	$post_title = $c_post->post_title;
	$post_content = strip_tags($c_post->post_content,'<img>');

	$title_len = mb_strlen($post_title,'UTF-8');
	$content_len = mb_strlen($post_content,'UTF-8');
	$rest_len = 120;

	if($title_len + $content_len> $rest_len) {
		$post_content = mb_substr($post_content,0,$rest_len-$title_len).'... ';
	}

	$status = '【'.$post_title.'】 '.$post_content.get_sina_short_url($token,get_permalink($post_ID));

	$pic = get_post_first_image($c_post->post_content);

	if($pic){
		upload_sina_t($token,$status,$pic);
	}else{
		update_sina_t($token,$status);
	}
}

if(!function_exists('get_post_first_image')){
	function get_post_first_image($post_content){
		preg_match_all('|<img.*?src=[\'"](.*?)[\'"].*?>|i', $post_content, $matches);
		if($matches){		
			return $matches[1][0];
		}else{
			return false;
		}
	}
}
if(!function_exists('get_sina_short_url')){
	function get_sina_short_url($token,$long_url){
	    include_once( dirname(__FILE__).'/config.php' );
	    @$response = wp_remote_get('https://api.weibo.com/2/short_url/shorten.json'."?source=".WB_AKEY."&access_token={$token[0]['oauth_access_token']}&url_long=".$long_url,array('sslverify'=>false));
        $response = (array)$response;

	    if(is_wp_error($response)){
	      exit('get sina short url error.');
	    }
        if(!$response['body']){
          return '';
        }

        $result = json_decode($response['body']);
        $result = $result->urls;

		return $result[0]->url_short;
	}
}


