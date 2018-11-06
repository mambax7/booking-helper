<?php
/*-----------引入檔案區--------------*/
$xoopsOption['template_main'] = "booking_helper_adm_main.tpl";
include_once "header.php";
include_once "../function.php";



/*-----------執行動作判斷區----------*/
include_once $GLOBALS['xoops']->path('/modules/system/include/functions.php');
$op = system_CleanVars($_REQUEST, 'op', '', 'string');
// $sn = system_CleanVars($_REQUEST, 'sn', 0, 'int');

switch ($op) {

    // case "xxx":
    // xxx();
    // header("location:{$_SERVER['PHP_SELF']}");
    // exit;

    case 'get_times_of_item':
        // 全部開放時段
        $data = getTimesByItem(trim($_GET['sn']));
        // 當日已預約時段 jbt_sn
        $used = getUsedTimes($data, trim($_GET['date']));
        // 附加是否可預約標記
        $data = array_map(function ($orig) use ($used) {
            $orig['can_order'] = !in_array($orig['jbt_sn'], $used);

            return $orig;
        }, $data);

        die(getJSONResponse($data));

    case 'create_orders':
        $data = $_POST;

        $result = createOrders($data);

        die(getJSONResponse(compact('result')));

    default:
        show_content();
        break;
}

include_once 'footer.php';

/*-----------function區--------------*/

//顯示預設頁面內容
function show_content()
{
    // 加入日期選擇器
    // https://campus-xoops.tn.edu.tw/modules/tad_book3/page.php?tbdsn=874
    // http://my97.net/demo/index.htm
    include_once XOOPS_ROOT_PATH . "/modules/tadtools/cal.php";
    $cal = new My97DatePicker();
    $cal->render();

    global $xoopsTpl;

    $items = getAllItems();

    $xoopsTpl->assign('items', $items);
}

// 進行預約
function createOrders($data) {
    global $xoopsDB, $xoopsUser;

    $jb_date = trim($data['jb_date']); // 日期
    // $jbi_sn = $data['jbi_sn']; // 場地 id
    $jbt_sn_array = $data['values']; // 時段 array
    // 進行預約之時間，取執行當下時間
    $orderAt = date_format(date_create(), 'Y-m-d H:i:s');
    $week = date('w', strtotime($jb_date));; // 1-6, 0 星期日
    $uid = $xoopsUser->uid();

    $event = trim($data['event']); // 理由
    $event = $event === '' ? '個人預約' : $event;




    // INSERT INTO `xx_jill_booking` (`jb_sn`, `jb_uid`, `jb_booking_time`, `jb_booking_content`, `jb_start_date`, `jb_end_date`) VALUES (1, 1, '2018-11-02 21:53:34', '個人預約', '2018-11-02', '2018-11-02');
    $sql = "INSERT INTO {$xoopsDB->prefix('jill_booking')} (`jb_uid`, `jb_booking_time`, `jb_booking_content`, `jb_start_date`, `jb_end_date`) VALUES ('{$uid}', '{$orderAt}', '{$event}', '{$jb_date}', '{$jb_date}')";
    $xoopsDB->query($sql) or web_error($sql);
    $jb_sn = $xoopsDB->getInsertId(); // 取得最新的預約單 id


    // INSERT INTO `xx_jill_booking_date` (`jb_sn`, `jb_date`, `jbt_sn`, `jb_waiting`, `jb_status`, `approver`, `pass_date`) VALUES (1, '2018-11-02', 12, 1, '1', 0, '0000-00-00');
    // $sql = "INSERT INTO {$xoopsDB->prefix('jill_booking_date')} (`jb_sn`, `jb_date`, `jbt_sn`, `jb_waiting`, `jb_status`, `approver`, `pass_date`) VALUES ('{$jb_sn}', '{$jb_date}', :jbt_sn, 1, '1', 0, '0000-00-00')";
    $sql = "INSERT INTO {$xoopsDB->prefix('jill_booking_date')} (`jb_sn`, `jb_date`, `jbt_sn`, `jb_waiting`, `jb_status`, `approver`, `pass_date`) VALUES ";

    $date_values = [];
    $week_values = [];
    foreach ($jbt_sn_array as $jbt_sn) {
        $date_values[] = "('{$jb_sn}', '{$jb_date}', '{$jbt_sn}', 1, '1', 0, '0000-00-00')";
        $week_values[] = "('{$jb_sn}', '{$week}', '{$jbt_sn}')";
    }

    $sql .= implode(',', $date_values);
    $xoopsDB->query($sql) or web_error($sql);

    // INSERT INTO `xx_jill_booking_week` (`jb_sn`, `jb_week`, `jbt_sn`) VALUES (1, 5,  12);
    $sql = "INSERT INTO {$xoopsDB->prefix('jill_booking_week')} (`jb_sn`, `jb_week`, `jbt_sn`) VALUES ";
    $sql .= implode(',', $week_values);
    $xoopsDB->query($sql) or web_error($sql);


    return true;
}



// 取得所有場地
function getAllItems() {
    global $xoopsDB;

    $sql = "SELECT 
                jbi_sn, jbi_title 
            FROM 
                {$xoopsDB->prefix('jill_booking_item')}";
    $result = $xoopsDB->query($sql);

    $data = [];
    while ($item = $xoopsDB->fetchArray($result)){
        $data[] = $item;
    };

    return $data;
}

// 取得某場地可用之時段
function getTimesByItem($jbi_sn) {
    global $xoopsDB;

    $sql = "SELECT 
                jbt_sn, jbi_sn, jbt_title, jbt_week
            FROM 
                {$xoopsDB->prefix('jill_booking_time')} 
            WHERE 
                jbi_sn = {$jbi_sn} 
            ORDER BY 
                jbt_sort ASC";
    $result = $xoopsDB->query($sql);

    $data = [];
    while ($item = $xoopsDB->fetchArray($result)){
        $data[] = $item;
    };

    return $data;
}

// 取得已預約之時段 jbt_sn
function getUsedTimes($times, $date) {
    global $xoopsDB;

    $targets = array_map(function($item){
        return $item['jbt_sn'];
    }, $times);
    $targets = implode(',', $targets);

    $sql = "SELECT
                `jbt_sn`
            FROM
                {$xoopsDB->prefix('jill_booking_date')}
            WHERE 
                `jb_date` = '{$date}' AND `jbt_sn` IN ({$targets})";

    $result = $xoopsDB->query($sql);

    $data = [];
    while ($item = $xoopsDB->fetchArray($result)){
        $data[] = $item['jbt_sn'];
    };
    
    return $data;
}

// 處理欲回傳之 json
function getJSONResponse($data) {
    return json_encode($data, JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
