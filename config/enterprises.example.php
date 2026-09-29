<?php
/**
 * config/enterprises.local.php 的**模板**（本文件入 git，只有占位符）
 *
 * 部署时：复制为 config/enterprises.local.php 并填入真实取值。
 * 该文件已在 .gitignore 中（/config/enterprises.local.php），绝不要提交它。
 *
 * 说明：
 *   - 企业名/类型/凭据位（label、primary）在 config/enterprises.php，本文件只补取值
 *   - ids[]：该企业全部平台 ID，用于采集时认领单据（可含历史 ID 与源库错值；同一 ID 不可属于两家企业）
 *   - 凭据四字段必须同时填齐；四项全空 = "待配凭据"（合法，页面对该企业禁用补传）
 *   - 凭据的 ref_ent_id / ent_id 必须出现在该企业的 ids 里
 */
return [
    'ids' => [
        'heyao' => ['<河药 refEntId>', '<河药 entId>'],
        'dyt-baoyuan' => ['<门店平台 ID>'],
        'dyt-xinjiang' => ['<现行 ID>', '<历史 ID>'],
        // …其余门店同理，key 与 config/enterprises.php 一致
    ],

    'credentials' => [
        'heyao' => [
            'main' => [
                'appkey' => '<AppKey>',
                'secretkey' => '<SecretKey>',
                'ref_ent_id' => '<refEntId>',
                'ent_id' => '<entId>',
            ],
        ],
        'dyt-baoyuan' => [
            'main' => [
                // 留空四字段即表示"还没拿到这家店的授权"
                'appkey' => '',
                'secretkey' => '',
                'ref_ent_id' => '',
                'ent_id' => '',
            ],
        ],
    ],
];
