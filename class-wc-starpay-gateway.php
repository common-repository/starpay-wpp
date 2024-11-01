<?php
if (! defined ( 'ABSPATH' ))
    exit (); // Exit if accessed directly
    
class WC_Starpay_Gateway extends WC_Payment_Gateway {

    //构造函数
    public function __construct() {

        array_push($this->supports,'refunds');
        $this->id = C_WC_STARPAY_ID;
        $this->icon =C_WC_STARPAY_URL. '/images/starpay.png';
        $this->has_fields = false;
        
        //WC配置页上的方法、描述
        $this->method_title = 'StarPay payment gateway';
        $this->method_description='StarPay payment function provided by <a href="https://www.netstars.co.jp" target="_blank">StarPay Inc.</a>';
        
        //通过多维数据将所有参数放到form里面
        $this->wc_starpay_init_form_fields ();
        $this->merchantId = $this->get_option ( 'merchantId' );
        $this->signKey = $this->get_option ( 'signKey' );
        $this->gatewayUrl = $this->get_option ( 'gatewayUrl' );
        $this->currency = $this->get_option ( 'currency' );
        $this->logging = $this->get_option( 'logging' );
        $this->wpUrl = $this->get_option ( 'wpUrl' );
        $this->title = 'StarPay-WPP';
        $this->description = 'StarPay online payment gateway. Support Japan and China mainstream payment methods.';
        if ( 'yes' == $this->logging ) {
            $this->log = new WC_Logger();
        }

        //处理并保存选项
        require_once( plugin_basename( 'wc-starpay-utils.php' ) );
        add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) ); // WC <= 1.6.6
        add_action( 'woocommerce_update_options_payment_gateways_'.C_WC_STARPAY_ID, array( $this, 'process_admin_options' ) ); // WC >= 2.0
        add_action( 'woocommerce_receipt_'.C_WC_STARPAY_ID, array($this, 'starpay_order'));
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'wc_starpay_custom_payment_update_order_meta') );
        add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ) );
    }

    //插件选项
    function wc_starpay_init_form_fields() {

        //定义多维数组
        $enabled = array (
            'title' => __ ( 'Enable/Disable', C_WC_STARPAY_ID ),
            'type' => 'checkbox',
            'label' => __ ( 'StarPay-WPP', C_WC_STARPAY_ID ),
            'default' => 'no'
        );

        $merchantId = array (
            'title' => __ ( 'Merchant ID', C_WC_STARPAY_ID ),
            'type' => 'text',
            'description' => __ ( 'Register your merchant id.', C_WC_STARPAY_ID ),
            'css' => 'width:400px',
            'default' => __ ( '', C_WC_STARPAY_ID )
        );

        $signKey = array (
            'title' => __ ( 'Merchant Signature Key', C_WC_STARPAY_ID ),
            'type' => 'text',
            'description' => __ ( 'Register your merchant signature key.', C_WC_STARPAY_ID ),
            'css' => 'width:400px',
            'default' => __ ( '', C_WC_STARPAY_ID )
        );

        $gatewayUrl = array (
            'title' => __ ( 'StarPay Gateway URL', C_WC_STARPAY_ID ),
            'type' => 'text',
            'description' => __ ( 'Register starpay gateway server url.', C_WC_STARPAY_ID ),
            'css' => 'width:400px',
            'default' => __ ( '', C_WC_STARPAY_ID )
        );

        $wpUrl = array (
            'title' => __ ( 'WordPress URL', C_WC_STARPAY_ID ),
            'type' => 'text',
            'description' => __ ( 'Register your wordpress server url.', C_WC_STARPAY_ID ),
            'css' => 'width:400px',
            'default' => __ ( '', C_WC_STARPAY_ID )
        );

        $currency = array (
            'title' => __ ( 'Currency', C_WC_STARPAY_ID ),
            'type' => 'select',
            'description' => __ ( 'Support Japanese Yen (JPY).', C_WC_STARPAY_ID ),
            'options' => array(
                'JPY' => 'JPY'
            ),
            'default' => 'JPY'
        );

        $logging = array(
            'title'       => __('Debug Log', C_WC_STARPAY_ID),
            'type'        => 'checkbox',
            'label'       => __('Log debug messages', C_WC_STARPAY_ID),
            'default'     => 'no',
            'description' => __('Log payment events, such as trade status, inside <code>wp-content/uploads/wc-logs/starpaygateway*.log</code>', C_WC_STARPAY_ID)
        );

        $this->form_fields = array();
        $this->form_fields['enabled'] = $enabled;
        $this->form_fields['merchantId'] = $merchantId;
        $this->form_fields['signKey'] = $signKey;
        $this->form_fields['gatewayUrl'] = $gatewayUrl;
        $this->form_fields['currency'] = $currency;
        $this->form_fields['wpUrl'] = $wpUrl;
        $this->form_fields['logging'] = $logging;
    }

    //处理付款
    public function process_payment( $order_id) {
        $order = new WC_Order( $order_id );
        return array (
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url ( true )
        );
    }

    //下单操作处理
    public function starpay_order($order_id) {

        $this->logging("Action: process_order, setp 1, [wordpress -> starpay], Desc: orderid: ".$order_id );
        $signKey = $this->signKey;
        $gatewayUrl = $this->gatewayUrl;
        $wpUrl = $this->wpUrl;
        $httpCode = 0;

        //验证URL配置
        if(trim($gatewayUrl) == ""){
            $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: request orderid: ".$order_id." Invalid StarPay Gateway URL" );
            ?>
                <p>Invalid starpay gateway url please check configuration.</p>
            <?php
            return new WP_Error( 'invalid_order', 'Invalid StarPay Gateway URL' );
        }

        //验证URL配置
        if(trim($wpUrl) == ""){
            $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: request orderid: ".$order_id." Invalid WordPress URL" );
            ?>
                <p>Invalid wordpress url please check configuration.</p>
            <?php
            return new WP_Error( 'invalid_order', 'Invalid WordPress URL' );
        }

        $records = wc_starpay_db_order_query_by_id($order_id,"PAID");
        if ($records){
            $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: find orderid: ".$order_id ." Order has been paid");
            ?>
                <p>Order has been paid.</p>
            <?php
            return new WP_Error( 'invalid_order', 'Order has been paid' );
        }

        $records = wc_starpay_db_order_query_by_id($order_id,"SUCCESS");
        if ($records){
            foreach ($records as $record) {
                $recordTradeNo = $record->tradeno;
                $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: find tradeNo: ".$recordTradeNo." orderid: ".$order_id );
                $paymentState = $this->wc_starpay_is_order_payment($order_id, $recordTradeNo);
                if($paymentState === 'SUCCESS') {
                    $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: find tradeNo: ".$recordTradeNo." orderid: ".$order_id ." Order has been paid");
                    ?>
                        <p>Order has been paid.</p>
                    <?php
                    return new WP_Error( 'invalid_order', 'Order has been paid' );
                }
                if($paymentState === 'USERPAYING') {
                    $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: find tradeNo: ".$recordTradeNo." orderid: ".$order_id ." Order USERPAYING");
                    ?>
                        <p>Order is being paid pls wait and try again later.</p>
                    <?php
                    return new WP_Error( 'invalid_order', 'Order is being paid pls wait and try again later' );
                }
            }
        }
         
        //每次下单前清除临时表1年前的数据
        wc_starpay_db_order_delete_one();

        //初始化订单
        $order = new WC_Order($order_id);

        //签名字符串
        $strSign = "";

        //下单必选变量
        $mchId = $this->merchantId;
        $amount = $order->get_total();
        $myamount = explode(".",$amount);
        $orderAmount = $myamount[0];
        $nonce = wc_starpay_get_random_str();
        $tradeNo = "";
        $tradeType = "";
        $desc = "WPOrder: ".$order_id;
        
        //下单可选变量
        $detail = "";
        $isCapture = "";
        $notifyUrl = $wpUrl."/wp-json/starpay/v1/recpayresult";
        $attach = (string)$order_id;
        $subOpenId = "";
        $codeType = "";
        $backToMchUrl = $wpUrl."/wp-json/starpay/v1/recbackresult";
        $orderImg = "";
        $needPSPinfo = "";
        
        //客户选择哪个支付钱包
        $tradeType = get_post_meta( $order_id, 'payType', true );
        $method = 'order';

        //生成单号，并更新到订单域
        $inputlen = 16 - (strlen($order_id) + 2);
        if($inputlen < 2) {
            $inputlen = 16;
            $tradeNo = wc_starpay_get_random_str_param($inputlen);
        } else {
            $tradeNo = $order_id."WP".wc_starpay_get_random_str_param($inputlen);
        }
        update_post_meta($order_id, 'tradeNo', $tradeNo);

        //下单数组
        $arrOrderReq = array(
            "MchId"=>$mchId, 
            "Nonce"=>$nonce, 
            "TradeNo"=>$tradeNo, 
            "OrderAmount"=>(int)$orderAmount, 
            "TradeType"=>$tradeType, 
            "Desc"=>$desc
        );

        //提取可用变量
        if ( !empty(trim($detail)) && !is_null($detail)  ) { $arrOrderReq['Detail'] = $detail;}
        if ( !empty(trim($isCapture)) && !is_null($isCapture)  ) { $arrOrderReq['IsCapture'] = $isCapture;}
        if ( !empty(trim($notifyUrl)) && !is_null($notifyUrl)  ) { $arrOrderReq['NotifyUrl'] = $notifyUrl;}
        if ( !empty(trim($attach)) && !is_null($attach)  ) { $arrOrderReq['Attach'] = $attach;}
        if ( !empty(trim($subOpenId)) && !is_null($subOpenId)  ) { $arrOrderReq['SubOpenId'] = $subOpenId;}
        if ( !empty(trim($codeType)) && !is_null($codeType)  ) { $arrOrderReq['CodeType'] = $codeType;}
        if ( !empty(trim($backToMchUrl)) && !is_null($backToMchUrl)  ) { $arrOrderReq['BackToMchUrl'] = $backToMchUrl;}
        if ( !empty(trim($orderImg)) && !is_null($orderImg)  ) { $arrOrderReq['OrderImg'] = $orderImg;}
        if ( !empty(trim($needPSPinfo)) && !is_null($needPSPinfo)  ) { $arrOrderReq['NeedPSPinfo'] = $needPSPinfo;}

        //下单数组排序及输出签名字符串
        ksort($arrOrderReq);
        foreach ($arrOrderReq as $key => $value)
        {
            $strSign = $strSign . $key . "=". $value . "&";
        }
        $strSign = $strSign . "key=". $signKey;
        $arrOrderReq['Sign'] = strtoupper(md5($strSign));
        
        //下单调用
        $orderJson = json_encode($arrOrderReq);
        $this->logging("Action: process_order, setp 2, [wordpress -> starpay], Desc: request tradeNo: ".$tradeNo." orderid: ".$order_id." request json: ".$orderJson);
        $args = array(
            'body'        => $orderJson,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
            'cookies'     => array(),
        );
        $response = wp_remote_post( $gatewayUrl . '/sponline/order', $args );
        
        //先提取状态吗，如果非200直接报错返回
        $httpCode = wp_remote_retrieve_response_code( $response );
        if($httpCode !== 200)
        {
            $this->logging("Action: process_order, setp 3, [wordpress <- starpay], Desc: find tradeNo: ".$tradeNo." orderid: ".$order_id ." SYSTEMERROR httpcode: ".$httpCode);
            ?>
                <p>Order call returns network error.</p>
            <?php
            return new WP_Error( 'invalid_order', 'Order call returns network error' );
        }

        //提取返回值body
        $respRecord = wp_remote_retrieve_body( $response );
        $respOrder = json_decode($respRecord, true);
        $this->logging("Action: process_order, setp 3, [wordpress <- starpay], Desc: request tradeNo: ".$tradeNo." orderid: ".$order_id." response json: ".$respRecord);
        
        //返回值处理
        if( $respOrder['Result'] === "SUCCESS"){
            $this->logging("Action: process_order, setp 4, [wordpress <- starpay], Desc: request tradeNo: ".$tradeNo." orderid: ".$order_id." result: SUCCESS");
            wc_starpay_db_order_insert($tradeNo, $order_id, $tradeType, $orderAmount);
            // 减少库存订单
            if ( function_exists( 'wc_reduce_stock_levels' ) ) { 
                wc_reduce_stock_levels($order_id); 
            } else {
                $order->reduce_order_stock();
            }
            $returnUrl = $this->get_return_url( $order );
            $qrcode_url = $respOrder['CodeUrl'];
            ?>
                <p>Please scan the QR code using the App to complete payment.</p>
                <div>
                    <div style="display: inline-block; margin: 0;">
                        <style type="text/css">
                            .codestyle *{
                                display: block;
                            }
                        </style>
                        <div id="code" class="codestyle"></div>
                        <script type="text/javascript">
                            jQuery("#code").qrcode({ 
                                width: 280,
                                height:280,
                                text: "<?php echo $qrcode_url ?>"
                            }); 
                        </script> 
                        <!-- <img style="display: block;" src="<?php echo C_WC_STARPAY_URL ?>/images/wechat_webscan01.png" />-->
                    </div>
                    <?php 
                        if($tradeType === 'ALQR'){
                            $tempType = "alipay";
                        }else if($tradeType === 'NATIVE'){
                            $tempType = "wechat";
                        }else if($tradeType === 'LNPAY'){
                            $tempType = "linepay";
                        }else if($tradeType === 'PAYPAYOM'){
                            $tempType = "paypay";
                        }else if($tradeType === 'PAYPAYMPM'){
                            $tempType = "paypay";
                        }else if($tradeType === 'UPIMPM'){
                            $tempType = "unionpay";
                        }else if($tradeType === 'MERPAYOM'){
                            $tempType = "merpay";
                        }else if($tradeType === 'RKTPAY'){
                            $tempType = "rpay";
                        }else if($tradeType === 'DPAYMPM'){
                            $tempType = "dpay";
                        }else if($tradeType === 'AUPAYMPM'){
                            $tempType = "aupay";
                        }else if($tradeType === 'JCOINMPM'){
                            $tempType = "jcoinpay";
                        }
                    ?>
                     <div style="display: inline-block; ">
                        <img style="display: block;" src="<?php echo C_WC_STARPAY_URL ?>/images/<?php echo $tempType ?>_logo.png" />
                    </div>
                </div>

                <script>
                    jQuery(document).ready(function() {
                        jQuery(document).on('heartbeat-send', function(event, data) {
                            console.log('orderId: ' + '<?php echo $order_id ?>'+' tradeNo: ' + '<?php echo $tradeNo ?>');
                            data['orderId'] = '<?php echo $order_id ?>';
                            data['tradeNo'] = '<?php echo $tradeNo ?>';
                        });
                        jQuery(document).on('heartbeat-tick', function(event, data) {
                            if(data['status']){
                                console.log('status: ' + data['status']);
                                if(data['status'] === 'SUCCESS'){
                                    window.location.replace('<?php echo $returnUrl ?>');
                                }
                            }
                        });
                        wp.heartbeat.interval( 'fast' );
                    });     
                </script>
            <?php
        } else {
            $this->logging("Action: process_order, setp 4, [wordpress <- starpay], Desc: request tradeNo: ".$tradeNo." orderid: ".$order_id." result: ".$respOrder['Result']." resultdesc: ".$respOrder['ResultDesc'] );
            wc_add_notice( 'Order request failed please contact customer service.', 'error' );
            $order->update_status('failed', $respOrder['Result']."-".$respOrder['ResultDesc']);
            wp_safe_redirect( wc_get_page_permalink( 'checkout' ) );
        }
    }

    //返金处理
    public function process_refund( $order_id, $amount = null, $reason = ''){
        //返金必选变量
        $gatewayUrl = $this->gatewayUrl;
        $this->logging("Action: process_refund, setp 1, [wordpress -> starpay], Desc: request tradeNo: ".$order_id." amount: ".$amount );
        $record = wc_starpay_db_order_query_s_by_id($order_id,"PAID");
        if ($record){
             $tradeNo = $record->tradeno;
             $inputlen = 16 - (strlen($order_id) + 2);
             if($inputlen < 2) {
                 $inputlen = 16;
                 $refundNo = wc_starpay_get_random_str_param($inputlen);
             } else {
                 $refundNo = $order_id."WR".wc_starpay_get_random_str_param($inputlen);
             }
        }else {
             return new WP_Error( 'invalid_order', 'No record found' );
        }

        $order = new WC_Order ( $order_id );
        if(!$order){
            return new WP_Error( 'invalid_order', 'Invalid Order ID' );
        }

        //返金必选变量
        $mchId = $this->merchantId;
        $nonce = wc_starpay_get_random_str();
        $tempAmount = $order->get_total();
        $myamount = explode(".",$tempAmount);
        $orderAmount = $myamount[0];
        
        //客户选择哪个支付钱包
        $payType = get_post_meta( $order_id, 'payType', true );
        $method = 'refund';

        //签名字符串
        $strSign = "";
        $signKey = $this->signKey;
        $gatewayUrl = $this->gatewayUrl;

        //验证URL配置
        if(trim($gatewayUrl) === ""){
            return new WP_Error( 'invalid_order', 'Invalid StarPay Gateway URL' );
        }

        //返金数组
        $arrRefundReq = array(
            "MchId"=>$mchId, 
            "Nonce"=>$nonce, 
            "TradeNo"=>$tradeNo,
            "RefundNo"=>$refundNo,
            "OrderAmount"=>(int)$orderAmount,
            "RefundFee"=>(int)$amount
        );

        //返金数组排序及输出签名字符串
        ksort($arrRefundReq);
        foreach ($arrRefundReq as $key => $value)
        {
            $strSign = $strSign . $key . "=". $value . "&";
        }
        $strSign = $strSign . "key=". $signKey;
        $arrRefundReq['Sign'] = strtoupper(md5($strSign));
        
        //返金调用
        $refundJson = json_encode($arrRefundReq);
        $this->logging("Action: process_refund, setp 2, [wordpress -> starpay], Desc: response tradeNo: ".$tradeNo." amount: ".$amount." request json: ".$refundJson );

        //调用返金接口
        $args = array(
            'body'        => $refundJson,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type: application/json'
            ),
            'cookies'     => array(),
        );
        $response = wp_remote_post( $gatewayUrl . '/sponline/refund', $args );

        //先提取状态吗，如果非200直接报错返回
        $httpCode = wp_remote_retrieve_response_code( $response );
        if($httpCode !== 200)
        {
            $this->logging("Action: process_refund, setp 3, [wordpress <- starpay], Desc: find tradeNo: ".$tradeNo." amount: ".$amount." SYSTEMERROR httpcode: ".$httpCode);
            ?>
                <p>Refund call returns network error.</p>
            <?php
            return new WP_Error( 'invalid_refund', 'Refund call returns network error' );
        }

        //提取返回值body
        $respRecord = wp_remote_retrieve_body( $response );
        $respRefund = json_decode($respRecord, true);
        $this->logging("Action: process_refund, setp 3, [wordpress <- starpay], Desc: response tradeNo: ".$tradeNo." amount: ".$amount." response json: ".$respRecord );

        //返金返回
        if($respRefund['Result'] === 'SUCCESS'){
            $this->logging("Action: process_refund, setp 4, [wordpress <- starpay], Desc: response tradeNo: ".$tradeNo." amount: ".$amount." result: ".$respRefund['Result'] );
            return true;
        }else{
            $this->logging("Action: process_refund, setp 4, [wordpress <- starpay], Desc: response tradeNo: ".$tradeNo." amount: ".$amount." result: ".$respRefund['Result']." resultdesc: ".$respRefund['ResultDesc'] );
            return new WP_Error( 'invalid_order', $respRefund['Result'].$respRefund['ResultDesc']);
        }
    }

    //更新客户的payType
    public function wc_starpay_custom_payment_update_order_meta( $order_id ) {
        if($_POST['payment_method'] != C_WC_STARPAY_ID){
            return;
        }
        $payType = sanitize_text_field($_POST['payType']);
        update_post_meta( $order_id, 'payType', $payType );
    }

    //订单是否已经完成交易根据orderid
    public function wc_starpay_is_order_completed($order_id){
        $this->logging("Action: payment_process_is_order_completed, setp 1, [wordpress -> starpay], Desc: orderid: ".$order_id );
        global $woocommerce;
        $order = new WC_Order( $order_id );
        $isCompleted = false;
        if($order->get_status() == 'completed' || $order->get_status() == 'processing' || $order->get_status() == 'refunded'){
            $isCompleted = true;
            $this->logging("Action: payment_process_is_order_completed, setp 2, [wordpress <- starpay], Desc: orderid: ".$order_id." complete: true" );
            return $isCompleted;
        } else {
            $records = wc_starpay_db_order_query_by_id($order_id,"PAID");
            if ($records){
                $isCompleted = true;
                if ( $order->get_status() != 'completed' || $order->get_status() != 'processing' || $order->get_status() != 'refunded') {
                    $order->payment_complete();
                    $woocommerce->cart->empty_cart();
                }
                $this->logging("Action: payment_process_is_order_completed, setp 2, [wordpress <- starpay], Desc: orderid: ".$order_id." complete: true" );
                return $isCompleted;
            }
            $records = wc_starpay_db_order_query_by_id($order_id,"SUCCESS");
            if ($records){
                foreach ($records as $record) {
                    $recordTradeNo = $record->tradeno;
                    $paymentState = $this->wc_starpay_is_order_payment($order_id, $recordTradeNo);
                    if($paymentState === 'SUCCESS') {
                        $isCompleted = true;
                        if ( $order->get_status() != 'completed' || $order->get_status() != 'processing' || $order->get_status() != 'refunded') {
                            $order->payment_complete();
                            $woocommerce->cart->empty_cart();
                        }
                        $this->logging("Action: payment_process_is_order_completed, setp 2, [wordpress <- starpay], Desc: orderid: ".$order_id." complete: true" );
                        return $isCompleted;
                    }
                }
            }
        }
        $this->logging("Action: payment_process_is_order_completed, setp 2, [wordpress <- starpay], Desc: orderid: ".$order_id." complete: false" );
        return $isCompleted;
    }

    //订单是否已经完成付款
    public function wc_starpay_is_order_payment($orderid, $tradeno){
        $this->logging("Action: is_order_payment, setp 1, [wordpress -> starpay], Desc: tradeNo: ".$tradeno." orderid: ".$orderid );
        global $woocommerce;
        $order = new WC_Order( $orderid );
        $orderstate = "NOTPAY";
        if($order->get_status() == 'completed' || $order->get_status() == 'processing' || $order->get_status() == 'refunded'){
            $this->logging("Action: is_order_payment, setp 2, [wordpress -> starpay], Desc: tradeNo: ".$tradeno." orderid: ".$orderid." wp payment has been completed " );
            $orderstate = "SUCCESS";
        } else {
            $orderstate = $this->starpay_query_order_state($orderid, $tradeno);
            $this->logging("Action: is_order_payment, setp 2, [wordpress <- starpay], Desc: tradeNo: ".$tradeno." orderid: ".$orderid." starpay order status: ".$orderstate );
            if($orderstate === 'SUCCESS' || $orderstate === 'REFUND'){
                if ( $order->get_status() != 'completed' || $order->get_status() != 'processing' || $order->get_status() != 'refunded') {
                    $order->payment_complete();
                    if ($woocommerce->cart !== null)
                        $woocommerce->cart->empty_cart();
                }
                $orderstate = 'SUCCESS';
            }
        }
        return $orderstate;
    }

    //自定义支付表格
    public function payment_fields(){
        if ( $description = $this->get_description() ) {
            echo wpautop( wptexturize( $description ) );
        }
        $mchTradeType = "";
        $mchTradeType = $this->starpay_query_mch_tradetype($this->merchantId);
        ?>
        <div id="custom_input">
            <p class="form-row form-row-wide">
                <?php 
                    if(strpos($mchTradeType,"ALQR") !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="ALQR" checked />Alipay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/alipay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'NATIVE') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="NATIVE" />WeChat Pay<img src="<?php echo C_WC_STARPAY_URL ?>/images/wechat_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'UPIMPM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="UPIMPM" />Union Pay &nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/unionpay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'PAYPAYOM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="PAYPAYOM" />PayPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/paypay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'PAYPAYMPM') !== false){
                        if(strpos($mchTradeType,'PAYPAYOM') === false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="PAYPAYMPM" />PayPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/paypay_logo.png" /></label>
                <?php 
                        }
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'LNPAY') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="LNPAY" />Line Pay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/linepay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'MERPAYOM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="MERPAYOM" />MerPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/merpay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'RKTPAY') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="RKTPAY" />Rakuten Pay<img src="<?php echo C_WC_STARPAY_URL ?>/images/rpay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'DPAYMPM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="DPAYMPM" />DPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/dpay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'AUPAYMPM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="AUPAYMPM" />AuPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/aupay_logo.png" /></label>
                <?php 
                    }
                ?>

                <?php 
                    if(strpos($mchTradeType,'JCOINMPM') !== false){
                ?> 
                    <br/>
                    <label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" name="payType" value="JCOINMPM" />JcoinPay &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<img src="<?php echo C_WC_STARPAY_URL ?>/images/jcoinpay_logo.png" /></label>
                <?php 
                    }
                ?>
            </p>
        </div>
        <?php
    }

    //支付成功页面
    public function thankyou_page($order_id) {
        $this->wc_starpay_is_order_completed($order_id);
        $order = new WC_Order( $order_id );
        $this->logging("Action: payment_process_thankyou_page, setp 1, [wordpress <- starpay], Desc: tradeNo: ".$order_id );
    }

    //查询订单状态
    function starpay_query_order_state($orderid, $tradeno){
        $this->logging("Action: starpay_query_order_state, setp 1, [wordpress -> starpay], Desc: tradeNo: ".$tradeno." orderid: ".$orderid );
        $order = new WC_Order ( $orderid );
        if(!$order){
            $this->logging("Action: starpay_query_order_state, setp 2, [wordpress -> starpay], Desc: response tradeNo: ".$tradeno." orderid: ".$orderid." result: invalid_order resultdesc: Invalid Order ID" );
            return new WP_Error( 'invalid_order', 'Invalid Order ID' );
        }

        //查询必选变量
        $mchId = $this->merchantId;
        $nonce = wc_starpay_get_random_str();
        $tradeNo = $tradeno;
        
        //客户选择哪个支付钱包
        $payType = get_post_meta( $orderid, 'payType', true );
        $method = 'payQuery';

        //签名字符串
        $strSign = "";
        $signKey = $this->signKey;
        $gatewayUrl = $this->gatewayUrl;

        //验证URL配置
        if(trim($gatewayUrl) === ""){
            $this->logging("Action: starpay_query_order_state, setp 2, [wordpress -> starpay], Desc: response tradeNo: ".$tradeno." orderid: ".$orderid." result: invalid_config resultdesc: Invalid StarPay Gateway URL" );
            return new WP_Error( 'invalid_config', 'Invalid StarPay Gateway URL' );
        }

        //查询数组
        $arrPayQueryReq = array(
            "MchId"=>$mchId, 
            "Nonce"=>$nonce, 
            "TradeNo"=>$tradeNo
        );

        //查询数组排序及输出签名字符串
        ksort($arrPayQueryReq);
        foreach ($arrPayQueryReq as $key => $value)
        {
            $strSign = $strSign . $key . "=". $value . "&";
        }
        $strSign = $strSign . "key=". $signKey;
        $arrPayQueryReq['Sign'] = strtoupper(md5($strSign));
        
        //查询调用
        $payQueryJson = json_encode($arrPayQueryReq);
        $args = array(
            'body'        => $payQueryJson,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type: application/json'
            ),
            'cookies'     => array(),
        );
        $response = wp_remote_post( $gatewayUrl . '/sponline/payQuery', $args );
        
        //先提取状态吗，如果非200直接报错返回
        $httpCode = wp_remote_retrieve_response_code( $response );
        if($httpCode !== 200)
        {
            return "SYSTEMERROR-httpcode: ".$httpCode;
        }

        //提取返回值body
        $respRecord = wp_remote_retrieve_body( $response );
        $respPayQuery = json_decode($respRecord, true);

        if($respPayQuery['Result'] === 'SUCCESS' && $respPayQuery['TradeState'] === 'SUCCESS'){
            $this->logging("Action: starpay_query_order_state, setp 2, [wordpress <- starpay], Desc: response tradeNo: ".$tradeno." orderid: ".$orderid." result: SUCCESS" );
            return 'SUCCESS';
        }else{
            if($respPayQuery['Result'] === 'SUCCESS') {
                $this->logging("Action: starpay_query_order_state, setp 2, [wordpress <- starpay], Desc: response tradeNo: ".$tradeno." orderid: ".$orderid." result: ".$respPayQuery['TradeState'] );
                return $respPayQuery['TradeState'];
            }
            else {
                $this->logging("Action: starpay_query_order_state, setp 2, [wordpress <- starpay], Desc: response tradeNo: ".$tradeno." orderid: ".$orderid." result: ".$respPayQuery['Result']." resultdesc: ".$respPayQuery['ResultDesc'] );
                return $respPayQuery['Result']."-".$respPayQuery['ResultDesc'];
            }
        }
    }

    //查询商户支付类型
    function starpay_query_mch_tradetype($mchId){
        $this->logging("Action: starpay_query_mch_tradetype, setp 1, [wordpress -> starpay], Desc: mchId: ".$mchId );

        //查询必选变量
        $nonce = wc_starpay_get_random_str();
        $method = 'mchTradeType';

        //签名字符串
        $strSign = "";
        $signKey = $this->signKey;
        $gatewayUrl = $this->gatewayUrl;

        //验证URL配置
        if(trim($gatewayUrl) == ""){
            $this->logging("Action: starpay_query_mch_tradetype, setp 2, [wordpress -> starpay], Desc: mchId: ".$mchId." gatewayUrl is null" );
            return '';
        }

        //查询数组
        $arrMchTradeTypeReq = array(
            "MchId"=>$mchId, 
            "Nonce"=>$nonce
        );

        //查询数组排序及输出签名字符串
        ksort($arrMchTradeTypeReq);
        foreach ($arrMchTradeTypeReq as $key => $value)
        {
            $strSign = $strSign . $key . "=". $value . "&";
        }
        $strSign = $strSign . "key=". $signKey;
        $arrMchTradeTypeReq['Sign'] = strtoupper(md5($strSign));
        
        //查询调用
        $payQueryJson = json_encode($arrMchTradeTypeReq);
        $args = array(
            'body'        => $payQueryJson,
            'timeout'     => '5',
            'redirection' => '5',
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type: application/json'
            ),
            'cookies'     => array(),
        );
        $response = wp_remote_post( $gatewayUrl . '/sponline/mchTradeType', $args );
        
        //先提取状态吗，如果非200直接报错返回
        $httpCode = wp_remote_retrieve_response_code( $response );
        if($httpCode !== 200)
        {
            $this->logging("Action: starpay_query_mch_tradetype, setp 2, [wordpress <- starpay], Desc: mchId: ".$mchId." return SYSTEMERROR-httpcode: ".$httpCode);
            return '';
        }

        //提取返回值body
        $respRecord = wp_remote_retrieve_body( $response );
        $respPayQuery = json_decode($respRecord, true);

        if($respPayQuery['Result'] == 'SUCCESS'){
            $mchTradeType = $respPayQuery['MchTradeType'];
            $this->logging("Action: starpay_query_mch_tradetype, setp 2, [wordpress <- starpay], Desc: mchId: ".$mchId." mchTradeType: ".$mchTradeType );
            return $mchTradeType;
        }else{
            return '';
        }
    }
    
    //post远程调用
    function do_post_request($url, $post_data){
        $result = wp_remote_post( $url, array( 
            'headers' => array("Content-type" => "application/json;charset=UTF-8"),
            'body' => $post_data ) );
        return $result;
    }
    
    //日志
    function logging($message) {
        if ( 'yes' == $this->logging ) {
            $this->log->add(C_WC_STARPAY_ID, $message);
        }
    }
    
    //日志函数
    function wc_starpay_log($message) {
        if (WP_DEBUG === true) {
            //判断是否为数据或对象
            if (is_array($message) || is_object($message)) {
                error_log(print_r($message, true));
            } else {
                error_log($message);
            }
        }
    }
    
    //判断是否为移动终端打开页面
    function isMobile() {
        if (isset($_SERVER['HTTP_X_WAP_PROFILE'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_VIA'])) {
            return stristr($_SERVER['HTTP_VIA'], "wap") ? true : false;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $clientkeywords = array('nokia','sony','samsung','htc','lg','lenovo','iphone','blackberry','meizu','android','netfront','ucweb','windowsce','palm','operamini','operamobi','openwave','nexus','pixel','wap','mobile','MicroMessenger','AlipayClient','HUAWEI','XiaoMi','OPPO','vivo');
            if (preg_match("/(" . implode('|', $clientkeywords) . ")/i", strtolower($_SERVER['HTTP_USER_AGENT']))) {
                return true;
            }
        }

        if (isset ($_SERVER['HTTP_ACCEPT'])) {
            if ( (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') !== false) && 
                (strpos($_SERVER['HTTP_ACCEPT'], 'text/html') === false || 
                    (strpos($_SERVER['HTTP_ACCEPT'], 'vnd.wap.wml') < strpos($_SERVER['HTTP_ACCEPT'], 'text/html'))) ) {
                return true;
            }
        }
        return false;
    }
    
    //是否在微信中打开
    function isWeChat(){ 
        if ( strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false ) {
            return true;
        }
        return false;
    }

    /**
     * 生成Notify的response响应json
     * @return string 生成json字符串
     */
    function GenerateNotifyRespJson($result, $resultdesc) {
        $arrPayQueryReq = array(
            "Nonce"=>wc_starpay_get_random_str(), 
            "Result"=>$result,
            "ResultDesc"=>$resultdesc
        );
        //输出签名字符串
        $strSign = "";
        ksort($arrPayQueryReq);
        foreach ($arrPayQueryReq as $key => $value)
        {
            $strSign = $strSign . $key . "=". $value . "&";
        }
        $strSign = $strSign . "key=". $this->signKey;
        $arrPayQueryReq['Sign'] = strtoupper(md5($strSign));
        //jsonpb字符串返回
        return json_encode($arrPayQueryReq);
    }
    
}

?>