-- sdk_payd.lua
-- Created by yaxixi, 2012.4.10
-- http支付请求模块

-- 声明模块名
module("SDK_PAY_D", package.seeall);
declare_module(L_SDK_PAY_D, "SDK_PAY_D");

local timer_id = -1;
local cur_date;
local RATE = 0.001235;
local BALANCE_LIMIT = -50000;
local balance_enabled = false;
local balance_status = 0;

local function recv_pay_notify(para_list)
    local platform_trade_no = para_list.platform_trade_no;
    local charge_price = to_int(tonumber(para_list['price']));
    local notify_url = url_decode(para_list.notify_url);
    local uid = para_list.uid;
    local rate = para_list.rate;
    para_list.notify_url = nil;
    para_list.uid = nil;
    para_list.rate = nil;

    -- 直接转发通知
    local post_str = generate_post_string(para_list);
    local ret = HTTP_CLIENT_D.post_crt(notify_url, post_str);
    print("recv_pay_notify ret : %o, notify_url : %o, para_list : %o", ret, notify_url, para_list);

    -- 通知成功，更新数据库
    local db_name = ARCHITECTURE_D.get_db_name("charge");
    if not db_name then
        return;
    end

    local flag, sql_cmd;
    if ret == "OK" then
        flag = true;
    else
        local response = json_decode(ret);
        local price = to_int(tonumber(para_list['price']));
        if response and response['code'] == 0 and
            price >= to_int(tonumber(response['price'])) then
            price = response['price'];
            flag = true;

            sql_cmd = string.format("update charge set real_price=%s where tradeno='%s'", price, platform_trade_no);
            DB_D.execute_db_crt(db_name, sql_cmd);
        end
    end

    if flag then
        -- 通知成功，更新数据库
        sql_cmd = string.format("update charge set status=1 where tradeno='%s'", platform_trade_no);
        DB_D.execute_db_crt(db_name, sql_cmd);
        print("succeed to notify pay.");

        local value = charge_price * rate;
        sql_cmd = string.format("update vendor set balance=balance-%s where uid='%s'", value, uid);
        DB_D.execute_db_crt(db_name, sql_cmd);
        print("succeed to update vendor balance.");
    end
end

-- 接受数据
function recv(agent, buffer)
    if not string.find(buffer, "\r\n\r\n") or not string.find(buffer, "/pay") then
        return;
    end

    local para_list = HTTP_SERVER_D.parse_http_request(agent, buffer);
    print("sdk_payd para_str : %o\n", para_list);
    if sizeof(para_list) == 0 then
        HTTP_SERVER_D.send(agent, "para error!");
        return true;
    end

    CRT_D.xpcall(recv_pay_notify, para_list);

    HTTP_SERVER_D.send(agent, "OK");
    return true;
end

local function timer_handle()
    local _handler = function()
        -- 查询未完成的支付，尝试通知
        local db_name = ARCHITECTURE_D.get_db_name("charge");
        if not db_name then
            return;
        end

        local sql_cmd = string.format("select tradeno, orderid, orderuid, price, uid, notify_url from charge where status = 0 and time<=%d limit 20", os.time()-60);
        local ret, result_list = DB_D.read_db_crt(db_name, sql_cmd);
        if type(ret) == "string" then
            print("sdk_payd error : %o\n", ret);
        end
        if result_list and sizeof(result_list) > 0 then
            for _, info in ipairs(result_list) do
                local notify_url = info.notify_url;
                local uid = info.uid;
                local orderid = info.orderid;
                local orderuid = info.orderuid;
                local price = info.price;
                local tradeno = info.tradeno;

                sql_cmd = string.format("select token,rate from vendor where uid='%s'", uid);
                local _, list = DB_D.read_db_crt(db_name, sql_cmd);
                local token;
                local rate = 0;
                if list and list[1] then
                    token = list[1].token;
                    rate = tonumber(list[1].rate);
                end
                if token then
                    local key = md5.sumhexa(orderid .. orderuid .. tradeno .. price .. token);
                    local post_data = {
                        platform_trade_no = tradeno,
                        orderid = orderid,
                        price = price,
                        orderuid = orderuid,
                        key = key,
                    };

                    local post_str = generate_post_string(post_data);
                    local ret = HTTP_CLIENT_D.post_crt(notify_url, post_str);
                    local flag;
                    if ret == "OK" then
                        flag = true;
                    else
                        local response = json_decode(ret);
                        if response and response['code'] == 0 and
                            to_int(tonumber(price)) >= to_int(tonumber(response['price'])) then
                            local real_price = response['price'];
                            flag = true;

                            sql_cmd = string.format("update charge set real_price=%s where tradeno='%s'", real_price, tradeno);
                            DB_D.execute_db_crt(db_name, sql_cmd);
                        end
                    end

                    if flag then
                        -- 通知成功，更新数据库
                        sql_cmd = string.format("update charge set status=1 where tradeno='%s'", tradeno);
                        DB_D.execute_db_crt(db_name, sql_cmd);
                        print("succeed to re-notify pay.");

                        local value = to_int(tonumber(price)) * rate;
                        sql_cmd = string.format("update vendor set balance=balance-%s where uid='%s'", value, uid);
                        DB_D.execute_db_crt(db_name, sql_cmd);
                        print("succeed to re-update vendor balance.");
                    end
                end
            end
        end
    end

    CRT_D.xpcall(_handler);

    local time_str = os.date("!%H%M", os.time() + 28800);
    if time_str >= "0001" and time_str <= "0003" then
        if cur_date ~= os.date("!%Y%m%d", os.time() + 28800) then
            -- 重置
            local db_name = ARCHITECTURE_D.get_db_name("account");
            if not db_name then
                return;
            end

            DB_D.execute_db(db_name, "update account set money = 0");
            cur_date = os.date("!%Y%m%d", os.time() + 28800);

            -- add_balance(0);
        end
    end
end

function add_balance(value)
    value = value or 0;
    local db_name = ARCHITECTURE_D.get_db_name("account");
    if not db_name then
        return;
    end

    local sql_cmd = string.format("update vendor set balance=balance+%s where uid='A9D9113YR003'", value);
    DB_D.execute_db(db_name, sql_cmd);

    DB_D.read_db(db_name, "select balance from vendor where uid='A9D9113YR003'",
        function(ret, result_list)
            if (result_list and result_list[1]) then
                local balance = to_int(tonumber(result_list[1].balance));
                if (balance < BALANCE_LIMIT) then
                    balance_status = -1;
                else
                    balance_status = 0;
                end
            end
        end)
end

function set_enabled(flag)
    balance_enabled = flag;
end

function set_balance_limit(value)
    BALANCE_LIMIT = value;
end

function set_rate(value)
    RATE = value;
end

function destruct()
    if timer_id ~= -1 then
        delete_timer(timer_id);
        timer_id = -1;
    end
end

-- 模块的入口执行
function create()

    -- 加载子模块
    timer_id = set_timer(60000, timer_handle, nil, true)

    cur_date = os.date("!%Y%m%d", os.time() + 28800);

    -- 注册接受回调
    MONITOR_D.register_recv_callback(recv);
end

create();
