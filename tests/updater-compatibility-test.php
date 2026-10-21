<?php
define('ABSPATH',__DIR__);define('HOUR_IN_SECONDS',3600);
$main=file_get_contents(dirname(__DIR__).'/tw-performance.php');
foreach(['TWPERF_VERSION','TWPERF_TESTED_WP'] as $name){preg_match("/define\\('".$name."',\\s*'([^']+)'\\)/",$main,$m);define($name,$m[1]);}
define('TWPERF_BASENAME','tw-performance/tw-performance.php');
function get_transient($key){return (object)['tag_name'=>'v99.0.0','body'=>'Fixture release','assets'=>[(object)['name'=>'tw-performance.zip','browser_download_url'=>'https://example.test/plugin.zip']]];}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES);}
require dirname(__DIR__).'/includes/class-updater.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$o=new TW_Perf_Updater();$info=$o->plugin_info(null,'plugin_information',(object)['slug'=>'tw-performance']);
$update=$o->check_for_update((object)['checked'=>[TWPERF_BASENAME=>TWPERF_VERSION]]);
check($info->tested==='7.1.3','Plugin details compatibility incorrect');
check($update->response[TWPERF_BASENAME]->tested===$info->tested,'Update compatibility differs');
check(version_compare('7.1.3',$info->tested,'<='),'Core would display warning on tested version');
check(!version_compare('7.2',$info->tested,'<='),'Untested newer version falsely advertised');
check(str_contains(file_get_contents(dirname(__DIR__).'/readme.txt'),"Tested up to: {$info->tested}\n"),'Readme differs');
echo "PASS: plugin details, update metadata and readme agree; no warning on 7.1.3; future-version warning retained\n";
