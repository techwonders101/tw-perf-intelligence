<?php
// Run with php tests/frontend-only-test.php
 define('ABSPATH', __DIR__);
$admin=true;$test=false;$hooks=[];$unloaded=[];
function is_admin(){global $admin;return $admin;}
function get_option($key,$default=null){global $test;return $key==='twperf_test_mode'?$test:$default;}
function current_user_can($cap){return true;}
function add_action($hook,$callback,$priority=10){global $hooks;$hooks[]=$hook;}
function add_filter($hook,$callback,$priority=10,$args=1){add_action($hook,$callback,$priority);}
function is_admin_bar_showing(){return false;}
function wp_dequeue_script($h){global $unloaded;$unloaded[]=$h;}
function wp_deregister_script($h){}
function wp_dequeue_style($h){global $unloaded;$unloaded[]=$h;}
function wp_deregister_style($h){}
class TW_Perf_Rules{static function get_for_current_page($context='frontend',$preview=false){return ['unload_js'=>['example-script'],'unload_css'=>['example-style'],'defer'=>['example-script'],'async_css'=>['example-style']];}}
require dirname(__DIR__).'/includes/class-asset-optimizer.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$o=new TW_Perf_Asset_Optimizer();$o->init();check(!$hooks,'Dashboard registered optimizer hooks');
$o->apply_unloads();check(!$unloaded,'Dashboard unloaded assets');
$tag='<script src="example.js"></script>';
check($o->apply_script_attrs($tag,'example-script','example.js')===$tag,'Dashboard script rewritten');
$css='<link rel="stylesheet" href="example.css">';
check($o->apply_async_css($css,'example-style','example.css','all')===$css,'Dashboard CSS rewritten');
ob_start();$o->inject_delay_loader();check(ob_get_clean()==='','Dashboard delay loader emitted');
$wp_scripts=(object)['queue'=>['example-script'],'to_do'=>[],'done'=>[],'registered'=>['example-script'=>(object)['deps'=>[],'extra'=>[]]]];
$admin=false;$test=true;$o=new TW_Perf_Asset_Optimizer();$o->init();check(!$hooks,'Test mode affected visitors');
$_COOKIE['twperf_preview']='1';$o->init();check(in_array('wp_enqueue_scripts',$hooks,true),'Admin frontend preview stopped working');
check(!in_array('admin_enqueue_scripts',$hooks,true),'Dashboard unload hook retained');
$o->apply_unloads();check($unloaded===['example-script','example-style'],'Frontend unload stopped working');
check(str_contains($o->apply_script_attrs($tag,'example-script','example.js'),' defer '),'Frontend defer stopped working');
echo "PASS: dashboard untouched, guest test mode protected, administrator frontend preview and unload/defer retained\n";
