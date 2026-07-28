# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 项目概述

药品追溯码上传系统（码上放心平台对接），用于河药将 ERP 系统中的出入库单上传至阿里健康 "码上放心" 平台。纯过程式 PHP 项目，无框架。

## 项目架构

```
root/
├── top_sdk/          # 阿里健康 TOP SDK，不可修改
│   ├── TopSdk.php    # SDK 入口，定义 TOP_SDK_WORK_DIR、TOP_SDK_DEV_MODE 常量
│   ├── Autoloader.php
│   └── top/
│       ├── TopClient.php        # HTTP 客户端（cURL + MD5 签名）
│       ├── request/*.php        # API 请求类（拼装参数 + check()）
│       └── domain/*.php         # 返回结果的 DTO
├── src/               # 项目自建类
│   └── SqlSrvHelper.php        # SQL Server 数据库操作封装
├── config/
│   └── .env                    # 数据库连接 + API 凭证
├── public/
│   └── index.php               # Web 入口（当前为空）
├── scripts/
│   └── cron_handle.php         # 定时任务入口（当前为空）
├── upload_test.php             # 核心：上传出入库单到码上放心
├── get_ent_list_test.php       # 同步往来单位列表到本地库
└── bill_info_test.php          # 查询单据详情
```

## 核心数据流

1. **上传出入库单**（`upload_test.php`）：从 SQL Server `msfx_up_task` 表读取待上传单据 → 根据 `type` 字段映射为阿里健康编码（201=销售出库, 102=采购入库, 103=销售退回, 202=采购退出）→ 按业务类型设置发货/收货/配送企业 entId → 调用 `AlibabaAlihealthDrugKytUploadinoutbillRequest` API → 结果写回 `resp` 字段

2. **往来单位同步**（`get_ent_list_test.php`）：分页调用 `AlibabaAlihealthDrugKytListpartsRequest` API → 获取企业 ent_id/ref_ent_id → 写入本地 `ent_list` 表缓存

3. **API 调用链**：`TopClient->execute($request)` → 组装系统参数 + 业务参数 → MD5 签名 → cURL POST 到 `gw.api.taobao.com/router/rest` → 返回 XML 解析为对象

## 关键依赖

- `db.php` 文件（不在仓库内，位于 PHP include_path），提供：
  - `info_log($title, $msg, $level, $data)` — 日志函数
  - `hht($sql, $params)` — 执行 INSERT/UPDATE
  - `hht($sql, $params)` 的查询版本 — 返回结果数组
  - `appkey_hyyy`, `secretKey_hyyy`, `ent_id_hyyy`, `RefEntId_hyyy` — 常量
- PHP 扩展：`sqlsrv`（SQL Server）、`curl`
- 运行环境：PHP + Nginx + SQL Server

## 常用命令

```bash
# 命令行执行上传任务
php /usr/share/nginx/mashangfangxin/upload_test.php

# 同步往来单位
php /usr/share/nginx/mashangfangxin/get_ent_list_test.php

# 查询单据信息
php /usr/share/nginx/mashangfangxin/bill_info_test.php
```

## 业务编码映射

- 单据类型：`XSO`=201 销售出库, `XST`=103 退货入库, `JHG`=102 采购入库, `JHO`=202 采购退出
- 药品类型：`3`=普药（非89开头追溯码）, `2`=特药（89开头追溯码）
- 客户端类型：上传接口必须填 `"2"`

## Agent skills

### Issue tracker

本地 markdown 文件，存储在 `.scratch/<feature-slug>/` 下。详见 `docs/agents/issue-tracker.md`。

### Triage labels

使用默认五个标准 triage 标签。详见 `docs/agents/triage-labels.md`。

### Domain docs

单上下文布局：根目录 `CONTEXT.md` + `docs/adr/`。详见 `docs/agents/domain.md`。
