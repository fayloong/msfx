# 16: 「所属企业」下拉把凭据未配齐的企业用字体标出来（全站 6 处）

- Type: task
- Status: done（2026-10-01；提交 `9f73176`、收口 `10e72a6`，gitee 与 origin 均已跟上）
  - ⚠️ **字体口径已被 17 票取代**（2026-10-01）：`ready` 改加粗黑字、`pending`/`no_slot` 改正常字体灰字，
    斜体不再使用。故本票「验收」第 4 项（"斜体在浏览器里显不显"）**作废**；其余交付**全部保留**
    （就绪态判据收口、`selectableOptions()` 改形、6 处覆盖、`STATE_*` 常量）。见本票 Comments
- Blocked by: 无
- 关联：`docs/adr/0012`（门店与凭据 1:1）、`src/Enterprise.php`（`retailCredentialReady()` / `selectableNames()`）、
  `src/views/layout.php`（全站视图共用的函数所在处）、`tests/enterprise_config_test.php`

## 背景：为什么"选中之后"才知道不能用，是不够的

15 家门店里**已有 5 家配齐 AppKey/SECRETKEY**，其余 10 家仍是待配凭据（spec 的"外部依赖"表）。
页面上的禁用与说明**都要先选中那家门店**才看得见（上传任务页的行内徽标、手动上传页的分支提示）——
而"所属企业"下拉里 17 个选项长得一模一样，**人是在选之前就不知道该选哪家的**。

用户要的：**下拉里就把没有 AppKey 的企业用不同字体标出来**，全站每一处"所属企业"下拉都改。

## 要交付的行为

按选项的**凭据就绪态**决定是否加样式类：

| 态 | 含义 | 下拉里的样子 |
|---|---|---|
| `ready` | 四字段填齐 | 原样 |
| `pending` | 凭据位在、四字段没填齐——**等密钥**（预期内的正常状态） | **斜体 + 灰字** |
| `no_slot` | 连凭据位都没在配置里声明——**配置缺口** | **斜体 + 灰字**（与 `pending` 同一档） |
| `unidentified` | `未识别`——**不是一个企业**，是认领失败那批的 `company` 取值 | 原样 |

**全站 6 处下拉一处不落**：

| # | 位置 | 选择器 |
|---|---|---|
| 1 | `views/upload_tasks.php` 筛选栏 | `#filter-company` |
| 2 | `views/upload_tasks.php` 编辑弹窗 | `#edit-company` |
| 3 | `views/uploaded.php` 筛选栏 | `#filter-company` |
| 4 | `views/failed.php` 筛选栏 | `#filter-company` |
| 5 | `views/failed.php` 编辑弹窗 | `#edit-company` |
| 6 | `views/manual_upload.php` 顶部 | `#company-select` |

## 决策

| 决策点 | 选择 | 理由 / 代价 |
|---|---|---|
| 哪些算"没有 AppKey" | `pending` **与** `no_slot` 都标 | 两者都传不出去（守卫都拒）。它们在**别处**是分开的（上传任务页徽标 `待配凭据` 灰 / `未声明凭据位` 红），因为那是"要谁去做不同的事"；下拉里人只需要一眼看出"这家现在用不了"。**同一种样式，不引入第二档** |
| `未识别` 标不标 | **不标** | 它不是企业、也没有凭据概念；它在表格里已整行标红（工单 03 的行为），下拉里只作筛选键 |
| 只看还是也禁用 | **只看，不禁用** | 待配凭据的企业**必须仍可选**：筛选要能筛出它们的行；编辑弹窗要能把任务改派给它们（凭据列被重设为该企业那套，等密钥到手即可传）。禁用会把"等密钥"变成"改不了" |
| 样式取值 | `class="fst-italic text-muted"` | 用户要的是"不同的字体"。Bootstrap 5.3.3 **本地副本**已含这两个类（`fst-italic{font-style:italic}` / `.text-muted{color:var(--bs-secondary-color)}`），不依赖 CDN |
| 就绪态的取数 | 复用既有的 `credentialFor()` + `credentialConfigured()`，并把 `retailCredentialReady()` 收敛到同一份数据上 | 判据只能有一处。原先 `retailCredentialReady()` 自己遍历一遍，本票要的是"全部企业"（批发主体也可能因 `.env` 缺字段变成 `pending`），两份遍历迟早说不到一块去 |
| 下拉选项的数据形状 | `selectableNames()` → **`selectableOptions()`，返回 `企业名 => 就绪态`** | 选项清单与就绪态是同一件事的两面：让它们分别取，就会有人只取名字、忘了取态——正是 `selectableNames()` 当初被收拢时要防的那种漂移。改了名字，老调用点会**当场报错**而不是静默丢掉样式 |

## 范围

### `src/Enterprise.php`

1. 新增 `credentialReady(): array<string,string>`——**全部企业**：`企业名 => 'ready'|'pending'|'no_slot'`
2. `retailCredentialReady()` 改为对 `credentialReady()` 按 `isRetail` 过滤（**公开契约不变**：
   只含零售、门店名缺席仍表示"不在配置中"——两个视图与既有断言都依赖它）
3. `selectableNames()` → `selectableOptions()`：`企业名 => 就绪态`，另含 `未识别 => 'unidentified'`

### `src/views/layout.php`

4. 新增 `companyOptionAttrs(string $state): string`——就绪态 → 可直接放进 `<option>` 的属性串。
   全站视图都 `require_once` layout.php，放这里保证 6 处都能用。**白名单式**：只对
   `pending` / `no_slot` 出样式，其余（含未知态）一律空串——将来多一个态时，默认是"不高亮"而不是"乱高亮"

### 6 处视图

5. 各页的 `$companyOptions` 改成新形状，每个 `<option>` 带上 `companyOptionAttrs($state)`
6. `manual_upload.php` 的选项另有 `data-type`（JS 的 `currentType()` 读它）与 `（门店）/（批发）` 后缀——**保留**

## 明确不做

- **不动表格**：上传任务页行内那套三态徽标（`未识别` 红 / `待配凭据` 灰 / `未声明凭据位` 红）是逐行的，
  与下拉的"选之前一眼看"是两件事，本票不改
- **不加文字后缀**（如"（待配凭据）"）：用户要的是字体区分。`manual_upload.php` 那个下拉已经有
  `（门店）` 后缀，再加一段只会更长。**若实测浏览器不认 `<option>` 的斜体，另开一票改文字标记**
- **不重排选项顺序**：顺序仍是配置顺序

## 验收

1. ✅ `php tests/enterprise_config_test.php` 全绿：**新增 12 条断言、删掉 1 条**（`names()` 那条随方法
   一起删，见收口）——三态字面量的钉法、`credentialReady()` 三态与 fixture 相符且**含批发企业**、
   `retailCredentialReady()` 四条既有断言原样通过（重构不回归的证据）、`selectableOptions()` 的键序与
   `未识别` 态、`companyOptionAttrs()` 的 `pending`/`no_slot` 出样式、`ready`/`unidentified`/未知态出空串
2. ✅ `tests/*_test.php` 七个自包含脚本全绿（`search_bill` / `singlerelation` 两个探针要传单号且会调
   平台，本就只作手动调试，未跑）
3. ✅ **6 处下拉逐一静态核对**：`grep -n companyOptionAttrs src/views/*.php` 命中 6 个渲染点
   （upload_tasks ×2、failed ×2、uploaded ×1、manual_upload ×1），全仓 `selectableNames` 无残留调用
4. ⏳ **渲染实测**：只读脚本（`/tmp/verify-ticket16.php`，不入仓）`require` **真实的 4 个视图文件**，
   抓出 6 处下拉共 **104 个选项**逐条核对样式位，**0 失败**——10 家 `pending` 门店全带
   `class="fst-italic text-muted"`，批发主体与 5 家 `ready` 门店不带，`未识别` 不带。
   **但"斜体在浏览器里显不显"本机验不了**（无浏览器/JS 引擎，`<option>` 的字体呈现只能人工看），
   故本项留空，待用户在页面上确认后再勾
5. ✅ 文档同步：`CLAUDE.md`（文件树两处、三数据页章节新增一条、手动上传章节）、`CONTEXT.md`
   （"所属企业"补下拉标记、"待配凭据"扩为与"配置缺口"并列的词条、"手动上传"）、`spec.md` 票表

## Comments

### 2026-10-01 实现笔记

- **`selectableNames()` 改名 `selectableOptions()` 并改返回 `企业名 => 就绪态`**，不留别名。理由是这票
  的核心风险不是"忘改某一处"（grep 得到），而是"某处取到了名字却没取到态"——那样下拉会静默变回正常
  字体，没有任何地方报错。合成一个返回值后，这种漏法在类型层面就不成立了；改名则保证老调用点**当场
  炸**而不是悄悄降级。全仓 `selectableNames` 现在只剩 `Enterprise.php` 里一句历史说明。
- **`retailCredentialReady()` 收敛成 `credentialReady()` 的零售过滤**：原来两处各遍历一遍
  `$companies`。新增这份"全部企业"是因为**批发主体也在下拉里**（此前没人问过它的凭据态）——
  两份各写各的，迟早出现"下拉里标着能用、补传按钮却是禁用"这种自相矛盾。
- **`pending` 与 `no_slot` 在下拉里同一档**：它们在别处是分开的（上传任务页徽标 `待配凭据` 灰 /
  `未声明凭据位` 红），因为那儿回答"该谁去做哪件事"；下拉里只回答"现在能不能传"，两者答案一样。
- **只看不禁用**：待配凭据的门店仍要能筛出它的行、仍要能把任务改派给它（凭据列会重设为该门店那套，
  等密钥到手即可传）。禁用会把"等密钥"变成"改不了"。
- **`未识别` 不标**：它不是企业、没有凭据概念；它在表格里已整行标红（工单 03 的行为），下拉里只是
  一个筛选键。**这是本票的一处刻意收窄**——若日后想让"该去查配置/源库的那批"在下拉里也显眼，另开一票。
- **没加文字后缀**（如"（待配凭据）"）：用户要的是字体区分，且 `manual_upload.php` 那个下拉已有
  `（门店）`/`（批发）` 后缀，再加一段只会更长。**若实测浏览器不认 `<option>` 的斜体/灰字，改文字
  标记是下一步**（`companyOptionAttrs()` 是唯一要动的地方，6 处调用点都不用改）。
- **`companyOptionAttrs()` 放 `views/layout.php`**：全站视图都 `require_once` 它（同 `layout()`），
  不必新增 include。代价是这个函数进了视图文件，测试要 include 视图文件才能断言它——
  已在 `enterprise_config_test.php` 里注明该 include 无输出、无副作用。
- **一处没做的事**：`validate()` 不拦"企业名恰好叫 `未识别`"（新返回形状下它会与哨兵键撞名）。
  属于既有的配置自检缺口，与旧实现同样存在（旧 `array_merge` 会渲染出重复项），本票不顺手扩大范围。

### 2026-10-01 code-review 收口

两轴并行审（`git diff 26b48b6...HEAD`）。**Standards：无硬性违反**；**Spec：无缺失、无范围蔓延**，
6 处下拉计数与既有契约均经独立实跑复核属实。改了三处、留了两处：

- **补 `STATE_*` 常量**（Standards 判断性提示 1：裸字面量散落）：`STATE_READY/PENDING/NO_SLOT/
  UNIDENTIFIED` 定义在 `Enterprise`，`credentialReady()`、`selectableOptions()`、`companyOptionAttrs()`
  的白名单、三个视图的降级分支全部改用它。**但断言的仍是字面量**（`=== 'pending'` 而非常量）——
  这几个值同时是内联 JS 的比较口径，改常量值时断言必须跟着响。常量注释里写明了这一点，
  免得被读成"词汇表已单点收口"。
- **删掉 `Enterprise::names()`**（两轴都点到的生产死代码）：它唯一的调用者就是本票删掉的
  `selectableNames()`；顺序断言已由 `selectableOptions()` 那条覆盖。
- **三页那段注释从三行压到两行**（判断性提示 2：逐字重复三遍）：渲染规则只写在 `layout.php`，
  视图留一句指针即可。
- **留 `manual_upload.php` 的 `?? ''` 哨兵**（判断性提示 3）：键必在（两处遍历同一份配置），
  这个兜底只影响"要不要上样式"，显示层 fail-safe 比 fail-loud 合适。
- **留共享函数在视图文件**（判断性提示 5）：全站视图都 require 它，省一处 include；代价是测试要
  include 视图文件，已在该测试里注明该 include 无输出、无副作用。

Spec 轴另提的两点已照办／记档：① 票面原写"12 条新增断言"实为 11（收口后 12），已改正；
② `no_slot` 在真实配置中**无样本**（16 家全 ready/pending），故"两态同档"目前**只有单测断言、
无真实渲染证据**——真实配置里出现第一家 `no_slot` 时才会在页面上第一次显形。

### 2026-10-01 口径被 17 票取代（注记，不改写上文）

用户看过实现后要求改成**两类都标**：有凭据的加粗黑字、没凭据的正常字体灰字。

上面「决策」表里"哪些算没有 AppKey""只看不禁用""`pending`/`no_slot` 同档"三条**不变**；
变的只有**样式取值**（斜体灰字 → 两种字体档）——即 `companyOptionAttrs()` 的函数体。
本票那句"若实测浏览器不认 `<option>` 的斜体，另开一票改文字标记"随之失效（斜体已不存在）。

回过头看，本票"只标没凭据的那一类"这个取向**本身就不够**：只看得出"哪些不能用"、看不出
"哪些能用"，在一个 17 项的列表里等于没给参照系。17 票改成两类都标，是对这个取向的修正，
不是对"就绪态该不该上样式"的翻案——判据与本票完全一致。
