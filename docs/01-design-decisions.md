# 设计决策：apt 制品 vs 源码编译

> 结论先行：**主路径采用 apt 软件包解包产物（docker-model-plugin deb），源码编译仅作为审计与兜底手段。**
> 评估基于 2026-10-26 实测数据，非推测。

---

## 一、先厘清一个前提：官方到底发布什么

| 渠道 | 是否提供 `docker-model` CLI 二进制 |
|---|---|
| `docker/model-runner` GitHub Releases | ❌ **没有任何二进制资产**（v1.2.9 assets 为空数组） |
| Docker apt repo（Ubuntu noble） | ✅ `docker-model-plugin_1.2.9-1~ubuntu.24.04~noble_{amd64,arm64,armhf}.deb` |
| Docker yum repo（el9） | ✅ `docker-model-plugin-1.2.9-1.el9.x86_64.rpm` |
| `docker-ce-cli` 包 | ❌ 只有 `docker` 本体，`Suggests: docker-model-plugin` |
| docker static bundle（`docker-29.9.2.tgz`） | ❌ 只有 docker/dockerd/containerd/runc 等 8 个文件 |

**关键事实：源码编译没有"官方产物"可对照。** 上游只通过发行版包管理器分发 CLI 插件，
GitHub 只发 `dmr`（独立推理二进制，`dmr-v0.1.0` 有 linux-amd64 资产，但那不是 CLI 插件）。

---

## 二、硬指标对比（全部实测）

| 维度 | apt 解包产物 | 源码编译产物 | 胜者 |
|---|---|---|---|
| **可获取性** | 一条 curl 拿到 deb | 需 `codeload` 拉源码 + Go 1.26.10+ | deb |
| **二进制大小** | 28,180,642 B | 28,364,960 B（+0.65%） | deb（略小） |
| **链接方式** | 静态链接 ELF（已验证） | 静态链接 ELF（已验证） | 平 |
| **构建标志** | `-trimpath=true`、`CGO_ENABLED=0`（buildinfo 可查） | 需手动指定才能一致 | deb |
| **可复现性** | SHA256 可对 apt `Packages` 校验 | 3 次构建 hash 完全一致（已验证） | 平 |
| **可信来源** | apt `InRelease` GPG 签名 + `Packages` SHA256 | 只有 GitHub tag 的 GPG commit 签名 | **deb** |
| **版本号正确性** | `v1.2.9`（`-X` ldflags 已注入） | ⚠️ 用 codeload tarball 构建**无 `.git`**，`git describe` 失败 → 版本落回 `"dev"` | **deb** |
| **构建耗时** | 下载 7.4MB，约 2 秒 | 首次 1m48s（下载 860MB 依赖），热构建 1 秒 | deb |
| **构建环境** | 只需 curl + tar + xz | 需 Go 1.26.10+（本机 go1.27.3 可用，但版本漂移风险） | deb |
| **供应链风险** | 依赖 Docker 官方 apt 源 | 依赖 394 个 go.sum 条目 + 第三方 module proxy | **deb** |
| **可审计性** | 二进制不可读，只能靠签名链 | 源码可读、可改、可加审计 | 源码 |
| **可定制性** | 无 | 可打补丁、裁剪、加内部特性 | 源码 |
| **功能面** | 24 个 `docker model` 子命令 | 完全相同的 24 个（已 diff 验证） | 平 |
| **gateway 子命令** | 不含（需 `-tags gateway` + Rust 静态库） | 默认构建同样不含 | 平 |

### 补充说明：`make build-cli` 在 Unraid 场景下会直接失败

上游 `cmd/cli/Makefile` 的 `build` 目标依赖 `build-gateway`：

```make
build: build-gateway
build-gateway:
	cargo build --release --manifest-path ../../model-cli/Cargo.toml
	@cp .../libmodel_cli_gateway.a commands/gateway_lib/libgateway.a
```

即需要 **Rust 工具链 + CGO 交叉编译**。若只用 `CGO_ENABLED=0 go build` 绕开，
则等于自己发明了一套与上游不同的构建方式——更需要人工验证。

---

## 三、决策矩阵（加权评分）

权重按"Unraid 插件分发"这个场景设定：**可信度 > 可维护性 > 可定制性**。

| 指标 | 权重 | apt 解包 | 源码编译 |
|---|---|---|---|
| 来源可信 / 可验证 | 30% | 9 | 6 |
| 版本准确无误 | 20% | 10 | 5 |
| 可维护性（升级成本） | 20% | 9 | 4 |
| 构建环境简单 | 15% | 10 | 4 |
| 可审计性 | 10% | 5 | 9 |
| 可定制性 | 5% | 2 | 9 |
| **加权总分** | | **8.75** | **5.55** |

---

## 四、最终方案

### 主路径：apt 解包（`scripts/build-plugin.sh`）

```
1. Read the SHA256 / Size / Filename of the target version from the
   download.docker.com Packages index
2. Download the deb, verify SHA256 byte for byte
3. Parse the ar container with pure Python (Unraid has no `ar`/`dpkg-deb`, verified)
4. Unpack data.tar.xz, extract usr/libexec/docker/cli-plugins/docker-model
5. Pack a Slackware txz (with install/slack-desc, required by upgradepkg)
6. Render the .plg (SHA256, boot deploy script, uninstall script)
```

**为什么不用 `dpkg-deb`/`ar`：** 测试机上 `which ar dpkg dpkg-deb` 全部 not found，
只有 `python3`（且是 NerdTools 装的，非 base OS）、`tar`、`xz`。所以解析 ar 必须用 Python，
或纯 shell（偏移计算）。脚本采用 **Python 优先 + 纯 shell 兜底** 双实现。

### 兜底路径：源码编译（仅审计用，不进发布流程）

保留 `docs/02-technical-design.md` 中记录的构建命令，用于：

- 怀疑 deb 产物被篡改时做行为对比
- 需要给插件打补丁（例如改默认端口、改镜像源）
- 上游停止发 deb 但继续发源码时

**若走源码编译，必须显式注入版本号**，否则 `docker model version` 显示 `dev`：

```bash
VERSION=v1.2.9
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 \
  go build -trimpath -ldflags="-s -w -X github.com/docker/model-runner/cmd/cli/desktop.Version=${VERSION}" \
  -o docker-model ./cmd/cli
```

---

## 五、apt 解包方案的残余风险与对策

| 风险 | 影响 | 对策 |
|---|---|---|
| Docker 停止发布 noble 包 | 无法升级 | 回退源码编译路径；deb 已归档在 `pkg/` |
| deb 内部二进制被投毒 | 高 | SHA256 对 `Packages` 校验 + `InRelease` GPG 链；发布时在 `.plg` 里再写一层 SHA256 |
| Ubuntu 包在 Slackware 上依赖 glibc 差异 | 中 | **已验证为静态链接**，无 glibc 依赖（`file` 输出 `statically linked`） |
| 插件随 Unraid 升级失效 | 中 | `.plg` 的 `min` 版本 + 开机脚本每次重新部署 |
| 上游 CLI 与 runner 版本不匹配 | 中 | 插件同时锁定 CLI 与 `docker/model-runner` 镜像 tag |

---

## 六、验证证据摘要

```
$ file docker-model            # extracted from the apt artifact
ELF 64-bit LSB executable, x86-64, statically linked, Go BuildID=..., stripped

$ go version -m docker-model
  go1.26.10
  path  github.com/docker/model-runner/cmd/cli
  mod   github.com/docker/model-runner  v1.2.9
  build -buildmode=exe  -compiler=gc  -trimpath=true

$ shasum -a 256 docker-model-plugin_1.2.9-..._amd64.deb
0d3a9ff3d9df36e8000680a178e74b64582a6f77b97dbd9d6ad31f2d0f197ea9
# matches the SHA256 in the apt Packages index exactly

$ strings docker-model | grep -o "docker model [a-z-]*" | sort -u | wc -l
24        # identical to the source-built binary after diff
```
