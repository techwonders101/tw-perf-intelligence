<?php
define('ABSPATH',__DIR__);define('WP_CONTENT_DIR',sys_get_temp_dir().'/twperf-nonexistent-cache-fixture');
$options=[];$hooks=[];$purges=0;
function add_action($h,$cb,$p=10,$args=1){global $hooks;$hooks[$h][]=$cb;}
function get_option($k,$d=null){global $options;return $options[$k]??$d;}
function update_option($k,$v){global $options;$options[$k]=$v;return true;}
function get_queried_object_id(){return 0;}
function wp_parse_url($u,$p){return parse_url($u,$p);}
function esc_url_raw($u){return $u;}
function w3tc_flush_all(){global $purges;$purges++;}
class TW_Perf_Rules{static function get_for_current_page(){return ['preload'=>['explicit']];}}
require dirname(__DIR__).'/includes/class-preload-manager.php';
require dirname(__DIR__).'/includes/class-cache-purger.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$wp_styles=(object)['registered'=>['google'=>(object)['src'=>'https://fonts.googleapis.com/css2?family=Roboto'],'explicit'=>(object)['src'=>'/explicit.css']]];$wp_scripts=(object)['registered'=>[]];
$o=new TW_Perf_Preload_Manager();$m=new ReflectionMethod($o,'collect_preloads');$items=$m->invoke($o);check(count($items)===1&&$items[0]['as']==='style','Google CSS preloaded as font or explicit preload lost');
TW_Perf_Preload_Manager::save_font_preload('https://example.test/a.ttf?ver=1',true);check($options['twperf_preloads'][0]['type']==='font/ttf','Wrong manual font MIME');
TW_Perf_Cache_Purger::init();check(isset($hooks['updated_option'],$hooks['added_option']),'Settings hooks missing');
TW_Perf_Cache_Purger::schedule_settings_purge('unrelated');check(!isset($hooks['shutdown']),'Unrelated setting purges cache');
TW_Perf_Cache_Purger::schedule_settings_purge('twperf_fix_font_display');TW_Perf_Cache_Purger::schedule_settings_purge('twperf_critical_css');check(count($hooks['shutdown'])===1,'Multiple purge callbacks');
TW_Perf_Cache_Purger::purge_changed_settings();TW_Perf_Cache_Purger::purge_changed_settings();check($purges===1,'Purge not coalesced');
echo "PASS: Google CSS preload removed, explicit preloads retained, MIME correct and settings cache purge coalesced\n";
