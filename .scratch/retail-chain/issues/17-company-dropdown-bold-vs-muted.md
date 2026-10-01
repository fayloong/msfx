# 17: 「所属企业」下拉改口径——有凭据的加粗黑字、没凭据的正常字体灰字

- Type: task
- Status: open
- Blocked by: 无（16 已完成）
- 关联：`16-company-dropdown-credential-font.md`（本票改的就是它定的字体口径）、`src/views/layout.php`
  的 `companyOptionAttrs()`、`src/Enterprise.php` 的 `credentialReady()` / `STATE_*`、`docs/adr/0012`

## 背景：16 票只标了一类

16 票定的规则是"**没配齐凭据的**标成斜体灰字，其余原样"——人只看得见"哪些不能用"。
在一个 17 项的列表里，"能用"的那 5 家因此和 `未识别` 一样，靠自己一个个数。

用户要求改成**两类都标**：有凭据的**加粗 + 黑字**，没凭据的**正常字体 + 灰字**。

## 要交付的行为

| 态（`credentialReady()`） | 16 票 | **本票** |
|---|---|---|
| `ready` | 原样 | **`fw-bold text-black`**（加粗黑字） |
| `pending` / `no_slot` | `fst-italic text-muted`（斜体灰字） | **`fw-normal text-muted`**（正常字体灰字） |
| `unidentified`（`未识别`） | 原样 | **原样**（见下） |
| 未知态 | 原样 | 原样 |

6 处下拉（三个筛选栏 + 两个编辑弹窗 + 手动上传页顶部）**一个字都不用改**——它们调的都是
`companyOptionAttrs()`，这正是 16 票把渲染规则收成一份的回报。

## 决策

| 决策点 | 选择 | 理由 |
|---|---|---|
| `未识别` 要不要也走灰字 | **不上样式，保持原样** | 用户的口径是"没有 **appkey 的企业**"，而 `未识别` 不是一个企业（16 票已定的收窄）。它在表格里另有整行标红 + 红徽标那套标记（工单 03），下拉里只是一个筛选键。**若希望它也灰下去，改 `companyOptionAttrs()` 的 default 分支一行即可** |
| 要不要显式写 `fw-normal` | **要** | 默认继承本就是 400，写出来是让"这一档是正常字体"成为代码里的话。不然哪天全局 CSS 把 `option` 加粗了，这一档会跟着变，而没人会想到这里 |
| 斜体（`fst-italic`） | **去掉** | 用户要的是"正常字体"。留着斜体与加粗并列会变成三重区分（粗/斜/色），没必要 |
| `text-black` 而不是留默认色 | 按用户要求写死 | Bootstrap 的 `.text-black` = `rgba(0,0,0,1)`。本仓无暗色主题（`data-bs-theme` 未设，默认 light），黑字在浅底上对比度最高 |

## 范围

### 只动一处

`src/views/layout.php` 的 `companyOptionAttrs()`：`ready` → ` class="fw-bold text-black"`，
`pending`/`no_slot` → ` class="fw-normal text-muted"`，其余 → `''`。docblock 同步改口径。

### 连带

- `tests/enterprise_config_test.php`：`companyOptionAttrs()` 的断言按新类名改（`ready` 那条从
  "不加样式"变成"加粗黑字"）
- `CLAUDE.md`（文件树 `layout.php` 行、"三数据页"那条 bullet、手动上传章节）、`CONTEXT.md`
  （"所属企业"词条、"待配凭据"词条）——把"斜体灰字"的说法改成新口径
- `spec.md` 票表加第 17 行

### 明确不做

- 不动行内徽标（上传任务页那套 `待配凭据` 灰 / `未声明凭据位` 红 / `未识别` 红）
- 不重排选项顺序、不加文字后缀
- 不碰 `credentialReady()` / `selectableOptions()` 的数据形状——**本票纯显示层**

## 对 16 票的影响

16 票「验收」第 4 项（"斜体在浏览器里显不显"，当时留空）**随本票作废**：斜体已经不存在了。
16 票的其余交付（就绪态判据收口、`selectableOptions()` 改形、6 处覆盖、`STATE_*` 常量）全部保留。

## 验收

1. `php tests/enterprise_config_test.php` 全绿，`companyOptionAttrs()` 的实现断言按新类名改过
2. 全部 7 个自包含测试脚本仍全绿
3. 渲染复验：只读脚本 require 真实的 4 个视图文件，6 处下拉每个选项的样式位与
   `credentialReady()` 逐条对上——`ready` 必带 `fw-bold text-black`，`pending`/`no_slot` 必带
   `fw-normal text-muted`，`未识别` 与"全部"占位项必不带
4. **浏览器里最终长什么样由用户确认**（本机无浏览器；两类都上样式后，"加粗看着够不够明显、
   灰字对比度够不够"是主观判断）
5. 文档同步：`CLAUDE.md` / `CONTEXT.md` / `spec.md`

## Comments
