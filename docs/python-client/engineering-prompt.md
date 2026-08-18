# 药品追溯码上传客户端(桌面版) — 工程提示词

> 本文件是一份**项目级工程提示词**,用于让 AI 编码工具(如 Claude Code)在空目录中从零搭建一个 Windows 桌面客户端软件。
> 用法:把本文件作为首个指令交给 AI,要求它在目标空目录中按"开发阶段与里程碑"逐阶段实现,每阶段完成后人工验证再进入下一阶段。
> 参考实现:现有 PHP 版系统位于 `/usr/share/nginx/mashangfangxin`,业务规则与表结构以它为准,可随时对照阅读。

---

## 1. 项目概述

开发一个 **Windows 桌面客户端软件**(单机版),用于药品追溯码上传至阿里健康"码上放心"平台。业务场景:药企操作员将 ERP 系统中的出入库单据及追溯码上传至码上放心平台。

**产品定位**:
- 交付给客户的软件,不是内部 Web 网站。
- 单机部署:每台操作员电脑独立安装一份,各自连接 ERP 数据库与码上放心 API,数据存本机。
- 首个子客户为河药(现 PHP 系统已在生产运行),Python 版上线后替换 PHP 版;同时作为可交付产品复制给其他药企。
- 无登录、无授权、无多用户并发;更新靠安装包覆盖安装(数据独立于程序目录)。

## 2. 技术栈

| 项 | 选型 | 说明 |
|----|------|------|
| 语言 | Python 3.11+ | |
| GUI | PySide6 | LGPL,可闭源商用;表格用 Model/View 架构 |
| SQL Server 连接 | pymssql | 基于 FreeTDS,客户端机器零依赖(不用 pyodbc,避免装 ODBC 驱动) |
| 本地数据库 | SQLite(sqlite3 标准库) | 零配置,单文件 |
| HTTP | requests | 实现 TOP 协议,不依赖第三方 SDK |
| Excel 读写 | openpyxl | xlsx 导入/导出/模板下载 |
| 打包 | PyInstaller + Inno Setup | 生成正式安装包 setup.exe |
| 日志 | logging(标准库) | JSONL 格式,同现有 PHP 版 |

## 3. 目录结构

```
client/                          # 源码根目录(新建)
├── main.py                      # 入口:启动引导(单实例锁 → 配置检查 → 主窗口/托盘)
├── requirements.txt
├── app/
│   ├── __init__.py
│   ├── config.py                # 配置读写(%APPDATA%\码上放心上传工具\config.json)
│   ├── database.py              # SQLite 封装(连接、迁移、备份)
│   ├── logger.py                # JSONL 日志写入
│   ├── top_client.py            # TOP API 协议层(签名/请求/解析,纯函数,可单测)
│   ├── api_client.py            # 业务 API 封装(上传/查询/搜索,区分网络/业务错误)
│   ├── bill_type.py             # 单据类型码归一化(照搬 PHP 版 App\BillType)
│   ├── task_fetcher.py          # 从 SQL Server 采集单据(可配置模式,未配置 SQL Server 时禁用)
│   ├── upload_service.py        # 核心上传逻辑(拆分/重试/限速/状态流转)
│   ├── check_service.py         # 批量查询上传状态(新鲜度门卫)
│   ├── xlsx_service.py          # xlsx 导入解析/导出/模板生成
│   └── migration.py             # 旧库迁移(导入 PHP 版 msfx.db)
├── ui/
│   ├── __init__.py
│   ├── main_window.py           # 主窗口:左侧导航 + 内容区
│   ├── dashboard_page.py        # 首页仪表盘
│   ├── tasks_page.py            # 上传任务管理页
│   ├── uploaded_page.py         # 已上传记录页
│   ├── failed_page.py           # 失败记录页
│   ├── manual_page.py           # 手动上传页(表单 + xlsx 导入 + 模板下载)
│   ├── settings_page.py         # 设置页(SQL Server / API 凭证 / 备份 / 迁移)
│   ├── wizard.py                # 首次启动配置向导
│   ├── tray.py                  # 系统托盘 + 气泡通知
│   └── widgets/                 # 复用控件(筛选表格、分页条、日期范围选择等)
├── worker/
│   ├── __init__.py
│   ├── scheduler.py             # 后台任务调度(定时器驱动)
│   ├── upload_worker.py         # 上传任务线程
│   └── check_worker.py          # 状态查询线程
├── packaging/
│   ├── build.spec               # PyInstaller 配置
│   └── installer.iss            # Inno Setup 脚本
└── tests/                       # 单元测试(TOP 签名、拆分、BillType、导入解析)
```

## 4. 数据模型(SQLite)

数据库文件:`%APPDATA%\码上放心上传工具\msfx.db`。结构与 PHP 版完全一致(方便旧库迁移)。

### upload_tasks(上传任务)
| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | |
| rq | TEXT | 单据日期 |
| djbh | TEXT | 单号 |
| ent_name | TEXT | 往来单位名称 |
| trace_codes | TEXT | 追溯码(逗号分隔) |
| task_status | TEXT | 等待上传/已处理 |
| source | TEXT | cron/manual/batch_check/batch_retry |
| bill_type | TEXT | 3 位数字类型码 |
| request_status | TEXT | 请求成功/请求失败 |
| response_status | TEXT | 上传成功/单据重复/上传失败/信息不存在/往来单位缺失/未确定 |
| resp | TEXT | API 返回内容 |
| created_at / updated_at / last_checked_at | TEXT | |

### upload_logs(上传日志)
同 upload_tasks 的字段集,`task_id INTEGER`(0 表示无关联),多一个 `response` 字段存 API 返回。

### ent_list(往来单位缓存)
`id / ent_name(TEXT UNIQUE) / ent_id / ref_ent_id / created_at`

## 5. 配置文件

`%APPDATA%\码上放心上传工具\config.json`,JSON 格式,包含:

- `sql_server`:启用开关 + host / port / database / username / password(可选,未启用则软件退化为纯手动 + xlsx 导入模式)
- `api`:appkey / secret(码上放心凭证,**每家客户填自己的**,首次启动向导引导填写,设置页可改)
- `scheduler`:采集/上传/查状态/备份的时间配置
- `backup_dir`:备份目录

## 6. 业务规则(必须与 PHP 版一致)

- **单据类型映射**(出入库):
  - 入库 1xx:`102`=采购入库, `103`=退货入库, `104`=调拨入库, `107`=供应入库, `108`=召回入库, `110`=赠品入库, `111`=盘盈入库, `112`=报废入库, `113`=其他入库
  - 出库 2xx:`201`=销售出库, `202`=退货出库, `203`=调拨出库, `204`=返工出库, `205`=销毁出库, `206`=抽检出库, `207`=直调出库, `209`=供应出库, `211`=召回出库, `212`=赠品出库, `214`=盘亏出库, `215`=损坏出库, `216`=报废出库, `217`=其他出库, `237`=直调退货
- **单号前缀映射**(兼容旧格式):`XSO`→201, `XST`→103, `JHG`→102, `JHO`→202;归一化逻辑照搬 PHP 版 `App\BillType::normalize`:已是 3 位数字直接返回 → 前缀命中映射表返回 → bill_type 缺失时按单号前 3 位推导 → 均无法识别返回空串。
- **药品类型**:追溯码以 `89` 开头为特药(类型 `2`),否则普药(类型 `3`)。
- **客户端类型**:上传接口必须填 `"2"`。
- **追溯码拆分**:单次最多 3500 个,超出自动拆分为 `单号_1, 单号_2...`,逐批上传。
- **API 重试**:最多 3 次,间隔 30s,**仅网络错误重试**,业务错误不重试。
- **API 限速**:每次调用间隔 330ms。
- **状态查询新鲜度门卫**:`last_checked_at` 距上次成功查询不足 30 分钟的单据不拉出;API 查询成功(含"信息不存在")与"已确认在平台跳过"时 touch;仅 API 异常不 touch,下次自动重查。新任务 last_checked_at 为 NULL 天然立即查。
- **状态查询双源合并**:upload_tasks(等待上传) + upload_logs(response_status != 上传成功) → 按 djbh 去重 → 逐单查询;已确认在平台(已有上传成功/单据重复记录)时:任务标记"已处理",日志记录不动,不调 API。
- **日志链**:API 响应实时写入 JSONL 文件(`%APPDATA%\码上放心上传工具\logs\api_YYYY-MM-DD.jsonl`,永久保存)+ 同步写 SQLite upload_logs(查询用);每日自动清理 3 个月前日志与 3 个月前已处理的 upload_tasks(按 updated_at 判断)。
- **SQL Server 采集**(仅启用时):当天单据按 djbh 去重(跳过已存在任务与已成功/重复单据)写入 upload_tasks(source=cron)。

## 7. TOP API 协议对接

- 网关:`http://gw.api.taobao.com/router/rest`,格式 XML,apiVersion `2.0`。
- **签名算法**:参数 ksort 排序 → 拼接 `secret + k1v1 + k2v2 + ... + secret` → `strtoupper(md5(...))`。
- 支持文件上传(multipart)。
- 业务接口:`uploadBill`(上传)、`queryBill`(查询详情)、`searchBill`(搜索)等,与 PHP 版 `src/ApiClient.php` 一一对应;需区分网络错误(超时/连接失败,可重试)与业务错误(平台返回错误码,不重试)。
- **不要使用第三方 SDK**,协议层 100~200 行,requests 实现即可。

## 8. UI 设计

- **主窗口**:左侧导航菜单(首页/上传任务/已上传记录/失败记录/手动上传/设置)+ 右侧内容区,与 PHP 版 Web 界面同构,操作员零学习成本。
- **表格页**(任务/已上传/失败):筛选行(单号、往来单位、状态、单据日期 rq、创建时间 created_at)+ 表格 + 分页。日期范围选择器默认最近 7 天(含当天);**关键词检索不受默认日期范围限制**:关键词输入时若日期选择器仍是默认 7 天(未手动改过),不传日期参数全库检索;手动改过日期则正常组合过滤。
- **首页仪表盘**:4 个统计卡片(待上传数/今日上传/今日失败/近 7 天上传),外加最近一次后台任务结果。
- **手动上传页**:单据类型下拉(必选)→ 日期/单号/往来单位 → 追溯码输入(一行一个自动转逗号分隔)→ 提交立即上传,结果实时反馈;xlsx 导入(支持拖拽文件进窗口);模板下载按钮。
- **设置页**:SQL Server 连接配置、API 凭证、后台任务时间、备份目录、立即备份、导入旧库(迁移)。
- **首次启动向导**:第一步填 API 凭证,第二步选数据源模式(SQL Server 或纯手动),第三步完成。可跳过,之后在设置页补配。
- **系统托盘**:常驻图标,点击恢复/最小化主窗口;气泡通知上传结果("上传成功 N 单,失败 M 单")。
- 交互增强:记录页筛选结果一键导出 xlsx;表格右键菜单(重传/删除)。

## 9. 后台任务(内置调度器,不依赖系统 cron)

| 任务 | 默认频率 | 说明 |
|------|---------|------|
| 采集(SQL Server 模式) | 每 10 分钟 | 拉取当天单据进队列,失败静默重试 |
| 批量上传 | 每 30 秒 | 队列中有等待上传的任务即执行;API 限速 330ms/次 |
| 批量查状态 | 每 5 分钟(8:00-20:00) | 新鲜度门卫 30 分钟 |
| 日志清理 | 每天 03:00 | 清 3 个月前数据 |
| 数据库备份 | 每天 02:00 | 复制 msfx.db 到备份目录,保留最近 30 份 |

- 所有后台任务在独立线程运行,严禁阻塞 GUI 线程;结果通过信号回传 UI。
- 软件开机自启(安装时注册,托盘菜单可开关),最小化到托盘继续运行。
- 单实例锁:同时只允许一个实例运行。

## 10. 打包与安装

- PyInstaller(build.spec)打 Windows 目录包,包含 PySide6 / pymssql / openpyxl / requests 及依赖。
- Inno Setup(installer.iss)生成 setup.exe:安装向导、桌面快捷方式、开机自启注册、卸载程序;数据目录 `%APPDATA%\码上放心上传工具` 在卸载时**保留**(不删用户数据)。
- 升级方式:新安装包覆盖安装,数据不受影响。

## 11. 开发阶段与里程碑

按顺序执行,**每个里程碑结束必须人工验证(运行/点击/打包测试)后再进入下一个**:

1. **M1 骨架与基础设施**:目录结构、config、database、logger、单实例锁、requirements;验证:程序可启动,创建 db 与配置文件。
2. **M2 协议与业务层**:top_client(签名+请求+解析,写单元测试)、api_client、bill_type、upload_service(拆分/重试/限速/状态流转);验证:用测试凭证调通一个 API,单元测试全绿。
3. **M3 后台任务**:scheduler、upload_worker、check_worker、日志清理、备份;验证:模拟任务跑通采集→上传→查状态闭环。
4. **M4 UI 主框架**:main_window 侧边导航、六个页面骨架、托盘;验证:界面可浏览、导航切换正常。
5. **M5 UI 功能**:表格页筛选/分页/导出、手动上传表单、xlsx 导入/导出/模板、设置页、首次向导;验证:手动传一单真实单据成功,筛选/导出正确。
6. **M6 集成与迁移**:旧库迁移(migration)、SQL Server 采集模式、与 PHP 版并行对照验证(同一单据两边上传结果一致)。
7. **M7 打包交付**:PyInstaller + Inno Setup 产出 setup.exe,在干净 Windows 环境(或虚拟机)安装、启动、上传全流程走通。

## 12. 验收清单

- [ ] 安装包在干净 Windows 上安装→启动→配置向导→上传成功
- [ ] 手动上传(xlsx 导入 + 在线表单)与 PHP 版结果一致
- [ ] SQL Server 模式采集、上传、查状态闭环;未配置时软件正常降级
- [ ] 追溯码 >3500 自动拆分,重试仅网络错误,限速 330ms
- [ ] 托盘通知、开机自启、单实例
- [ ] 每日自动备份 + 手动立即备份可用
- [ ] 旧库迁移后历史记录可查
- [ ] 卸载程序后 %APPDATA% 数据保留
- [ ] 单元测试覆盖:TOP 签名、BillType 归一化、追溯码拆分、xlsx 解析、去重逻辑

## 13. 明确不做的事

- 不做登录认证(单机软件)
- 不做授权/激活码(安装包直发)
- 不做声音提醒
- 不做自动静默更新(安装包手动覆盖升级)
- 不做多租户/服务端(纯单机)
