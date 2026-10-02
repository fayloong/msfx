# 04: 计数门卫

**What to build:** 采集开跑前先数三个数（当日单据总数 / 已上传 / 未上传），与上次基线一致就跳过整轮并在日志里写明原因。交付给运维：日志从此能区分"今天真没新单"与"脚本压根没跑"，源库没事时也不再白跑本地那一套（去重、认领、写库）。

**Blocked by:** 02（改的是同一个采集脚本的流程）

**Status:** ready-for-agent

- [ ] 一条 SQL 取三个数，形状是「状态表去重成派生表 + `LEFT JOIN` + `count()`」：

  ```sql
  select count(*) as total, count(a.bill_code) as uploaded,
         count(*) - count(a.bill_code) as unuploaded
    from <单据头> ls
    left join (select distinct bill_code from <状态表>) a on a.bill_code = ls.bill_code
   where ... and ls.bill_time = ?
  ```

  （去重派生表不能省；**聚合里套子查询在 SQL Server 上直接报错**——实测踩过）
- [ ] 独立基线文件（日期 + 三个数），**不与批发采集的计数基线共用**（两个脚本共写一个文件会互相踩）
- [ ] 三个数都没变 → 打印原因并正常退出（退出码 0）
- [ ] 基线**只在整轮采集成功后**写：落库中途失败、源库读取失败都不更新
- [ ] 基线文件缺失 / 损坏 / 日期不符 → 视为无基线，照常采集
- [ ] `--all` 与 `--dry-run` 绕过门卫
- [ ] 自包含测试：新增 / 变化 / 未变 三种判定 + 损坏基线 + **异常路径不更新基线**（把"成功后写基线"去掉必须变红）
- [ ] 实测：连跑两次，第二次跳过并打印原因；动一下基线后能重新采；既有测试全部跑通
- [ ] CLAUDE.md 的 cron 时间表与"零售无计数门卫"的表述同步更新
