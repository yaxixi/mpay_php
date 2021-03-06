<?php

	/*error_reporting(E_ALL);
    ini_set('display_errors', 'On');
    ini_set('display_startup_errors','On');
     */

   error_reporting(0);

    include_once "common/log.php";
    include_once "common/ez_sql_mysql.php";
    require_once('common/SnsNetwork.php');

    function go_error($ret, $msg)
    {
        addLog("pay", $msg . " " . $ret);
        die($ret);
    }

    function get_trade_no()
    {
        $yCode = array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q');
        $orderSn = $yCode[intval(date('Y')) - 2017] . strtoupper(dechex(date('m'))) . date('d') . substr(time(), -5) . substr(microtime(), 2, 5) . sprintf('%02d', rand(0, 999999));
        return $orderSn;
    }

    $data = $_REQUEST["data"];
    $uid = $_REQUEST["uid"];
    $key = $_REQUEST["key"];
    $token = '';
    //die(json_encode(array('ret'=>0,'msg'=>json_encode($_REQUEST))));

    $db = ezSQL_mysql::get_db("mpay");
    if (!$db)
    {
        $return['ret'] = -1;
        go_error(json_encode($return), "fail to connect dbasebase");
    }

    function order_handler($order_info)
    {
        $money = $order_info['money'];
        $account = $order_info['account'];
        $fromName = $order_info['fromName'];
        $orderid = $order_info['remark'];
        $clientTime = $order_info['time'];

        global $db;

        if (preg_match('/(.*)\(.*\)/', $orderid, $matches))
        {
            $orderid = $matches[1];
        }

        // 查询预充值
        $ret = $db->get_row("select * from precharge where tradeno='$orderid'");
        if ($ret)
        {
            if ((int)$ret['status'] != 0)
            {
                // 该订单已处理
                $ret = $db->get_row("select price, clientTime from charge where tradeno='$orderid'");
                if ($ret)
                {
                    if ($ret['price'] != $money || $ret['clientTime'] != $clientTime)
                    {
                        // 重复订单，不同充值记录
                        $keyId = strtolower(md5($fromName. $account. $orderid. $money. $clientTime));
                        $db->query("insert into charge_exception (`keyId`, `userid`,`account`,`remark`,`price`,`clientTime`) value ('$keyId', '$fromName','$account','$orderid', $money, '$clientTime')");
                    }
                }

                return;
            }
            //if ((int)$ret['orderuid'] == 43 || (int)$ret['orderuid'] == 53 || (int)$ret['orderuid'] == 55)
           // {
                // 该下游需要比对金额，不匹配当作异常单
                $order_price = round((float)$ret['goodsname'], 2);
                $price = round((float)$money, 2);
                if ($order_price != $price)
                {
                    $keyId = strtolower(md5($fromName. $account. $orderid. $money. $clientTime));
                    $remark = $orderid . "(订单金额$order_price)";
                    $db->query("insert into charge_exception (`keyId`, `userid`,`account`,`remark`,`price`,`clientTime`) value ('$keyId', '$fromName','$account','$remark', $money, '$clientTime')");
                    return;
                }
          //  }

            // 增加帐号收款金额
            $account = $ret['account'];
            $db->query("update account set money = money + $money where account='$account'");
            $db->query("update account set status = 1 where account='$account' and money >= max_money");

            // 插入
            $tradeno = $ret['tradeno'];
            $orderuid = $ret['orderuid'];
            $orderid = $ret['orderid'];
            $channel = $ret['channel'];
            $goodsname = $ret['goodsname'];
            $notify_url = $ret['notify_url'];
            $uid = $ret['uid'];
            $time = time();
            $ret = $db->query("insert into charge (`tradeno`,`orderid`,`account`,`userid`,`orderuid`,`uid`,`channel`,`goodsname`,`price`,`notify_url`,`time`,`clientTime`) value ('$tradeno','$orderid','$account','$fromName','$orderuid','$uid','$channel','$goodsname',$money,'$notify_url',$time,'$clientTime')");
            if ($ret == 1)
            {
                // 更新 precharge 表的 status
                $db->query("update precharge set status=1 where orderid='$orderid'");

                // 更新 paycode 表的 time
                $db->query("update paycode set time=0 where tradeno='$tradeno'");

                $token = '';
                $ret2 = $db->get_row("select token,rate from vendor where uid='$uid'");
                if ($ret2)
                {
                    $token = $ret2['token'];

                    // 通知商店支付成功
                    $key = strtolower(md5($orderid. $orderuid. $tradeno. $money. $token));
                    $params = array(
                        'platform_trade_no'=>$tradeno,
                        'orderid'=>$orderid,
                        'uid'=>$uid,
                        'rate'=>$ret2['rate'],
                        'price'=>(double)$money,
                        'notify_url'=>$notify_url,
                        'orderuid'=>$orderuid,
                        'key'=>$key,
                    );
                    $line = SnsNetwork::makeRequest("http://localhost:9001/pay", $params, '', 'post');
                }
            }
        }
        else
        {
            $keyId = strtolower(md5($fromName. $account. $orderid. $money. $clientTime));
            // 找不到该订单，记录充值异常表
            $db->query("insert into charge_exception (`keyId`, `userid`,`account`,`remark`,`price`,`clientTime`) value ('$keyId', '$fromName','$account','$orderid', $money, '$clientTime')");
        }
    }

    //$data = stripslashes(html_entity_decode($data));
    $ret = $db->get_row("select status, salt, token from vendor where uid='$uid'");
    if ($ret)
    {
        if ((int)$ret['status'] != 0)
            die(json_encode(array('ret'=>0,'msg'=>'uid无效')));

        $salt = $ret['salt'];
        $token = $ret['token'];

        // 进行验证
        $my_key = strtolower(md5($data. $salt. $uid));
        //echo $salt . " uid :" . $uid . " data:" . $data . " key : ". $my_key;
        if (strcmp($my_key, $key) != 0)
        {
            addLog("pay", "key not correct." . json_encode($_REQUEST));
            die(json_encode(array('ret'=>0,'msg'=>'key验证失败')));
        }
        else
        {
            $order_list = json_decode($data, true);
            foreach($order_list as $order)
            {
                $order_info = json_decode($order, true);
                order_handler($order_info);
            }
            die(json_encode(array('ret'=>0)));
        }
    }

    echo json_encode(array('ret'=>0,'msg'=>'找不到uid'));
?>
