<?php

if (! defined ( 'ABSPATH' )){
    exit (); // Exit if accessed directly
}

/**
 * 随机生成16位字符串
 * @return string 生成的字符串
 */
function wc_starpay_get_random_str() {
    $str = "";
    $str_pol = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz";
    $max = strlen($str_pol) - 1;
    for ($i = 0; $i < 16; $i++) {
        $str .= $str_pol[mt_rand(0, $max)];
    }
    return $str;
}

/**
 * 根据输入位数随机生成字符串
 * @return string 生成的字符串
 */
function wc_starpay_get_random_str_param($inputlen) {
    $str = "";
    $str_pol = "ABCDEFGHIJKLMNOQSTUVWXYZ0123456789abcdefghijklmnoqstuvwxyz";
    $max = strlen($str_pol) - 1;
    for ($i = 0; $i < $inputlen; $i++) {
        $str .= $str_pol[mt_rand(0, $max)];
    }
    return $str;
}

//重定向
function wc_starpay_redirect($url){
    header('Location: '.$url);
    exit();
}

/**
 * 数据表初始化建立wp_starpaywpp_order临时表
 */
function wc_starpay_db_init() {
    global $wpdb;
    $sql = "CREATE TABLE IF NOT EXISTS wp_starpaywpp_order (
        tradeno  varchar(16) NOT NULL ,
        orderid  varchar(100) NOT NULL ,
        tradetype  varchar(16) NOT NULL ,
        orderamount  int NOT NULL ,
            state  varchar(16) NOT NULL ,
        statetime  varchar(20) NOT NULL ,
        PRIMARY KEY (tradeno),
        INDEX(orderid)
    ) ;";
    dbDelta( $sql );
}

/**
 * 清除临时表1年前的数据
 */
function wc_starpay_db_order_delete_one() {
    global $wpdb;
    $sql = "DELETE FROM wp_starpaywpp_order where date_format(statetime,'%Y-%m-%d %H:%i:%s') < date_add(curdate(),INTERVAL -12 month) ;";
    $records = $wpdb->query($sql);
    return $records;
}

/**
 * 查询临时表，根据orderid
 * @return records 返回结果集
 */
function wc_starpay_db_order_query_by_id($orderid, $orderstate) {
    global $wpdb;
    $sql = " SELECT * FROM wp_starpaywpp_order WHERE orderid = '$orderid' AND state = '$orderstate' ;";
    $records = $wpdb->get_results($sql);
    return $records;
}

/**
 * 查询临时表，根据tradeno，返回单行
 * @return record 返回结果
 */
function wc_starpay_db_order_query_s_by_tradeno($tradeno) {
    global $wpdb;
    $sql = " SELECT * FROM wp_starpaywpp_order WHERE tradeno = '$tradeno' ;";
    $record = $wpdb->get_row($sql);
    return $record;
}

/**
 * 查询临时表，根据orderid，返回单行
 * @return record 返回结果
 */
function wc_starpay_db_order_query_s_by_id($orderid, $orderstate) {
    global $wpdb;
    $sql = " SELECT * FROM wp_starpaywpp_order WHERE orderid = '$orderid' AND state = '$orderstate' ;";
    $record = $wpdb->get_row($sql);
    return $record;
}

/**
 * 插入临时表
 */
function wc_starpay_db_order_insert($tradeno, $orderid, $tradetype, $orderamount) {
    global $wpdb;
    $wpdb->insert( 'wp_starpaywpp_order', array( 'tradeno' => $tradeno, 'orderid' => $orderid, 'tradetype' => $tradetype, 'orderamount' => $orderamount, 'state' => 'SUCCESS', 'statetime' => date("YmdHis") ), array( '%s', '%s', '%s', '%d', '%s', '%s' ) );
}

/**
 * 更新临时表
 */
function wc_starpay_db_order_update($tradeno) {
    global $wpdb;
    $wpdb->update( 'wp_starpaywpp_order', array( 'state' => 'PAID', 'statetime' => date("YmdHis") ), array( 'tradeno' => $tradeno ), array( '%s', '%s' ), array( '%s' ) );
}

?>