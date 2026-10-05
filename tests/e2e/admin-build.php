<?php
// Test harness: renders the Settings panels for Payments, the connection check and Demo with tiny WordPress stand-ins.
define('ABSPATH','/x/'); define('SB_VERSION','test'); define('SB_DIR','/x/'); define('SB_URL','/'); define('MINUTE_IN_SECONDS',60);
function __($s){return $s;} function esc_html__($s){return htmlspecialchars($s);} function esc_html($s){return htmlspecialchars((string)$s);} function esc_attr($s){return htmlspecialchars((string)$s);} function esc_url($s){return htmlspecialchars((string)$s);} function esc_url_raw($s){return (string)$s;} function esc_textarea($s){return htmlspecialchars((string)$s);}
function checked($a,$b=true,$e=true){return $a==$b?" checked='checked'":'';} function selected($a,$b=true,$e=true){return $a==$b?" selected='selected'":'';}
function admin_url($p=''){return '/wp-admin/'.$p;} function rest_url($p=''){return 'https://inverness.example/wp-json/'.ltrim($p,'/');} function wp_nonce_field(){echo '<input type="hidden" name="_wpnonce" value="x">';}
function get_option($k,$d=false){return $GLOBALS['opts'][$k]??$d;} function get_transient($k){return $GLOBALS['trans'][$k]??false;} function delete_transient($k){} function get_current_user_id(){return 1;} function wp_date($f,$t){return date($f,$t);}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);} function wp_strip_all_tags($s){return strip_tags($s);} function get_bloginfo($k){return 'Inverness Taxis';}
function add_action(){} function wp_json_encode($v,$f=0){return json_encode($v,$f);}
$GLOBALS['opts']=['sb_settings'=>['payments'=>['allow_driver'=>true,'currency'=>'GBP','stripe'=>['enabled'=>true,'sandbox'=>true,'test_secret'=>'sk_test_51Habcdef1234','live_secret'=>'','test_whsec'=>'whsec_abcdef9876','live_whsec'=>''],'paypal'=>['enabled'=>true,'sandbox'=>false,'sandbox_id'=>'','sandbox_secret'=>'','live_id'=>'','live_secret'=>'']]],
 'sb_pay_last_error'=>['time'=>time()-3600,'gateway'=>'PayPal','message'=>'Client Authentication failed']];
$GLOBALS['trans']=['sb_paytest_1'=>['ok'=>true,'gateway'=>'stripe','message'=>'Stripe accepted the key (sandbox mode).']];
spl_autoload_register(function($c){ $f=__DIR__.'/../../includes/'.str_replace('SprintBooking\\','',$c).'.php'; if(is_readable($f)) require $f; });
use SprintBooking\Settings; use SprintBooking\Admin;
$c = Settings::get(); $name = Settings::OPTION;
$row = function ( string $id, string $label, string $control, string $help = '' ): void { printf('<div class="sb-ui-row"><label for="%1$s">%2$s</label><div>%3$s%4$s</div></div>', esc_attr($id), esc_html($label), $control, $help ? '<p class="sb-ui-help">' . esc_html($help) . '</p>' : ''); };
$open = function ( string $id, string $title, string $sub = '' ): void { printf('<section class="sb-ui-panel" data-sb-panel="%1$s" id="sb-panel-%1$s"><div class="sb-ui-panel__head"><h2>%2$s</h2>%3$s</div><div class="sb-ui-panel__body">', esc_attr($id), esc_html($title), $sub ? '<p>' . esc_html($sub) . '</p>' : ''); };
$close = function (): void { echo '</div></section>'; };
$call = function ( string $m, ...$a ) { $r = new ReflectionMethod( Admin::class, $m ); $r->setAccessible( true ); return $r->invoke( null, ...$a ); };
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="dashboard.css"><style>body{margin:0;background:#f5f8fa;font-family:system-ui,sans-serif;font-size:14px}</style></head><body><div class="sb-ui"><form>';
$call( 'payments_form_panel', $c, $name, $row, $open, $close );
echo '</form>';
$call( 'payments_status_panel', $open, $close );
$call( 'demo_panel', $c, $open, $close );
echo '</div></body></html>';
