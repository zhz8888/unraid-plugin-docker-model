# 路线图

## 状态总览

| 阶段 | 内容 | 状态 |
|---|---|---|
| P0 | 选型评估（apt 解包 vs 源码编译） | ✅ 完成 |
| P0 | 构建脚本 + 插件模板 | ✅ 完成 |
| P1 | 本地构建验证（txz + plg 生成、hash 一致） | ✅ 完成 |
| P1 | 跨平台可复现构建（Linux CI 与 macOS 本地产物逐字节一致） | ✅ 完成 |
| P2 | 发布仓库（GitHub Release + `pluginURL` 资产） | ✅ 完成 |
| P3 | 在真实 Unraid 主机上通过 `.plg` 正式安装验证 | 🔲 **下一步** |
| P4 | CA（Community Applications）上架 | 🔄 材料已备（`ca_profile.xml` + wrapper XML），待发论坛帖 |
| P5 | 图形界面（Settings 页） | ✅ 完成（`src/.../DockerModelRunner.page`） |
| P6 | 对齐 Unraid 官方插件规范 | ✅ 完成（见 `docs/05-unraid-plugin-conventions.md`） |
| 文档 | `docs/03` 按最终构建方案回填实测哈希 | ⬜ 待办 |

> 最近一次核对：**2026-11-05**。P2 的完成状态基于已发布的 Release 20261027 实测校验，
> 而非仅凭提交记录推断。

---

## P0 / P1 · 已完成

| 交付物 | 位置 | 验证方式 |
|---|---|---|
| 选型评估 | `docs/01-design-decisions.md` | 硬指标全部实测，非推测 |
| 构建脚本 | `scripts/build-plugin.sh` | `make build` 生成 txz + plg |
| 插件模板 | `plugin/unraid-plugin-docker-model.plg.in` | `make lint` 静态检查 |
| 校验脚本 | `scripts/lint-plg.sh`、`scripts/verify-release.sh` | `make lint && make verify` 全绿 |
| 审计脚本 | `scripts/fetch-plugin.sh` | 只下载 + 校验 + 解包，不改动仓库 |
| 实测记录 | `docs/03-verification-log.md` | 真实 Unraid 主机上的实测数据 |
| CI | `.github/workflows/build.yml` | 每次 push 构建 + lint + verify + 复现性断言 |

20261027 发布产物的 SHA256（Linux CI 与 macOS 本地产物一致）：

```
0d545fb9e7e2f098b79d249757e5b839ebddaa981b493ee3f6b84c115d43e905  unraid-plugin-docker-model-20261027-x86_64-1.txz
```

> 早期用 `tar` 打包时，GNU tar（Linux/CI）与 bsdtar（macOS）写出的头部字段不同、
> 成员顺序依赖 readdir，两个平台的产物哈希不一致且依赖检出目录。现已改用 Python
> `tarfile` 统一写出，见提交 `b39e673`、`e6337f0`。

---

## P2 · 发布仓库

**已完成**（2026-10-26 首次发布；2026-10-27 重新发版，补上代理版插件文件）：

1. ✅ 打 tag 并推送：`refs/tags/20261027` → `701429a`（本次发版提交），已推送到 origin
2. ✅ CI（`.github/workflows/build.yml`）自动构建并附加产物，Release 由 `github-actions[bot]` 创建
3. ✅ Release 20261027 含三个资产：
   - `unraid-plugin-docker-model-20261027-x86_64-1.txz`
   - `unraid-plugin-docker-model.plg`（直连版）
   - `unraid-plugin-docker-model-proxy.plg`（经 `https://gh-proxy.org/` 代理）
4. ✅ `pluginURL`（`releases/latest/download/unraid-plugin-docker-model.plg`）返回 HTTP 200
5. ✅ 代理版下载地址（`releases/latest/download/unraid-plugin-docker-model-proxy.plg`）返回 HTTP 200
6. ✅ 线上 SHA256 与本地一致：

```bash
$ ./scripts/verify-release.sh \
    https://github.com/zhz8888/unraid-plugin-docker-model/releases/latest/download/unraid-plugin-docker-model.plg
[verify] expected SHA256: 0d545fb9e7e2f098b79d249757e5b839ebddaa981b493ee3f6b84c115d43e905
  ok remote txz SHA256 matches
```

> 发版版本号跟随上游 `docker-model-plugin`，因此上游未发新版本前无法用新版本号发版；
> 补齐代理版时只能重新发布同一个版本，tag 前移到包含新插件文件的提交。
> 插件的 `version` 属性也随之保持不变，所以**已安装的用户不会收到更新提示**——
> 代理版面向的是新装机的中国大陆用户。
>
> ⚠️ **该问题已在 2026-11-05 修复**：插件版本改为发布日期式 `YYYYMMDD`
> （同日多次发布用 `.a`/`.b` 后缀），与上游版本解耦。更关键的是，Unraid 安装器用
> `strcmp()` 比较插件版本，原来的 `1.2.9` 方案在上游发布 `1.2.10` 时会被判定为
> **更旧**而拒绝安装。详见 `docs/05-unraid-plugin-conventions.md`。
>
> 该版本最初以 `1.2.9` 为 tag 发布，2026-11-05 按新的版本规则改名为 `20261027`
> （发布日）。tag 指向的提交没有变，`txz` 内容也没有变，只是文件名与 `.plg` 里的
> 版本号改了。远端 tag 与 Release 资产需要同步更新，见下面「版本号规则」。

> 生成的 `.plg` 是构建产物（`dist/` 被 gitignore），因此通过 Release 资产分发，
> 不提交进仓库。`pluginURL` 与下载链接都使用 GitHub 的
> `releases/latest/download/<asset>` 稳定地址，换版本时无需改 URL。
>
> `pluginURL` 曾指向未跟踪的 `plugin/unraid-plugin-docker-model.plg`（模板文件），
> 导致 Unraid「检查更新」返回 404、自动更新静默失效，已在提交 `80e1eee` 修正为
> Release 资产地址。

手动发布（不走 CI 时）：

```bash
# 发布命令（示例）
make build lint verify
gh release create 20261105 dist/unraid-plugin-docker-model-20261105-x86_64-1.txz \
  --title "unraid-plugin-docker-model 20261105" \
  --notes-file CHANGELOG.md
```

**发布后必须跑 `scripts/verify-release.sh <plg-url>`** 确认线上 SHA256 与本地一致。

---

## 版本号规则

**插件版本 = 发布日期 `YYYYMMDD`。** 同一天发布多次时，第二个版本起追加字母后缀：

```
20261105      当天的第一个版本
20261105.a    当天的第二个版本
20261105.b    当天的第三个版本
20261106      第二天
```

规则固化在以下位置，改动时必须同步：

| 位置 | 作用 |
|---|---|
| `scripts/build-plugin.sh` | 默认取本地日期 `date +%Y%m%d`；不匹配 `^[0-9]{8}(\.[a-z])?$` 直接报错退出 |
| `scripts/lint-plg.sh` | 校验生成的 `.plg` 的 `version` 属性符合该格式 |
| `Makefile` | `PLUGIN_VERSION ?= $(shell date +%Y%m%d)` |
| `.github/workflows/build.yml` | 构建与复现性检查固定 `PLUGIN_VERSION`；发版 job 用 git tag |
| `CONTRIBUTING.md`、`README.md`、`README.zh-CN.md`、`docs/02`、`docs/05` | 规则说明 |

**为什么必须这么排**：Unraid 安装器用 `strcmp()` 比较插件版本，即按字节比较字符串。
这套格式在字节序下正好等于发布顺序：

```
20261105 < 20261105.a < 20261105.b < 20261106
```

`20261105` 是 `20261105.a` 的前缀，短者在前；`.` 的字节值 0x2E 小于数字字符，所以
`20261105.z` 仍然小于 `20261106`。语义化版本做不到这点——`1.2.10` 排在 `1.2.9`
**之后**，Unraid 会拒绝这次升级。

**插件版本与上游版本解耦**：上游 `docker-model-plugin` 的版本号另行记录在
更新日志、包描述与 WebGUI 页面里。同一个上游版本的重新打包也要发新版本号，
否则已安装的用户收不到更新提示。

### 历史版本的改名

首个版本最初以 `1.2.9` 为 tag 发布（因为当时插件版本直接跟随上游版本号）。
按新规则改名为发布日 `20261027`：

- git tag：`refs/tags/1.2.9` → `refs/tags/20261027`，**指向同一个提交**（`701429a`），
  tagger 与日期保持不变
- 资产文件名：`unraid-plugin-docker-model-1.2.9-x86_64-1.txz`
  → `unraid-plugin-docker-model-20261027-x86_64-1.txz`（**内容不变**，SHA256 不变）
- `.plg` 里的四处替换：

  | 位置 | 改动 |
  |---|---|
  | `<PLUGIN version="...">` | `1.2.9` → `20261027` |
  | `<FILE Name="...">` | 缓存的包名换成新文件名 |
  | `<URL>` | `/releases/download/1.2.9/` → `/releases/download/20261027/` |
  | `<CHANGES>` 里的 `### 1.2.9` | 改成 `### 20261027` |

  ⚠️ 更新日志里 `docker-model-plugin_1.2.9` 那一行是**上游版本**，不能跟着改。

改名后的资产已生成在 `dist/reissue-20261027/`（`dist/` 被 gitignore，属本地产物）。
`txz` 逐字节复现了已发布产物，SHA256 仍是
`0d545fb9e7e2f098b79d249757e5b839ebddaa981b493ee3f6b84c115d43e905`。

远端需要同步执行：

```bash
# 删除旧 tag，推送新 tag
git push origin :refs/tags/1.2.9
git push origin refs/tags/20261027

# 用改名后的资产覆盖 Release
gh release upload 20261027 \
  dist/reissue-20261027/unraid-plugin-docker-model-20261027-x86_64-1.txz \
  dist/reissue-20261027/unraid-plugin-docker-model.plg \
  dist/reissue-20261027/unraid-plugin-docker-model-proxy.plg --clobber

# 把 Release 的标题与 tag 一起改名
gh release edit 20261027 --tag 20261027 --title "unraid-plugin-docker-model 20261027"
```

---

## P3 · 正式安装验证

**下一步**。当前测试机上是**手工**装好的（直接 `cp` 二进制 + `docker tag` 镜像），
`.plg` 安装器本身还没在真实主机上跑通过。

Release 20261027 已发布，`.plg` 可直接取自 Release 资产，无需先在本地构建。
需要用 `.plg` 走一遍完整流程：

```bash
# 1. 删除手工安装的副本（此前没有 removepkg 记录，直接删除文件）
ssh root@<unraid-host> "rm -f /usr/libexec/docker/cli-plugins/docker-model"

# 2. 下载并上传 plg 文件（Release 资产，无需本地构建）
curl -fsSL -o /tmp/unraid-plugin-docker-model.plg \
  https://github.com/zhz8888/unraid-plugin-docker-model/releases/latest/download/unraid-plugin-docker-model.plg
scp /tmp/unraid-plugin-docker-model.plg root@<unraid-host>:/boot/config/plugins/

# 3. 安装
ssh root@<unraid-host> "plugin install /boot/config/plugins/unraid-plugin-docker-model.plg"

# 4. 验证
ssh root@<unraid-host> "docker model version; docker model status"

# 5. 重启以验证持久化
ssh root@<unraid-host> "reboot"
# 等待主机重新上线
ssh root@<unraid-host> "docker model version"
```

验收标准：

- [ ] `plugin install` 无报错
- [ ] `/var/log/plugins/unraid-plugin-docker-model.plg` 软链已建立
- [ ] `docker model version` 输出 `v1.2.9`
- [ ] **重启后**仍可用（验证开机脚本生效）
- [ ] `plugin remove unraid-plugin-docker-model` 能干净卸载
- [ ] 卸载后 runner 容器/镜像/卷仍保留（不误删数据）

---

## P4 · CA 上架

**仓库侧材料已备齐**：

| 文件 | 作用 |
|---|---|
| `ca_profile.xml` | 仓库根目录的仓库信息，`<Profile>` 非空是 CA 的硬性提交门槛 |
| `icon.svg` | 仓库根目录图标 |
| `ca/unraid-plugin-docker-model.xml` | 插件条目（wrapper XML） |

wrapper XML 的 `<PluginURL>` 必须与 `.plg` 的 `pluginURL` 属性**完全一致**，
两者都是 `releases/latest/download/unraid-plugin-docker-model.plg`。

**还差一步**：CA 要求插件有一个论坛支持帖。待办：

1. 在 Unraid 论坛的 *Community Applications* 分类发帖，把帖子 URL 填进
   `ca/unraid-plugin-docker-model.xml` 的 `<Support>`（以及 `ca_profile.xml` 的
   `<Forum>`）
2. 通过 <https://ca.unraid.net/submit> 提交；条目以 pull request 的形式进
   [unraid/community.applications](https://github.com/unraid/community.applications)
   的 `plugins/` 目录

> CA 对插件型的收录要求较严，需要先在论坛发帖积累反馈。

---

## P5 · 图形界面（已完成）

WebGUI 页面随 Slackware 包一起安装，位于
`/usr/local/emhttp/plugins/unraid-plugin-docker-model/`：

- **Settings → Docker Model Runner**（`DockerModelRunner.page`）
  - 插件版本、捆绑的 docker-model 版本、CLI 二进制路径与校验结果
  - Model Runner 运行状态
  - 本地已有模型列表
- **Settings 页签**（`DockerModelRunner-1-Settings.page`）
  - 一键部署 runner（CPU / GPU auto），后台执行，日志写 `/var/log/docker-model-runner-install.log`
  - 一键移除 runner（保留镜像与模型卷）
  - 手动命令速查

后端 PHP 走 `docker model` CLI，用 `proc_open` + 超时保证 WebGUI 不被卡住；
操作接口只接受固定的 action 名，命令不来自请求，避免 shell 注入。

> ⚠️ 页面**尚未在真实 Unraid 主机的 Dynamix 里验证过**，见 `docs/05` 第四节。

---

## 待办 · 实测记录回填

`docs/03-verification-log.md` 的「txz 打包的可复现性（跨目录验证）」一节记录的是
**中间阶段**的修复结果，其哈希与最终发布产物对不上：

| 记录位置 | 文档中的哈希 | 对应阶段 |
|---|---|---|
| 克隆目录 / 原目录构建对比 | `6c59f689…`、`bfac03ee…` | xattr + `--no-xattrs` 阶段 |
| 「修复后两边均为」 | `d86f4e93…` | 同上 |
| **实际发布产物** | `0d545fb9…` | 最终改用 Python `tarfile` |

原因：最终方案不再用 `tar` 打包（GNU tar 与 bsdtar 头部字段不同、成员顺序依赖
readdir），改用 Python `tarfile` 统一写出，见提交 `b39e673`、`e6337f0`。
文档需要按最终方案重写这一节，并补上「Linux CI 产物 == macOS 本地产物」的实测结论。

---

## 待确认问题

1. **是否要支持 arm64**：Unraid 只跑 x86_64，但脚本已支持 `DMR_ARCH=arm64`。
2. **runner 镜像是否要一并缓存到 flash**：969MB，flash 只有 32GB，建议不缓存。
3. **是否需要多版本并存**：目前 `.plg` 只装一个版本，升级即覆盖。

---

## 维护节奏

上游 `docker-model-plugin` 发版频率约 2–4 周一次。当前上游最新版本仍是 **1.2.9**
（2026-10-27 核对 `Packages` 索引），与本项目捆绑版本一致，无需 bump。

流程：

1. `.github/workflows/upstream-check.yml` 每天 12:00 UTC 检查 apt 索引，发现新版本会自动开 issue
   （工作流已配置；仓库 2026-10-26 创建，首次定时触发为 2026-10-27 12:00 UTC，
   目前尚无运行记录，需确认首个 issue 能正常开出）
2. 按 issue 提示跑 `DMR_VERSION=x.y.z ./scripts/build-plugin.sh`
3. `make lint && make verify`，并在测试机上 smoke test（`docker model version` + `docker model status`）
4. 更新 `CHANGELOG.md`，合并后打 tag，CI 自动发布 Release
5. 发布后跑 `scripts/verify-release.sh <plg-url>` 核对线上 SHA256
