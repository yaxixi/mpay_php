<?php

	/*error_reporting(E_ALL);
    ini_set('display_errors', 'On');
    ini_set('display_startup_errors','On');
    ini_set('error_log', dirname(__FILE__) . '/error_log.txt');*/

    error_reporting(0);

    include_once "common/log.php";
    include_once "common/ez_sql_mysql.php";
    include_once "phpqrcode/phpqrcode.php";
    use \QRcode;

    function go_error($ret, $msg)
    {
        addLog("precharge", $msg . " " . $ret);
        die($ret);
    }

    /*
     * rc4加密算法
     * $pwd 密钥
     * $data 要加密的数据
     */
    function rc4 ($pwd, $data)//$pwd密钥 $data需加密字符串
    {
        $key[] ="";
        $box[] ="";

        $pwd_length = strlen($pwd);
        $data_length = strlen($data);

        for ($i = 0; $i < 256; $i++)
        {
            $key[$i] = ord($pwd[$i % $pwd_length]);
            $box[$i] = $i;
        }

        for ($j = $i = 0; $i < 256; $i++)
        {
            $j = ($j + $box[$i] + $key[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }

        for ($a = $j = $i = 0; $i < $data_length; $i++)
        {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;

            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;

            $k = $box[(($box[$a] + $box[$j]) % 256)];
            $cipher .= chr(ord($data[$i]) ^ $k);
        }

        return $cipher;
    }

    $orderid = $_REQUEST["orderid"];
    $orderuid = $_REQUEST["orderuid"];
    $uid = $_REQUEST["uid"];
    if (is_string($_REQUEST["channel"]))
        $channel = $_REQUEST["channel"];
    else
        $channel = "";
    $price = (string)$_REQUEST["price"];
    $istype = (string)$_REQUEST["istype"];
    $notify_url = $_REQUEST["notify_url"];
    $return_url = $_REQUEST["return_url"];
    $goodsname = $_REQUEST["goodsname"];
    $key = $_REQUEST["key"];
    $token = '';

    $db = ezSQL_mysql::get_db("mpay");
    if (!$db)
    {
        $return['msg'] = '数据库连接失败';
        $return['data'] = '';
        $return['code'] = -1;
        $return['url'] = '';
        go_error(json_encode($return), "fail to connect dbasebase");
    }

    $ret = $db->get_row("select status, token from vendor where uid='$uid'");
    if ($ret && (int)$ret['status'] == 0)
    {
        $token = $ret['token'];
    }
    else
    {
        $db->disconnect();
        $return['msg'] = '商户不合法';
        $return['data'] = '';
        $return['code'] = -1;
        $return['url'] = '';
        die(json_encode($return));
    }

    if ($token)
    {
        // 进行验证
        $my_key = strtolower(md5($channel. $goodsname. $istype . $notify_url . $orderid . $orderuid . $price . $return_url . $token . $uid));
        if (strcmp($my_key, $key) != 0)
        {
            $db->disconnect();
            $return['msg'] = 'key验证失败';
            $return['data'] = '';
            $return['code'] = -1;
            $return['url'] = '';
            die(json_encode($return));
        }
        else
        {
            // 验证通过，取得信息
            $ret = $db->get_row("select account, accountid from account where uid='$uid' and status=0 order by fetch_time limit 1");
            if ($ret)
            {
                $time = time();
                $account = $ret['account'];
                $accountid = $ret['accountid'];
                // 插入预充值表
                $ret = $db->query("insert into precharge (`orderid`,`orderuid`,`account`,`uid`,`channel`,`price`,`time`,`notify_url`,`return_url`,`goodsname`) value ('$orderid','$orderuid','$account','$uid','$channel',$price,$time,'$notify_url','$return_url','$goodsname')");
                if (is_int($ret) && $ret == 1)
                {
                    // 更新该帐号的获取时间
                    $time = time();
                    $ret = $db->query("update account set fetch_time=$time where account='$account'");
                    $db->disconnect();

                    $crypt_accountid = urlencode(base64_encode(rc4("fdsas#%226", $accountid)));
                    $crypt_orderid = urlencode(base64_encode(rc4("fdsas#%226", $orderid)));
                    $url = "http://www.axixi.top/topay.php?ac=". $crypt_accountid. "&id=". $crypt_orderid;
                    $qrcode = QRcode:text($url);
                    $return['msg'] = '成功预充值';
                    $return['data'] = array(
                        'phone_url'=> $url,
                        'qrcode'=>$qrcode,
                    );
                    $return['code'] = 1;
                    $return['url'] = '';
                    die(json_encode($return));
                }
                else
                {
                    $db->disconnect();
                    $return['msg'] = '订单号重复';
                    $return['data'] = '';
                    $return['code'] = -2;
                    $return['url'] = '';
                    die(json_encode($return));
                }
            }
            else
            {
                $db->disconnect();
                $return['msg'] = '获得帐号失败，请稍候再试';
                $return['data'] = '';
                $return['code'] = -2;
                $return['url'] = '';
                die(json_encode($return));
            }
        }
    }
    else
    {
        $db->disconnect();
        $return['msg'] = '商户不合法';
        $return['data'] = '';
        $return['code'] = -1;
        $return['url'] = '';
        die(json_encode($return));
    }
?>
