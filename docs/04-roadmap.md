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
| P4 | CA（Community Applications）上架 | ⬜ 待办 |
| P5 | 可选：图形界面（Settings 页） | ⬜ 待办 |
| 文档 | `docs/03` 按最终构建方案回填实测哈希 | ⬜ 待办 |

> 最近一次核对：**2026-10-27**。P2 的完成状态基于已发布的 Release 1.2.9 实测校验，
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

1.2.9 发布产物的 SHA256（Linux CI 与 macOS 本地产物一致）：

```
0d545fb9e7e2f098b79d249757e5b839ebddaa981b493ee3f6b84c115d43e905  unraid-plugin-docker-model-1.2.9-x86_64-1.txz
```

> 早期用 `tar` 打包时，GNU tar（Linux/CI）与 bsdtar（macOS）写出的头部字段不同、
> 成员顺序依赖 readdir，两个平台的产物哈希不一致且依赖检出目录。现已改用 Python
> `tarfile` 统一写出，见提交 `b39e673`、`e6337f0`。

---

## P2 · 发布仓库

**已完成**（2026-10-26 首次发布；2026-10-27 重新发版，补上代理版插件文件）：

1. ✅ 打 tag 并推送：`refs/tags/1.2.9` → `701429a`（本次发版提交），已推送到 origin
2. ✅ CI（`.github/workflows/build.yml`）自动构建并附加产物，Release 由 `github-actions[bot]` 创建
3. ✅ Release 1.2.9 含三个资产：
   - `unraid-plugin-docker-model-1.2.9-x86_64-1.txz`
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
> 补齐代理版时只能重新发布同一个 `1.2.9`，tag 前移到包含新插件文件的提交。
> 插件的 `version` 属性也随之保持 `1.2.9`，所以**已安装的用户不会收到更新提示**——
> 代理版面向的是新装机的中国大陆用户。

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
gh release create 1.2.9 dist/unraid-plugin-docker-model-1.2.9-x86_64-1.txz \
  --title "unraid-plugin-docker-model 1.2.9" \
  --notes-file CHANGELOG.md
```

**发布后必须跑 `scripts/verify-release.sh <plg-url>`** 确认线上 SHA256 与本地一致。

---

## P3 · 正式安装验证

**下一步**。当前测试机上是**手工**装好的（直接 `cp` 二进制 + `docker tag` 镜像），
`.plg` 安装器本身还没在真实主机上跑通过。

Release 1.2.9 已发布，`.plg` 可直接取自 Release 资产，无需先在本地构建。
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

需要：

1. 提交到 [unraid/community.applications](https://github.com/unraid/community.applications) 的模板仓库
2. 提供 `unraid-plugin-docker-model.xml`（CA 用的元数据，与 `.plg` 不同）
3. 提供图标（PNG，建议 256×256）

CA 模板示例（放在独立 repo 的 `templates/` 下）：

```xml
<?xml version="1.0"?>
<Container version="2">
  <Name>unraid-plugin-docker-model</Name>
  <Repository>zhz8888/unraid-plugin-docker-model</Repository>
  <Category>Tools: Utilities</Category>
  <Description>Docker Model Runner CLI plugin for Unraid</Description>
  <Plugin>https://github.com/zhz8888/unraid-plugin-docker-model/releases/latest/download/unraid-plugin-docker-model.plg</Plugin>
</Container>
```

> CA 对**插件型**（非容器型）的收录要求较严，需要先在论坛发帖积累反馈。

---

## P5 · 可选：图形界面

Unraid 插件可以带 WebGUI 页面（`/usr/local/emhttp/plugins/<name>/`）。可做：

- **Settings → Docker Model Runner** 页
  - runner 状态（running / stopped / 版本）
  - 已安装模型列表 + 大小
  - 一键 install / uninstall runner
  - GPU 选择（none / cuda / rocm）
  - 端口配置
  - 常用模型的快捷 pull 按钮
- 后端 PHP 脚本调 `docker model` CLI

工作量估计：2–3 天。优先级低于 P3（P2 已完成）。

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
