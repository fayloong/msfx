<?php
/**
 * App\Config::sqlServer() 单元测试（自包含断言脚本，无框架依赖）
 *
 * 运行: php tests/config_test.php
 *
 * 测试目标: SQL Server 连接配置的**单一来源**——四个连接点
 *   （TaskFetcher / UpdateStateWriter / fetch_bills_retail / backfill_rq）共用这一个方法。
 *
 * 钉的是"会真的写错"的那几处，而不是"四处取到的配置一致"（收敛后那是构造上的性质，
 * 四处都调同一个方法，测它等于测 PHP 的赋值语句）：
 *   - 键名拼错一个，`Config::get()` 会静默走兜底默认值（而生产 .env 的值与默认值多数相同，
 *     看一眼根本发现不了）→ 用例 4 拿 `Config::get()` 对账
 *   - `timeout` 混进这一层 → 用例 1 的键集合是**恰好**比较
 *   - 方法内有没有真的确保已 load → **用例 3**，全脚本唯一有辨别力的那条：
 *     生产 .env 的 server/port/database/username 与硬编码默认值逐字相同，唯有 password
 *     默认 '' 、.env 里是真实密码。故**用例 3 必须第一个跑**——本进程一旦调用过任何
 *     触发 load 的方法，它就失去辨别力（脚本顺序即为此设，别重排）
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Config;

$failures = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures;
    if ($cond) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name  $detail\n";
    }
}

// 本进程第一次碰配置：刻意**不先**调 Config::load()——用例 3 全靠这一点
$config = Config::sqlServer();

$envPath = __DIR__ . '/../config/.env';

// ---------- 用例 3（必须最先判定）: 未显式 load() 也能拿到 .env 的真值 ----------
if (!file_exists($envPath)) {
    echo "SKIP  用例3: 未显式 load() 时 password 非空（config/.env 不存在，无法判定）\n";
} else {
    check(
        '用例3: 未显式 load() 时 password 非空（load 强制生效）',
        $config['password'] !== '',
        'password 为空——sqlServer() 没有确保 load()，取到的是硬编码默认值'
    );
}

// ---------- 用例 1: 键集合恰为五字段（多一个 timeout 都算失败） ----------
$expectedKeys = ['server', 'port', 'database', 'username', 'password'];
$actualKeys = array_keys($config);
sort($expectedKeys);
sort($actualKeys);
check(
    '用例1: 键集合恰为五字段（不含 timeout 等连接点特性）',
    $expectedKeys === $actualKeys,
    '实际: ' . implode(',', $actualKeys)
);

// ---------- 用例 2: 五个值都是 string ----------
$nonString = [];
foreach ($config as $k => $v) {
    if (!is_string($v)) {
        $nonString[] = $k . ':' . gettype($v);
    }
}
check('用例2: 五个值都是 string', $nonString === [], implode(',', $nonString));

// ---------- 用例 4: 与 Config::get() 逐键一致（键名拼写的对账） ----------
// 对账用 get() 的**无默认值**形态：sqlServer() 里若把键名拼错（如 DB_DATABSE），
// 它取到的是自己的兜底默认值，而这里取到 ''，两者不等即暴露。
// .env 不在场时跳过：那时 sqlServer() 的兜底默认值（192.168.2.133 等）与 get() 的 ''
// 必然不等，红出来的形态与"键名拼错"一模一样——诊断会指向错误的方向。
if (!file_exists($envPath)) {
    echo "SKIP  用例4: 五字段与 Config::get() 逐键一致（config/.env 不存在，无法对账）\n";
} else {
    $pairs = [
        'server' => 'DB_SERVER',
        'port' => 'DB_PORT',
        'database' => 'DB_DATABASE',
        'username' => 'DB_USERNAME',
        'password' => 'DB_PASSWORD',
    ];
    $mismatch = [];
    foreach ($pairs as $field => $envKey) {
        if ($config[$field] !== Config::get($envKey)) {
            $mismatch[] = "{$field}({$config[$field]})≠{$envKey}(" . Config::get($envKey) . ')';
        }
    }
    check('用例4: 五字段与 Config::get() 逐键一致', $mismatch === [], implode(', ', $mismatch));
}

echo "\n";
if ($failures === 0) {
    echo "全部通过 ✓\n";
    exit(0);
}
echo "失败 $failures 项 ✗\n";
exit(1);
