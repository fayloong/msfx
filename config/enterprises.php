<?php
/**
 * 企业（申报主体）配置——**结构部分，不含任何凭据与平台 ID**（本文件入 git）
 *
 * 分两文件，凭据绝不入仓：
 *   本文件                        企业名 / 类型 / 凭据位（label；每家企业恰一套，见 docs/adr/0012）
 *   config/enterprises.local.php  平台 ID 列表 + 凭据四字段明文（.gitignore，见 enterprises.example.php 模板）
 *
 * 企业名（name）= 数据库 upload_tasks.company / upload_logs.company 列的值，也是页面"所属企业"的显示值与筛选键。
 * 必须与源库 `zsm_ls.oper_ic_name` **一字不差**（含全角括号）：它同时是 ID 认领失败时（ID 为空的行）的回退匹配键。
 *
 * 门店清单（15 家）与平台 ID 来自 2026-09-29 对 dyt.msfx.dbo.zsm_ls 的只读探测，
 * 由 `bill_type IN (104,203)` 的「oper_ic_name ↔ to_user_id」对照直接得出——
 * 详见 .scratch/retail-chain/probe-findings-2026-09-29.md。
 *
 * 拿齐某门店的 AppKey/SECRETKEY 后，只需在 config/enterprises.local.php 补该 key 的凭据四字段，
 * 本文件不用改（凭据位已预声明）。
 */
return [
    'companies' => [
        // ---------- 批发（河药）：凭据仍从 .env 取，见 enterprises.local.php 顶部说明 ----------
        [
            'key' => 'heyao',
            'name' => '河药医药（河源）有限公司',
            'type' => 'wholesale',
            'credentials' => [
                'main' => ['label' => '主主体', 'primary' => true],
            ],
        ],

        // ---------- 零售连锁（大源堂）：15 家门店，按 104/203 单量降序 ----------
        [
            'key' => 'dyt-baoyuan',
            'name' => '大源堂智慧药房（河源）有限公司宝源店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-puqianlixin',
            'name' => '大源堂智慧药房（河源）有限公司埔前立信分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-xinjiang',
            'name' => '大源堂智慧药房（河源）有限公司新江分店',
            'type' => 'retail',
            // 该店两套平台 ID 都在源库出现（旧 entId 用到 2025-07、新 refEntId 自 2024-03 起），
            // 两个值都登记在 local 的 ids 里；凭据只保留现行一套
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-hongkangtang',
            'name' => '大源堂智慧药房（河源）有限公司鸿康堂分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-xudong',
            'name' => '大源堂智慧药房（河源）有限公司徐洞分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-dahu',
            'name' => '大源堂智慧药房（河源）有限公司大湖分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-yajule',
            'name' => '大源堂智慧药房（河源）有限公司雅居乐分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-lijiang',
            'name' => '大源堂智慧药房（河源）有限公司郦江分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-xincheng',
            'name' => '大源堂智慧药房（河源）有限公司新城分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-baitian',
            'name' => '大源堂智慧药房（河源）有限公司白田分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-huadajie',
            'name' => '大源堂智慧药房（河源）有限公司华达街分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-dengtazhongxin',
            'name' => '大源堂智慧药房（河源）有限公司灯塔中心分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-kanghuai',
            'name' => '大源堂智慧药房（河源）有限公司康怀分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-yuexiangwan',
            'name' => '大源堂智慧药房（河源）有限公司越祥湾分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
        [
            'key' => 'dyt-jinlin',
            'name' => '大源堂智慧药房（河源）有限公司金麟分店',
            'type' => 'retail',
            'credentials' => ['main' => ['label' => '主授权']],
        ],
    ],
];
