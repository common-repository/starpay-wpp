<?php
/**
 * Plugin Name: StarPay-WPP
 * Plugin URI: https://www.netstars.co.jp
 * Description: StarPay mpm and online payment gateway. Support China and Japan mainstream payment methods.
 * Version: 1.0.0
 * Author: NETSTARS CO., LTD
 * Author URI: https://www.netstars.co.jp
 * License: GPL2
 * License URI: https://opensource.org/licenses/GPL-2.0
 * Text Domain: starpay
 */

if (! defined ( 'ABSPATH' )){
	exit (); // Exit if accessed directly
}

//常量声明
global $wpdb;
define('C_WC_STARPAY_ID','wcstarpaygateway');
define('C_WC_STARPAY_DIR',rtrim(plugin_dir_path(__FILE__),'/'));
define('C_WC_STARPAY_URL',rtrim(plugin_dir_url(__FILE__),'/'));

//当插件加载完毕，执行后面的starpay初始化函数
add_action( 'plugins_loaded', 'wc_starpay_gateway_init' );

//建立心跳检查定单状态
add_action( 'init', 'wc_starpay_init_heartbeat' );

//建立心跳间隔
add_filter( 'heartbeat_settings', 'wc_starpay_setting_heartbeat' );

//对心跳接受到的order进行校验
add_filter('heartbeat_received', 'wc_starpay_heartbeat_received', 10, 2);
add_filter('heartbeat_nopriv_received', 'wc_starpay_heartbeat_received', 10, 2 );

//在定单管理页面进行功能处理
add_action( 'woocommerce_admin_order_data_after_billing_address', 'wc_starpay_custom_display_admin', 10, 1 );

//初始化api
add_action( 'rest_api_init', 'wc_starpay_recpayresult_route');
add_action( 'rest_api_init', 'wc_starpay_recbackresult_route');

//卸载时调用
register_uninstall_hook(__FILE__, 'wc_starpay_pluginprefix_function_to_run');

//业务功能函数
//starpay初始化函数
function wc_starpay_gateway_init() {
    //判定WC_Payment_Gateway这个类是否存在，没有的话直接返回
    if( !class_exists('WC_Payment_Gateway') ){
        return;
    } 
    require_once( plugin_basename( 'class-wc-starpay-gateway.php' ) );//加载主体功能
    require_once( plugin_basename( 'wc-starpay-utils.php' ) );      //加载工具类
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    add_filter('woocommerce_payment_gateways', 'wc_starpay_add_gateway' ); //将我们的PHP类注册为WooCommerce支付网关
    add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_starpay_plugin_edit_link' );
    wc_starpay_db_init();//数据初始化
}

//接受支付结果回调函数
function wc_starpay_recpayresult_callback() {
    try {
        $gateway = new WC_Starpay_Gateway();
        $msg = "";
        $resultBody = file_get_contents('php://input');
        $gateway->logging("Action: wc_starpay_recpayresult, setp 1, [wordpress <- starpay], Desc: json: ".$resultBody );
        $resultRecord = json_decode($resultBody);
        header('Content-Type:application/json; charset=utf-8');
        if (!$resultRecord->{'Result'} || !$resultRecord->{'OrderAmount'} || !$resultRecord->{'TradeNo'} || !$resultRecord->{'TradeState'} ){
            $msg = $gateway->GenerateNotifyRespJson("FAIL", "PARAM_ERROR");
            $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
            exit($msg);
        } else {
            //验证签名
            $strSign = "";
            $signKey = $gateway->signKey;
            $arrNotifyReq = array(
                "Result"=>$resultRecord->{'Result'}, 
                "ResultDesc"=>$resultRecord->{'ResultDesc'}, 
                "MchId"=>$resultRecord->{'MchId'},
                "Nonce"=>$resultRecord->{'Nonce'},
                "Attach"=>$resultRecord->{'Attach'},
                "BankType"=>$resultRecord->{'BankType'},
                "OrderAmount"=>$resultRecord->{'OrderAmount'},
                "TradeNo"=>$resultRecord->{'TradeNo'},
                "TradeState"=>$resultRecord->{'TradeState'},
                "TradeTime"=>$resultRecord->{'TradeTime'},
                "TradeType"=>$resultRecord->{'TradeType'}
            );
            //输出签名字符串
            ksort($arrNotifyReq);
            foreach ($arrNotifyReq as $key => $value)
            {
                $strSign = $strSign . $key . "=". $value . "&";
            }
            $strSign = $strSign . "key=". $signKey;
            if(strcmp(strtoupper(md5($strSign)),$resultRecord->{'Sign'} ) != 0) {
                $msg = $gateway->GenerateNotifyRespJson("FAIL", "SIGNFAILED");
                $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
                exit($msg);
            }
            $record = wc_starpay_db_order_query_s_by_tradeno($resultRecord->{'TradeNo'});
            if($record){
                if($record->state === "PAID") {
                    $msg = $gateway->GenerateNotifyRespJson("SUCCESS", "OK");
                    $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
                    exit($msg);
                }
            }else {
                $msg = $gateway->GenerateNotifyRespJson("FAIL", "ORDERNOTEXIST");
                $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
                exit($msg);
            }
        }
        $isCompleted = $gateway->wc_starpay_is_order_payment($record->orderid, $record->tradeno);
        if($isCompleted === 'SUCCESS'){
            wc_starpay_db_order_update($record->tradeno);
            $msg = $gateway->GenerateNotifyRespJson("SUCCESS", "OK");
            $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
            exit($msg);
        }
        $msg = $gateway->GenerateNotifyRespJson("FAIL", "NOTPAY");
        $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
        exit($msg);
    }
    catch (Exception $e) {
        $msg = $gateway->GenerateNotifyRespJson("FAIL", $e->getMessage());
        $gateway->logging("Action: wc_starpay_recpayresult, setp 2, [wordpress -> starpay], Desc: json: ".$msg );
        exit($msg);
    }
}

//接受支付完成跳转回调函数
function wc_starpay_recbackresult_callback() {
    $osta = '';
    $msg = '';
    $tradeNo = ''; 
    $gateway = new WC_Starpay_Gateway();
    $tradeNo = sanitize_text_field($_GET['tradeno']);
    $osta = sanitize_text_field($_GET['osta']);
    $msg = sanitize_text_field($_GET['msg']);
    $gateway->logging("Action: wc_starpay_recbackresult, setp 1, [wordpress <- starpay], Desc: tradeNo: ".$tradeNo." osta: ".$osta." msg: ".$msg );
    if($osta === "success" && $msg === "paysuccess" && $tradeNo !== "") {
        $record = wc_starpay_db_order_query_s_by_tradeno($tradeNo);
        if($record) {
            wc_starpay_redirect($gateway->wpUrl."/checkout-2/order-received/".$record->orderid); 
        }else {
            return "tradeno does not exist";
        }
        return "ok";
    } else {
        return "fail";
    }
}

//接受支付结果路由设定
function wc_starpay_recpayresult_route() {
    register_rest_route( 'starpay/v1', 'recpayresult', [
      'methods'   => 'POST',
      'callback'  => 'wc_starpay_recpayresult_callback'
    ] );
}

//支付完成跳转设定
function wc_starpay_recbackresult_route() {
    register_rest_route( 'starpay/v1', 'recbackresult', [
      'methods'   => 'GET',
      'callback'  => 'wc_starpay_recbackresult_callback'
    ] );
}

//增加StarPayGateway功能
function wc_starpay_add_gateway( $methods ) {
    $methods[] = 'WC_Starpay_Gateway';
    return $methods;
}

//增加StarPayGateway编辑连接
function wc_starpay_plugin_edit_link( $links ){
    return array_merge(
        array(
            'settings' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section='.C_WC_STARPAY_ID) . '">'.__( 'Settings', 'starpay' ).'</a>'
        ),
        $links
    );
}

//初始化将脚本放入队列
function wc_starpay_init_heartbeat(){
    wp_enqueue_script('heartbeat');
    wp_register_script('jqueryQrcode', plugin_dir_url(__FILE__).'js/jquery.qrcode.min.js');
    wp_enqueue_script('jqueryQrcode');
}

//设置间隔
function wc_starpay_setting_heartbeat( $settings ){
    $settings['interval'] = 2;
    return $settings;
}

//调用StarPay订单状态查询
function wc_starpay_heartbeat_received($response, $data){
    //取定单
    if(!isset($data['orderId'])){
        return;
    }
    if(!isset($data['tradeNo'])){
        return;
    }
	$gateway = new WC_Starpay_Gateway();
	//查询定单
    $isCompleted = $gateway->wc_starpay_is_order_payment($data['orderId'], $data['tradeNo']);
    //判断定单是否已完成
    if($isCompleted === 'SUCCESS'){
        $response['status'] = 'SUCCESS';
        wc_starpay_db_order_update($data['tradeNo']);
    }
    return $response;
}

//调用StarPay配置页面
function wc_starpay_custom_display_admin($order){
    //确定是否为starpay发起，如不是直接返回
    $method = get_post_meta( $order->get_id(), '_payment_method', true );
    if($method != C_WC_STARPAY_ID){
        return;
    }
    //确定payType
    $payType = get_post_meta( $order->get_id(), 'payType', true );
    $starpayOrderId = get_post_meta( $order->get_id(), 'starpayOrderId', true );
}

//加载主菜单函数
function wc_starpay_config_page_html() {

    if (!current_user_can('manage_options')) {
       return;
    }
    ?>
     <div class=wrap>
         <h1><?= esc_html(get_admin_page_title()); ?></h1>
         <h2 font-size: 30px;>Starpay is an aggregate payment product launched by Japan's Netstar company. Now we launch the ECSHOP payment plug-in for WordPress + wordcommerce which can be easily searched in the WordPress store. After one click installation, simple information configuration can be used. The plug-in now supports cross border payments of China's Alipay, WeChat payment and UnionPay. Japan has paypay, linepay, Merpay, Lotte, Dpay, Aupay, Jcoin and other payment methods. With the development of payment technology in Japan. In the future, it will continue to update and add, and the payment types of other countries will also be included.</h2>
         <h2>Operation manual:  </h2>
         <h2>Chinese:  <a href="<?php echo C_WC_STARPAY_URL ?>/files/StarPay-WPP-OperationManual-Chinese.pdf" target="_blank">Download link</a></h2>
         <h2>English:  <a href="<?php echo C_WC_STARPAY_URL ?>/files/StarPay-WPP-OperationManual-English.pdf" target="_blank">Download link</a></h2>
         <h2>Japanese:  <a href="<?php echo C_WC_STARPAY_URL ?>/files/StarPay-WPP-OperationManual-Japanese.pdf" target="_blank">Download link</a></h2>
     </div>
    <?php
}

//加载主菜单
function wc_starpay_options_page() {
    add_menu_page(
       'StarPay-WPP',
       'StarPay-WPP',
       'manage_options',
	   'starpayconfig',
	   'wc_starpay_config_page_html',
       '',
       20
    );
}

add_action('admin_menu', 'wc_starpay_options_page');

//加载主菜单
function wc_starpay_apply_options_page_html() {
    if (!current_user_can('manage_options')) {
       return;
    }
    ?>
     <div class=wrap>
         <h1><?= esc_html(get_admin_page_title()); ?></h1>
         <h2>Please visit our official website for service application.</h2>
         <h2>English:  <a href="https://www.netstars.co.jp/en/contact/" target="_blank">https://www.netstars.co.jp/en/contact/</a></h2>
         <h2>日本語:  <a href="https://www.netstars.co.jp/kameiten_contact/" target="_blank">https://www.netstars.co.jp/kameiten_contact/</a></h2>
     </div>
    <?php
}

//加载主菜单
function wc_starpay_aboutus_options_page_html() {
    if (!current_user_can('manage_options')) {
       return;
    }
    ?>
     <div class=wrap>
         <h1><?= esc_html(get_admin_page_title()); ?></h1>
         <h2><a href="https://www.netstars.co.jp" target="_blank">Welcome to visit netstars website.</a></h2>
     </div>
    <?php
    echo "<script language=\"javascript\">window.open ('https://www.netstars.co.jp', 'newwindow')</script>";
}

//加载子菜单
function wc_starpay_wporg_options_page() {

    add_submenu_page(
       'starpayconfig', 
       'Apply',
       'Apply',
       'manage_options',
       'starpayapply',
       'wc_starpay_apply_options_page_html',
       30
    );

    add_submenu_page(
       'starpayconfig', 
       'About Us',
       'About Us',
       'manage_options',
       'starpayaboutus',
       'wc_starpay_aboutus_options_page_html',
       50
    );

}

add_action('admin_menu', 'wc_starpay_wporg_options_page');

//卸载时数据清理
function wc_starpay_pluginprefix_function_to_run() {
   //菜单清理 
    remove_menu_page('starpayaboutus');
    remove_menu_page('starpayapply');
    remove_menu_page('starpayconfig');
}

?>