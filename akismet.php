<?php

/*
 * Plugin Name: Akismet 反垃圾评论
 * Version: 3.4
 * Description: 基于Akismet的智能反垃圾评论插件
 * ForEmlog: 5.0.1 - 5.1.2
 * Author: vibbow
 * Author Email: vibbow@gmail.com
 * Author URL: http://vsean.net/
 */
!defined('EMLOG_ROOT') && exit('access deined!');

// 后台侧边栏挂载
function akismet_sidebar() {
	echo '<div class="sidebarsubmenu" id="akismet"><a href="./plugin.php?plugin=akismet">反垃圾评论</a></div>';
}

addAction('adm_sidebar_ext', 'akismet_sidebar');

//更新标志挂载
function akismet_update_icon() {
	if (!extension_loaded('curl'))
		return;

	if (akismet_update() != FALSE) {
		echo <<<EOF
<script>document.getElementById('akismet').childNodes[0].setAttribute('style', 'background: url(../content/plugins/akismet/update.png) no-repeat 83px 0px;"');</script>
EOF;
	}
}

addAction('adm_footer', 'akismet_update_icon');

// 在评论存入数据库前的操作
function akismet_main_before() {
	// 如果是登陆用户发表的评论则不判断
	if (ISLOGIN === TRUE)
		return;

	// 被屏蔽IP检查
	$block_ip = akismet_get_config('blockip');

	if (!empty($block_ip)) {
		$user_ip = '|' . akismet_userip() . '|';
		$block_list = akismet_get_config('blocklist');

		if (strstr($block_list, $user_ip) !== FALSE) {
			// 通过屏蔽IP拦截的评论数统计更新
			$block_count = akismet_get_config('blockcount');
			akismet_set_config('blockcount', ++$block_count);
			akismet_msg('IP已被屏蔽，请勿发表垃圾评论');
		}
	}

	akismet_update_cache();
}

addAction('comment_post', 'akismet_main_before');

// 在评论存入数据库后的操作
function akismet_main_after($cid) {
	// 如果curl扩展不存在，则退出
	if (!extension_loaded('curl'))
		return;

	// 如果是登陆用户发表的评论则不判断
	if (ISLOGIN === TRUE)
		return;

	$comment_array = akismet_get_comment($cid);
	$apikey = akismet_get_config('apikey');

	// 评论被Aksimet判断为垃圾评论后的操作
	if ($apikey && akismet_check_akismet($apikey, $comment_array)) {
		// 被Akismet判断为垃圾评论数统计更新
		$spam_count = akismet_get_config('spamcount');
		akismet_set_config('spamcount', ++$spam_count);

		$block_ip = akismet_get_config('blockip');
		if (!empty($block_ip)) {
			$user_ip = '|' . akismet_userip() . '|';
			$spam_list = akismet_get_config('spamlist');

			// 如果一个IP发表的评论被Aksimet判断为垃圾评论三次以上，则添加到屏蔽IP列表
			if (substr_count($spam_list, $user_ip) > 3) {
				$block_list = akismet_get_config('blocklist');
				$block_list .= $user_ip;
				akismet_set_config('blocklist', $block_list);

				$spam_list = str_replace($user_ip, '', $spam_list);
				akismet_set_config('spamlist', $spam_list);
				// 否则添加到Spam list临时列表里
			} else {
				$spam_list .= $user_ip;

				//如果Spam list列表过长，则截断较旧的那部分
				if (strlen($spam_list) > 6000) {
					$spam_list = substr($spam_list, -2000);
					$spam_list = explode('||', $spam_list, 2); //在按字符数截断的时候，有IP可能会被从中间截断。于是用||分离IP列表（第一个IP和剩下的IP），只保留剩下的IP
					$spam_list = '|' . $spam_list[1];
				}

				akismet_set_config('spamlist', $spam_list);
			}
		}

		// 根据设置隐藏或者删除评论
		$del_comment = akismet_get_config('delcomment');
		if ($del_comment)
			akismet_del_comment($cid);
		else
			akismet_hide_comment($cid);
		return;
	}

	// 检查评论内容里是否有网址前缀
	$check_url = akismet_get_config('checkurl');
	if ($check_url && akismet_check_url($comment_array['comment'])) {
		// 被通过网址前缀匹配隐藏的评论数统计更新
		$url_count = akismet_get_config('urlcount');
		akismet_set_config('urlcount', ++$url_count);
		akismet_hide_comment($cid);
	}
}

// 为了与Sendmail插件进行兼容，优先运行反垃圾评论插件
addAction('comment_saved', 'akismet_main_after');
global $emHooks;
array_multisort($emHooks['comment_saved']);

// Akismet检查主函数
function akismet_check_akismet($apikey, $comment_array) {
	$url = "http://{$apikey}.rest.akismet.com/1.1/comment-check";
	$post = array(
		'blog' => urlencode(BLOG_URL),
		'user_ip' => urlencode(akismet_userip()),
		'user_agent' => urlencode($_SERVER['HTTP_USER_AGENT']),
		'referrer' => urlencode($_SERVER['HTTP_REFERER']),
		'comment_type' => urlencode('comment'),
		'comment_author' => urlencode($comment_array['poster']),
		'comment_author_email' => urlencode($comment_array['mail']),
		'comment_author_url' => urlencode($comment_array['url']),
		'comment_content' => urlencode($comment_array['comment'])
	);
	$result = akismet_remote($url, $post);
	return $result == 'true';
}

// 检查评论是否包含网址前缀
function akismet_check_url($comment) {
	$keywords = array('<a href=', '[url=', 'http://');
	foreach ($keywords as $keyword) {
		if (strpos($comment, $keyword) !== FALSE)
			return TRUE;
	}
	return FALSE;
}

// 通过cid获取评论内容
function akismet_get_comment($cid) {
	$db = MySql::getInstance();
	$sql = "SELECT * FROM " . DB_PREFIX . "comment where cid = " . $cid;
	$result = $db->query($sql);
	$comment_array = $db->fetch_array($result);
	return $comment_array;
}

// 隐藏评论操作函数
function akismet_hide_comment($cid) {
	$comment_model = new Comment_Model;
	$comment_model->hideComment($cid);
	$cache_model = Cache::getInstance();
	$cache_model->updateCache(array('sta', 'comment'));
	akismet_msg('评论需要审核，请勿发表垃圾评论');
}

// 删除评论操作函数
function akismet_del_comment($cid) {
	$comment_model = new Comment_Model;
	$comment_model->delComment($cid);
	$cache_model = Cache::getInstance();
	$cache_model->updateCache(array('sta', 'comment'));
	akismet_msg('评论发表失败，请勿发表垃圾评论');
}

// 更新检测函数
function akismet_update() {
	$lastUpdate = akismet_get_config('lastupdate');
	$lastVersion = akismet_get_config('lastversion');

	if (empty($lastUpdate) OR (time() - $lastUpdate) > 86400) {
		$url = 'http://vsean.net/update/check.php?plugin=akismet&version=3.4&source=' . urlencode(BLOG_URL);
		$lastVersion = akismet_remote($url);
		akismet_set_config('lastupdate', time());
		if (!empty($lastVersion))
			akismet_set_config('lastversion', $lastVersion);

		akismet_update_cache();
	}

	if ($lastVersion > 3.4)
		return $lastVersion;
	else
		return FALSE;
}

function akismet_userip() {
	$use_cdn = akismet_get_config('usecdn');
	$ip = $_SERVER['REMOTE_ADDR'];

	if ($use_cdn) {
		$header_type = array(
			'CLIENT_IP',
			'FORWARDED',
			'FORWARDED_FOR',
			'FORWARDED_FOR_IP',
			'HTTP_CF_CONNECTING_IP',
			'HTTP_CLIENT_IP',
			'HTTP_FORWARDED',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED_FOR_IP',
			'HTTP_PROXY_CONNECTION',
			'HTTP_VIA',
			'HTTP_X_FORWARDED',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'VIA',
			'X_FORWARDED',
			'X_FORWARDED_FOR'
		);

		foreach ($header_type as $header) {
			if (isset($_SERVER[$header])) {
				$ip_proxy = $_SERVER[$header];

				if (strpos($ip_proxy, ',') !== FALSE) {
					$ips = explode(',', $ip_proxy);
					$ip_proxy = trim(end($ips));
				}

				if (ip2long($ip_proxy) != FALSE) {
					$ip = $ip_proxy;
					break;
				}
			}
		}
	}

	return $ip;
}

// 远程内容获取函数
function akismet_remote($url, $post = FALSE) {
	if (!extension_loaded('curl'))
		return '';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Emlog Antispam Plugin/3.4');
	if ($post != FALSE) {
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
	}

	$result = curl_exec($ch);
	curl_close($ch);
	return $result;
}

// 提示消息显示函数
function akismet_msg($message) {
	akismet_update_cache();
	if (isset($_GET['gid']))
		mMsg($message, './');
	else
		emMsg($message);
}

// 设置读取函数
function akismet_get_config($name) {
	$name = strtolower("akismet_{$name}");
	$result = Option::get($name);

	if ($result == 'TRUE')
		return TRUE;
	else if ($result == 'FALSE')
		return FALSE;
	else
		return $result;
}

// 设置保存函数
function akismet_set_config($name, $value) {
	$name = strtolower("akismet_{$name}");
	Option::updateOption($name, $value);
}

// 更新缓存
function akismet_update_cache() {
	$cache_model = Cache::getInstance();
	$cache_model->updateCache('options');
}

// 获取Emlog版本号(int类型)
function akismet_emlog_version() {
	$emlog_dot_version = Option::EMLOG_VERSION;
	$emlog_int_version = str_replace('.', '0', $emlog_dot_version);
	return (int) $emlog_int_version;
}

?>
