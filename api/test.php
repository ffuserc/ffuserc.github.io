<?php
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// 新增：直接访问（GET请求）时显示提示文字
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Content-Type: text/html; charset=utf-8");
    echo "当前为卡密验证接口调用api，不可直接访问，by.冰西奶茶，QQ［3650495635］";
    exit;
}

// 移除所有AES加密解密相关代码
define('CARD_FILE', __DIR__ . '/cards.json'); // 卡密存储文件

// 初始化卡密文件
if (!file_exists(CARD_FILE)) {
    file_put_contents(CARD_FILE, json_encode([]));
    chmod(CARD_FILE, 0644);
}

// 读取卡密数据
$cards = json_decode(file_get_contents(CARD_FILE), true);
if ($cards === null) $cards = [];

// 读取POST请求
$postRaw = file_get_contents('php://input');
$postData = json_decode($postRaw, true);
if ($postData === null) {
    echo json_encode(['code' => 1, 'msg' => '请求格式错误（需JSON）']);
    exit;
}

$action = $postData['action'] ?? '';


// 卡密验证分支（纯明文处理，无解密）
if ($action === 'verify') {
    $card = $postData['card'] ?? '';
    if (empty($card)) {
        echo json_encode(['code' => 1, 'msg' => '卡密不能为空']);
        exit;
    }

    // 直接用明文卡密查询，无解密步骤
    if (!isset($cards[$card])) {
        echo json_encode(['code' => 1, 'msg' => '卡密不存在']);
        exit;
    }

    $cardInfo = $cards[$card];
    // 状态校验
    if (in_array($cardInfo['status'], ['封禁', '冻结', '已过期'])) {
        $msgMap = [
            '封禁' => '卡密已被封禁',
            '冻结' => '卡密已被冻结',
            '已过期' => '卡密已过期'
        ];
        echo json_encode(['code' => 1, 'msg' => $msgMap[$cardInfo['status']]]);
        exit;
    }

    // 激活未使用的卡密
    if ($cardInfo['status'] === '未使用') {
        $cards[$card]['status'] = '已使用';
        $cards[$card]['useTime'] = time();
        $cards[$card]['ip'] = $_SERVER['REMOTE_ADDR'];
        $cards[$card]['devices'] = [['ip' => $_SERVER['REMOTE_ADDR'], 'status' => '正常']];
        file_put_contents(CARD_FILE, json_encode($cards, JSON_UNESCAPED_UNICODE));
    }

    // 到期时间校验
    $currentTime = time();
    if ($cardInfo['type'] !== '永久卡' && $currentTime > $cardInfo['expireTime']) {
        $cards[$card]['status'] = '已过期';
        file_put_contents(CARD_FILE, json_encode($cards, JSON_UNESCAPED_UNICODE));
        echo json_encode(['code' => 1, 'msg' => '卡密已过期']);
        exit;
    }

    // 返回成功结果
    echo json_encode([
        'code' => 0,
        'msg' => '验证成功',
        'expireTime' => date('Y-m-d H:i:s', $cardInfo['expireTime']),
        'cardInfo' => $cardInfo
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// 生成卡密分支（保持原逻辑，明文存储）
if ($action === 'generate') {
    $adminPwd = $postData['adminPwd'] ?? '';
    if ($adminPwd !== 'amdfgh') {
        echo json_encode(['code' => 1, 'msg' => '管理员密码错误']);
        exit;
    }

    $type = $postData['type'] ?? '';
    $length = (int)($postData['length'] ?? 16);
    $struct = $postData['struct'] ?? '大写+数字';
    $count = (int)($postData['count'] ?? 1);
    $deviceLimit = (int)($postData['deviceLimit'] ?? 0);
    $customTime = (int)($postData['customTime'] ?? 3600);

    $typeMap = [
        '小时卡' => 3600,
        '天卡' => 86400,
        '周卡' => 604800,
        '月卡' => 2592000,
        '年卡' => 31536000,
        '永久卡' => 0,
        '次数卡' => $customTime
    ];
    if (!isset($typeMap[$type])) {
        echo json_encode(['code' => 1, 'msg' => '卡密类型无效']);
        exit;
    }

    $cardList = [];
    for ($i = 0; $i < $count; $i++) {
        $card = generateCard($length, $struct);
        while (isset($cards[$card])) {
            $card = generateCard($length, $struct);
        }
        $expireTime = $type === '永久卡' ? 9999999999 : (time() + $typeMap[$type]);
        $cards[$card] = [
            'card' => $card,
            'type' => $type,
            'struct' => $struct,
            'deviceLimit' => $deviceLimit,
            'status' => '未使用',
            'createTime' => time(),
            'useTime' => 0,
            'expireTime' => $expireTime,
            'ip' => '',
            'devices' => []
        ];
        $cardList[] = $card;
    }

    file_put_contents(CARD_FILE, json_encode($cards, JSON_UNESCAPED_UNICODE));
    echo json_encode([
        'code' => 0,
        'msg' => '卡密生成成功',
        'cards' => $cardList
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// 卡密管理分支（保持原逻辑）
if ($action === 'manage') {
    $adminPwd = $postData['adminPwd'] ?? '';
    if ($adminPwd !== 'adminIceXixi') {
        echo json_encode(['code' => 1, 'msg' => '管理员密码错误']);
        exit;
    }

    $card = $postData['card'] ?? '';
    $operate = $postData['operate'] ?? '';

    if (!isset($cards[$card])) {
        echo json_encode(['code' => 1, 'msg' => '卡密不存在']);
        exit;
    }

    switch ($operate) {
        case 'ban':
            $cards[$card]['status'] = '封禁';
            $msg = '卡密已封禁';
            break;
        case 'unban':
            $cards[$card]['status'] = $cards[$card]['useTime'] > 0 ? '已使用' : '未使用';
            $msg = '卡密已解封';
            break;
        case 'freeze':
            $cards[$card]['status'] = '冻结';
            $msg = '卡密已冻结';
            break;
        case 'unfreeze':
            $cards[$card]['status'] = $cards[$card]['useTime'] > 0 ? '已使用' : '未使用';
            $msg = '卡密已解冻';
            break;
        case 'delete':
            unset($cards[$card]);
            $msg = '卡密已删除';
            break;
        case 'deleteExpired':
            foreach ($cards as $k => $v) {
                if ($v['status'] === '已过期') unset($cards[$k]);
            }
            $msg = '已删除所有过期卡密';
            break;
        default:
            echo json_encode(['code' => 1, 'msg' => '操作类型无效']);
            exit;
    }

    file_put_contents(CARD_FILE, json_encode($cards, JSON_UNESCAPED_UNICODE));
    echo json_encode(['code' => 0, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}


// 获取卡密列表分支
if ($action === 'getList') {
    $adminPwd = $postData['adminPwd'] ?? '';
    if ($adminPwd !== 'adminIceXixi') {
        echo json_encode(['code' => 1, 'msg' => '管理员密码错误']);
        exit;
    }
    echo json_encode(['code' => 0, 'data' => $cards], JSON_UNESCAPED_UNICODE);
    exit;
}


// 设备管理分支
if ($action === 'device_manage') {
    $adminPwd = $postData['adminPwd'] ?? '';
    if ($adminPwd !== 'amdfgh') {
        echo json_encode(['code' => 1, 'msg' => '管理员密码错误']);
        exit;
    }

    $card = $postData['card'] ?? '';
    $ip = $postData['ip'] ?? '';
    $operate = $postData['operate'] ?? '';

    if (!isset($cards[$card])) {
        echo json_encode(['code' => 1, 'msg' => '卡密不存在']);
        exit;
    }

    $deviceIndex = -1;
    foreach ($cards[$card]['devices'] as $i => $dev) {
        if ($dev['ip'] === $ip) $deviceIndex = $i;
    }
    if ($deviceIndex === -1) {
        echo json_encode(['code' => 1, 'msg' => 'IP设备不存在']);
        exit;
    }

    switch ($operate) {
        case 'ban_ip':
            $cards[$card]['devices'][$deviceIndex]['status'] = '封禁';
            $msg = 'IP已封禁';
            break;
        case 'freeze_ip':
            $cards[$card]['devices'][$deviceIndex]['status'] = '冻结';
            $msg = 'IP已冻结';
            break;
        case 'unban_ip':
            $cards[$card]['devices'][$deviceIndex]['status'] = '正常';
            $msg = 'IP已解封';
            break;
        default:
            echo json_encode(['code' => 1, 'msg' => '操作无效']);
            exit;
    }

    file_put_contents(CARD_FILE, json_encode($cards, JSON_UNESCAPED_UNICODE));
    echo json_encode(['code' => 0, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}


echo json_encode(['code' => 1, 'msg' => '无效操作']);
exit;


// 生成卡密函数（保持原逻辑）
function generateCard($length, $struct) {
    $charSets = [
        '大写+数字' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        '大小写+数字' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
        '小写+数字' => 'abcdefghijklmnopqrstuvwxyz0123456789',
        '大小写字母' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz',
        '纯数字' => '0123456789'
    ];
    $chars = $charSets[$struct] ?? $charSets['大写+数字'];
    $card = '';
    for ($i = 0; $i < $length; $i++) {
        $card .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $card;
}
?>
