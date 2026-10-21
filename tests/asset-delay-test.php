<?php
define('ABSPATH',__DIR__);
define('TWPERF_DIR',dirname(__DIR__).'/');
define('TWPERF_URL','https://example.test/plugin/');define('TWPERF_VERSION','1.0.11');
$enqueued=[];$localized=[];
function wp_enqueue_script(...$args){global $enqueued;$enqueued[]=$args;}
function wp_localize_script(...$args){global $localized;$localized[]=$args;}
function is_admin(){return false;}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
function get_option($key,$default=null){return $default;}
function wp_json_encode($v){return json_encode($v);}
function current_user_can($v){return true;}
class TW_Perf_Rules{static function get_for_current_page($c='frontend',$p=false){global $rules;return $rules;}}
require dirname(__DIR__).'/includes/class-asset-optimizer.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function fixture($ruleset,$scripts){global $rules,$wp_scripts;$rules=$ruleset;$wp_scripts=(object)['registered'=>[],'queue'=>array_keys($scripts),'to_do'=>[],'done'=>[]];foreach($scripts as $h=>$d)$wp_scripts->registered[$h]=(object)['deps'=>$d,'extra'=>[]];return new TW_Perf_Asset_Optimizer();}
$tag='<script type="module" src="/a.js" nonce="sample" integrity="sha256-test" crossorigin="anonymous" data-label="é"></script>';
$o=fixture(['delay'=>['a','b']],['a'=>[],'b'=>['a']]);
$out=$o->apply_script_attrs($tag,'a','/a.js');check(str_contains($out,'data-twperf-delay'),'Safe dependency chain not delayed');preg_match('/data-twperf-tag="([^"]+)"/',$out,$m);check(base64_decode($m[1])===$tag,'Original attributes changed');
$o=fixture(['delay'=>['a']],['a'=>[],'b'=>['a']]);check($o->apply_script_attrs($tag,'a','/a.js')===$tag,'Immediate consumer lost dependency');
$o=fixture(['delay'=>['a','b','c']],['a'=>[],'b'=>['a'],'c'=>['b'],'immediate'=>['c']]);check($o->apply_script_attrs($tag,'a','/a.js')===$tag,'Transitive consumer lost dependency');
$o=fixture(['delay'=>['a','b']],['a'=>[],'b'=>['a']]);$wp_scripts->registered['b']->extra['after']=['initialize();'];check($o->apply_script_attrs($tag,'a','/a.js')===$tag,'Inline dependent initializer not protected');
$o=fixture(['delay'=>['a']],['a'=>[]]);$wp_scripts->registered['a']->extra['data']='var config={};';check($o->apply_script_attrs($tag,'a','/a.js')===$tag,'Localization not protected');
$o=fixture(['delay'=>['a']],['a'=>[]]);$wp_scripts->registered['unused']=(object)['deps'=>['a'],'extra'=>[]];check(str_contains($o->apply_script_attrs($tag,'a','/a.js'),'data-twperf-delay'),'Unqueued registration prevented delay');
$o=fixture(['delay'=>['a']],['a'=>[]]);check($o->apply_script_attrs($tag.'<script>init()</script>','a','/a.js')===$tag.'<script>init()</script>','Combined inline tags discarded');
$o=fixture(['delay'=>['a','b']],['a'=>['b'],'b'=>['a']]);check($o->apply_script_attrs($tag,'a','/a.js')===$tag,'Cycle did not fail safely');
$o=fixture(['delay'=>['a']],['a'=>[]]);ob_start();$o->inject_delay_loader();$loader=ob_get_clean();check($loader==='' && $enqueued[0][0]==='twperf-delay-loader' && $enqueued[0][4]===true,'Loader not enqueued in footer');check($localized[0][1]==='twperfDelayConfig','Loader configuration missing');
check($o->apply_script_attrs($tag,'twperf-delay-loader','/loader.js')===$tag,'Loader was optimized');
echo "PASS: delayed tag preservation, immediate/transitive/inline dependencies, cycles and loader output\n";
