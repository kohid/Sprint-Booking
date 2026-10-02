<?php
// Test harness: renders the real booking template with tiny WordPress stand-ins, so the real CSS/JS can be exercised in a browser.
define('ABSPATH', '/x/');
function __($s){return $s;} function esc_html__($s){return htmlspecialchars($s);} function esc_attr__($s){return htmlspecialchars($s);}
function esc_html_e($s){echo htmlspecialchars($s);} function esc_attr_e($s){echo htmlspecialchars($s);}
function esc_html($s){return htmlspecialchars($s);} function esc_attr($s){return htmlspecialchars($s);}
function checked($a,$b){return $a===$b?'checked':'';}
function esc_url($s){return htmlspecialchars($s);}
function selected($a,$b){return $a===$b?'selected':'';}
$GLOBALS['as_user'] = ( $argv[1] ?? '' ) === 'user';
function is_user_logged_in(){return $GLOBALS['as_user'];}
function wp_get_current_user(){return (object)['display_name'=>'Sam Customer'];}
function wp_logout_url($r=''){return '/logout';}
function get_permalink(){return '/book/';}
function home_url($p=''){return '/'.$p;}
function wp_unique_id($p=''){return $p.'1';}
function get_bloginfo($k){return 'Inverness Taxis';}
$root = dirname( __DIR__, 2 ) . '/';
$services = ['airport'=>['label'=>'Airport Transfer'],'corporate'=>['label'=>'Corporate Service'],'golf'=>['label'=>'Golf Transfer'],'wedding'=>['label'=>'Wedding Cars'],'minibus'=>['label'=>'Minibus Service'],'tours'=>['label'=>'Inverness Tours']];
$default='airport'; $max_vias=5;
ob_start(); include $root.'templates/booking-form.php'; $html = ob_get_clean();
$cfg = [
 'rest'=>'http://localhost:8123/wp-json/sprint-booking/v1/','symbol'=>'£','maxVias'=>5,'freeLuggage'=>2,'luggageFee'=>150,'minLeadText'=>'1 hour',
 'minPickup'=>date('Y-m-d\TH:i', strtotime('+1 hour')),'defaultService'=>'airport',
 'services'=>[
  'airport'=>['label'=>'Airport Transfer','quoteOnly'=>false,'minibusOnly'=>false],'corporate'=>['label'=>'Corporate Service','quoteOnly'=>false,'minibusOnly'=>false],
  'golf'=>['label'=>'Golf Transfer','quoteOnly'=>false,'minibusOnly'=>false],'wedding'=>['label'=>'Wedding Cars','quoteOnly'=>true,'minibusOnly'=>false],
  'minibus'=>['label'=>'Minibus Service','quoteOnly'=>false,'minibusOnly'=>true],'tours'=>['label'=>'Inverness Tours','quoteOnly'=>true,'minibusOnly'=>false]],
 'accounts'=>true,'user'=>$GLOBALS['as_user'] ? ['name'=>'Sam Customer','email'=>'sam@example.com','phone'=>'07700 900555'] : null,'nonce'=>$GLOBALS['as_user'] ? 'abc123' : '',
 'vehicles'=>[
  'saloon'=>['label'=>'Saloon','capacity'=>4,'bags'=>2,'minibus'=>false,'type'=>'saloon','image'=>''],
  'estate'=>['label'=>'Estate','capacity'=>4,'bags'=>3,'minibus'=>false,'type'=>'estate','image'=>'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="60"><rect width="160" height="60" fill="#8aa"/><text x="80" y="35" text-anchor="middle" font-size="14" fill="#fff">PHOTO</text></svg>')],
  'mpv'=>['label'=>'MPV','capacity'=>6,'bags'=>4,'minibus'=>false,'type'=>'mpv','image'=>''],
  'minibus8'=>['label'=>'Minibus (8 seats)','capacity'=>8,'bags'=>8,'minibus'=>true,'type'=>'minibus','image'=>''],
  'minibus16'=>['label'=>'Minibus (16 seats)','capacity'=>16,'bags'=>16,'minibus'=>true,'type'=>'minibus','image'=>'']],
 'center'=>[57.4778,-4.2247],'zoom'=>12,'tiles'=>['url'=>'http://tiles.test/{z}/{x}/{y}.png','attribution'=>'&copy; OpenStreetMap contributors'],'imagePath'=>'leaflet/images/'];
echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Booking harness</title>',
 '<link rel="stylesheet" href="leaflet/leaflet.css"><link rel="stylesheet" href="flatpickr/flatpickr.min.css"><link rel="stylesheet" href="booking-form.css"><style>body{margin:0;padding:24px 16px;font-family:system-ui,sans-serif;background:#fff}</style></head><body>',
 $html, '<script src="leaflet/leaflet.js"></script><script src="flatpickr/flatpickr.min.js"></script><script>window.SB_CONFIG=', json_encode($cfg), ';</script><script src="booking-form.js"></script></body></html>';
