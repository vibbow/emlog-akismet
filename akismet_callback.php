<?php

/*
 * Akismet 插件初始化模块
 * Author: vibbow
 * Date: 2013/06/30
 */
!defined('EMLOG_ROOT') && exit('access deined!');

function callback_init() {
	$cache = Cache::getInstance();
	$db = MySql::getInstance();

	$setting = array(
		/* 设置保存键值 */
		'blocklist' => '',
		'spamlist' => '',
		'apikey' => '',
		/* 设置开关键值 */
		'blockip' => 'FALSE',
		'checkurl' => 'TRUE',
		'delcomment' => 'FALSE',
		'usecdn' => 'FALSE',
		/* 统计键值 */
		'blockcount' => '0',
		'spamcount' => '0',
		'urlcount' => '0',
		/* 更新用键值 */
		'lastupdate' => '0',
		'lastversion' => '3.4'
	);

	foreach ($setting as $key => $value) {
		$sql = "INSERT INTO " . DB_PREFIX . "options VALUES (NULL, 'akismet_{$key}', '{$value}')";
		$db->query($sql);
	}

	$cache->updateCache("options");
}

//function callback_rm()
//{
//    $cache = Cache::getInstance();
//    $db = MySql::getInstance();
//
//	$setting = array('blocklist', 'spamlist', 'apikey', 'blockip', 'checkurl', 'delcomment', 'usecdn', 'spamcount', 'blockcount', 'urlcount', 'lastupdate', 'lastversion');
//
//	foreach ($setting as $value)
//	{
//		$sql = "DELETE FROM ".DB_PREFIX."options WHERE option_name = 'akismet_{$value}'";
//		$db->query($sql);
//	}
//
//    $cache->updateCache("options");
//}
?>