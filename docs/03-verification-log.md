# 验证记录

第一至七节的数据来自 **2026-10-26** 在测试机（Unraid 7.3.2 / Docker 29.5.3 /
双路 Xeon E5-2680 v2，64GB 内存）上的实测；第八节是 2026-11-26/27 的本地与 NAS3
真机验证，来源不同，节内单独注明。

## 一、环境基线

```
$ cat /etc/unraid-version
version="7.3.2"

$ docker version --format '{{.Server.Version}}'
29.5.3

$ cat /etc/os-release | head -3
NAME="Unraid OS"
ID="unraid-os"
ID_LIKE="slackware"
PRETTY_NAME="Unraid OS 7.3 x86_64"

$ docker info --format '{{json .ClientInfo.Plugins}}'
[{"Name":"buildx","Path":"/usr/libexec/docker/cli-plugins/docker-buildx"},
 {"Name":"compose","Path":"/usr/lib/docker/cli-plugins/docker-compose"}]
   <- 无 model plugin
```

## 二、Unraid 官方版本全量核对

逐版抓取 `unraid/docs` 的 release notes，检索 `docker-model` / `model runner`：

| 版本 | 自带 docker | 提及 docker-model | 结论 |
|---|---|---|---|
| 7.2.0 | — | 0 | ❌ |
| 7.2.5 | — | 0 | ❌ |
| 7.2.6 | — | 0 | ❌ |
| 7.2.7 | — | 0 | ❌ |
| 7.2.8 | 29.5.1 → 29.5.3 | 0 | ❌ |
| 7.2.9 | — | 0 | ❌ |
| 7.3.0 | 29.3.1 → 29.4.3 | 0 | ❌ |
| 7.3.1 | 29.4.3 → 29.5.1 | 0 | ❌ |
| 7.3.2 | 29.5.2 → 29.5.3 | 0 | ❌ |
| 7.3.3 | 29.5.3 → 29.8.2 | 0 | ❌ |
| 7.3.4 | — | 0 | ❌ |
| 7.4.0-rc.2 | 29.5.3 → 29.8.2 | 0 | ❌ |

**核心判据：docker 版本升级不带来 `docker model`。** 它是独立包
`docker-model-plugin`（`docker-ce-cli` 的 `Suggests`），Unraid 的 Limetech
重打包只挑了 `docker` 本体 + buildx + compose。

## 三、CLI 插件来源核验

### 3.1 官方 GitHub Release 无二进制

```
$ curl -s .../repos/docker/model-runner/releases/tags/v1.2.9
tag: v1.2.9
assets: []
```

→ 源码编译没有官方产物可比对。

### 3.2 apt 包可用且可校验

```
$ curl -s .../dists/noble/stable/binary-amd64/Packages | awk ...
Filename: dists/noble/pool/stable/amd64/docker-model-plugin_1.2.9-1~ubuntu.24.04~noble_amd64.deb
Size: 7416728
SHA256: 0d3a9ff3d9df36e8000680a178e74b64582a6f77b97dbd9d6ad31f2d0f197ea9

$ shasum -a 256 docker-model-plugin_1.2.9-1~ubuntu.24.04~noble_amd64.deb
0d3a9ff3d9df36e8000680a178e74b64582a6f77b97dbd9d6ad31f2d0f197ea9   <- 一致
```

三架构均可用：

| 架构 | SHA256 | Size |
|---|---|---|
| amd64 | `0d3a9ff3…f197ea9` | 7,416,728 |
| arm64 | `100a199c…b29aa9` | 6,371,692 |
| armhf | `d87894b0…d0c033` | 6,522,152 |

### 3.3 二进制属性

```
$ file docker-model
ELF 64-bit LSB executable, x86-64, statically linked, stripped

$ go version -m docker-model
  go1.26.10
  path  github.com/docker/model-runner/cmd/cli
  mod   github.com/docker/model-runner  v1.2.9
  build -buildmode=exe
  build -compiler=gc
  build -trimpath=true
  build CGO_ENABLED=0
  build GOARCH=amd64
  build GOOS=linux
  build GOAMD64=v1
  build vcs.revision=58f4153e77170d0646681dbe0529ade453002343
  build vcs.modified=false
```

`CGO_ENABLED=0` + `statically linked` = 无 glibc 依赖，Slackware 直接可用。

## 四、源码编译对照实验

```
$ CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath \
    -ldflags="-s -w -X .../desktop.Version=v1.2.9" -o docker-model ./cmd/cli

首次构建（含 860MB 依赖下载）：1m48s
增量重建：1.0s
产物体积：28,364,960 B（deb 产物 28,180,642 B，+0.65%）
```

### 可复现性

```
$ shasum -a 256 built-linux-amd64 built2 built3
a4a42032afed1e51de7546722e1769d2f5013df3  built-linux-amd64
a4a42032afed1e51de7546722e1769d2f5013df3  built2
a4a42032afed1e51de7546722e1769d2f5013df3  built3     <- 三次构建完全一致
```

### 功能面一致

```
$ diff <(strings built | grep -o "docker model [a-z-]*" | sort -u) \
       <(strings deb  | grep -o "docker model [a-z-]*" | sort -u)
（无输出）
→ 命令集合完全一致；1.2.9 的 `docker model --help` 列出 28 个 Commands
  加 `context` 管理命令（其中 `gateway` 是 stub，不可用）
```

### ⚠️ 但源码编译有版本号陷阱

```
$ git describe --tags --always --dirty --match 'v*'
fatal: not a git repository

$ strings built_noldflags | grep -E "^(devel|v1\.2\.9)$"
(devel)        <- 版本号退化为 "dev"
```

上游 Makefile 依赖 `git describe` 注入版本；从 codeload tarball 构建时无 `.git`，
**必须手动传 `-X ...Version=v1.2.9`**，否则 `docker model version` 显示 `dev`。

### ⚠️ 且 `make build-cli` 会失败

```make
build: build-gateway
build-gateway:
	cargo build --release --manifest-path ../../model-cli/Cargo.toml
```

需要 **Rust 工具链 + CGO**（`cmd/cli/commands/gateway.go` 有 `//go:build gateway` + `import "C"`）。
纯 Go 交叉编译只能绕开 gateway 子命令。

### txz 打包的可复现性（跨目录验证）

`.txz` 本身也必须可复现，否则「同一上游版本 → 同一 hash」的承诺不成立。
实测发现过两次不确定性来源：

| 来源 | 现象 | 处理 |
|---|---|---|
| mtime | 每次构建 hash 都变 | 打包前 `touch -t 202001010000` 归一 |
| macOS 扩展属性 | 同一版本在**不同目录**构建出不同 hash | 打包前 `xattr -rc` 清除，并对支持的 tar 传 `--no-xattrs` |

第二个问题的实测证据：从 `git clone` 出来的工作树构建，与在原检出目录构建，
hash 不一致——

```
# 修复前
克隆目录构建：    6c59f68954e017cac6a9bc0da2536f0c99288bd789fdf2e81ce917728f71080a
原目录构建：      bfac03eefe7e2f2538f2aee20232713d609592247eeefd2eafed95a568b63201
```

根因是 bsdtar（libarchive）把 macOS 的 `com.apple.provenance` 扩展属性写成
`LIBARCHIVE.xattr.*` pax 扩展头：

```
00000190:       ...  pax header  ...
00000200: 0005 1607 0002 0000 4d61 6320 4f53 2058  ........Mac OS X
                                          ^^^^^^^^^^^^^
00000250: 0000 0000 4154 5452 0000 0000 0000 00a3  ....ATTR........
                                          ^^^^^^^^^^^^^^^^
```

而 git clone 出来的文件由系统打上 `com.apple.provenance`，原目录的文件没有。
修复后两边均为：

```
d86f4e9367b6292afa5f05081521e0459cccde1caca580786b1a811bd0359ca5
```

> 注意：这个缺陷只在 macOS 上出现。Linux/CI 上 GNU tar 不读取扩展属性，
> 但 `--no-xattrs` 参数做能力探测后才传，避免在不支持的 tar 上直接报错。

> **最终方案**：发布包的归档改由 Python `tarfile` 写出（固定 mtime、成员顺序、
> 权限），不再依赖 `tar`。20261027 的 txz SHA256 为
> `0d545fb9e7e2f098b79d249757e5b839ebddaa981b493ee3f6b84c115d43e905`；此后
> 20261106 / 20261110 / 20261111 沿用同一实现，CI 每次构建两遍比对哈希。上面两次
> 跨目录实验属于改用 Python 之前的中间阶段，保留作为问题定位记录。

---

## 五、插件在测试机上的完整跑通记录

### 5.1 安装

```
$ cp docker-model /usr/libexec/docker/cli-plugins/
$ chmod 755 /usr/libexec/docker/cli-plugins/docker-model
$ docker model version
Client:
 Version:    v1.2.9
 OS/Arch:    linux/amd64
Server:
 Version:    v1.2.9
```

### 5.2 部署 runner

```
$ docker pull gh-proxy.org/docker/docker/model-runner:latest
Digest: sha256:60e6a14d8cc331fab3e51d7b78bba98274b4d28573f4e0b345e475d52d167869
Status: Downloaded newer image   (969MB)

$ docker tag gh-proxy.org/docker/docker/model-runner:latest docker/model-runner:latest
$ docker model install-runner --gpu none
Creating model storage volume docker-model-runner-models...
Starting model runner container docker-model-runner...
```

### 5.3 运行状态

```
$ docker ps --filter name=model-runner
docker-model-runner | Up (healthy) | 127.0.0.1:12434->12434/tcp, 172.17.0.1:12434->12434/tcp

$ docker model status
Docker Model Runner is running
llama.cpp  Running  llama.cpp 72874f559
```

> 这里的端口是当时从终端手工执行 `install-runner`（不带 `--host`）的结果；
> 页面安装 runner 时的默认监听地址是 `0.0.0.0`，见 `docs/02` 第二节。

### 5.4 模型拉取与推理

```
$ docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M
Downloaded 270.60MB of 270.60MB
Model pulled successfully

$ docker model run gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M "Hello, what model are you?"
I'm a specialized model designed for Natural Language Processing tasks.
I've been trained on a wide range of tasks, including speech recognition,
sentiment analysis, topic modeling, and language translation.
```

**推理链路完全打通。**

## 六、踩过的坑（都会体现在插件脚本里）

| # | 现象 | 根因 | 处理 |
|---|---|---|---|
| 1 | `docker: unknown command: docker model` | 插件放进 `/root/.docker/cli-plugins/` 后 chmod 无效 | `/root/.docker` 是 vfat 软链；必须放 `/usr/libexec/docker/cli-plugins/` |
| 2 | `permission denied` 且 `ShadowedPaths` 出现 | 同上，vfat 副本遮蔽了系统目录 | 安装脚本主动 `rm -f /root/.docker/cli-plugins/docker-model` |
| 3 | `docker model pull` 直连 registry 失败 | runner 不读 `daemon.json` 的 registry-mirrors | 用 `gh-proxy.org/docker/` 前缀写进镜像名 |
| 4 | `docker manifest inspect docker.1ms.run/...` 超时 | 部分 mirror 不代理 manifest | 改用 `gh-proxy.org/docker/` |
| 5 | health 偶发 unhealthy | `runc exec` 偶发 `/run/user/0/runc-process...: no such file` | 环境噪音（同机 astrbot/UptimeKuma 同样），接口本身正常，不影响推理 |
| 6 | 重启后插件消失 | `/usr/libexec` 在 overlay 上 | 由 `.plg` 开机脚本重新 `upgradepkg` |
| 7 | apt 索引里查不到 `1.2.9` | 索引 Version 是完整 Debian 版本号 `1.2.9-1~ubuntu.24.04~noble` | 解析时用前缀匹配 `index(v, ver "-") == 1` |

## 七、性能参考（纯 CPU，非本次验证重点）

同机实测 smollm2-360M 为秒级响应。1.5B Q4 的推算值见会话记录：
生成 14–18 tok/s、预填充 180–280 tok/s（瓶颈为 4 通道 DDR3-1600 + 无 AVX2）。

## 八、2026-11-26 WebGUI 改动的验证

本节记录 2026-11-26 起 WebGUI 改动的验证：先在本机用 PHP + 假 docker CLI 验证
（8.1–8.3），再到 NAS3 真机部署页面并让页面真正跑起来（8.4）。覆盖范围包括版本信息
结构化、Stop 按钮、网络卡片、本地模型表格（更新/删除）、字号标定与卡片布局。

### 8.1 捆绑二进制的命令面

从 `download.docker.com` 重新下载 `docker-model-plugin 1.2.9`，SHA256 与 apt 索引
一致（`0d3a9ff3…ef197ea9`），说明打包进插件的就是上游产物；再从 `data.tar.xz` 里
取出 `docker-model`（28,180,642 B）检索字符串：

| 检索项 | 结果 |
|---|---|
| `stop-runner` / `start-runner` / `restart-runner` | ✅ 存在 |
| `reinstall-runner` / `install-runner` / `uninstall-runner` | ✅ 存在 |
| `--host` / `Host address to bind Docker Model Runner` | ✅ 存在 |
| `Client:` / `Engine:` / `(not reachable)` | ✅ 存在 |

→ 页面用的 `stop-runner`、`reinstall-runner` 和 `--host` 在捆绑的 1.2.9 里确实存在，
版本输出也确实是 `Client:` / `Server:` 两段式，解析器不是照文档猜的。

### 8.2 PHP 端（本地 PHP 8.4 + 假 docker CLI）

| 检查 | 结果 |
|---|---|
| 四个 PHP 文件 `php -l` | ✅ 无语法错误 |
| `docker model version` 解析（含 `(not reachable)`、未知布局） | ✅ |
| `--host` 校验（IPv4 / IPv6 / 非法值 / 空） | ✅ |
| 设置文件写入、回读、非法值回退默认 `0.0.0.0` | ✅ |
| GPU 模式从镜像 tag 反查（cpu/cuda/rocm） | ✅ |
| `Status.php` 输出结构（version / runner / runner_ports / models / models_error / settings） | ✅ |
| `Action.php` save-settings 写入 + 非法地址拒绝 + 跨源拒绝 | ✅ |
| 模型动作：`update-model` / `remove-model` 的解析、伪造引用拒绝、命令拼装 | ✅ |
| 模型列表解析（`docker model list --json`，含无 tag 模型、非法 JSON 回退） | ✅ |

### 8.3 页面渲染（Playwright + 本地 PHP 服务器 + 假 docker）

在 1440 / 1100 / 700 px 三种宽度、中英文两种语言下截图测量：

- 六张卡片齐全，**同一行的卡片高度完全相等**（1440 px 下：字号标定前一次运行为
  316/316、301/301、303/303；标定后为 399/406/425，逐行仍相等）
- Status 卡显示解析后的四行（客户端版本 1.2.9、客户端平台 linux/amd64、服务端版本
  1.0.0、服务端引擎 Docker Engine），原始输出收在折叠块里
- runner 运行中：安装按钮禁用、停止/卸载/重建可用；停止状态相反（已用假 CLI 状态切换验证）
- 命令失败时：红色提示条显示 CLI 报错，原始输出自动展开
- Network 卡：下拉框回显 `0.0.0.0`、已发布端口显示 `0.0.0.0:12434->12434/tcp, …`
- 本地模型表格：2 行、每行「更新/删除」两个按钮；行内按钮实测间距 10 px、不换行，
  表格不超出卡片宽度
- 浏览器控制台：**无错误**（无 4xx/5xx 请求、无 JS 异常）

### 8.4 NAS3 真机（2026-11-26 / 27）

- **安装产物**：NAS3（Unraid 7.3.2）上存在代理版 `.plg` 的安装结果——注册软链
  `/var/log/plugins/unraid-plugin-docker-model-proxy.plg`、flash 上的
  `unraid-plugin-docker-model-proxy.plg` 与缓存包
  `unraid-plugin-docker-model-20261106-x86_64-1.txz`、已安装的
  `/usr/libexec/docker/cli-plugins/docker-model`。这说明 `.plg` 安装路径已在真机
  执行过；重启持久化与 `plugin remove` 卸载尚未记录。
- **页面渲染**：把当前仓库的 WebGUI 文件部署到 NAS3 后，页面在真实 Dynamix 中正常
  渲染；用户在页面上保存了监听地址，生成
  `/boot/config/plugins/unraid-plugin-docker-model/unraid-plugin-docker-model.cfg`
  （`DMR_HOST="0.0.0.0"`，2026-11-26 20:01），说明 save-settings 在真机可用。
- **尚未真机验证**：Stop、重建 runner、模型更新/删除（日志
  `/var/log/docker-model-runner-model.log`）的端到端行为，以及 `.plg` 的重启持久化
  与卸载。

> 本地验证覆盖页面结构与交互逻辑；真机上的保存动作已实际执行（8.4），但 Stop、
> 重建 runner、模型更新/删除仍需在真机上逐一点过。

### 8.5 本地 API Key 设置（2026-11-27）

- 重新检查捆绑的 `docker-model` 1.2.9 二进制：没有 `--api-key` 参数，内置 OpenAI
  示例也写明本地推理不需要 API Key。因此新增的 `DMR_API_KEY` 只是 OpenAI 兼容
  客户端的连接占位值，默认 `local`，不声称开启 runner 鉴权，也不进入 CLI 命令。
- 新增 `tests/settings.php`，37 项检查全部通过：默认值、合法/非法字符、128 位上限、
  保存回读、空值恢复 `local`、只改地址时保留 Key、旧配置兼容、非法存储值回退，以及
  含 `/` 的 Key 在 Python 客户端示例中按原样渲染。
- `Action.php` 的 save-settings 场景通过：自定义 Key、默认 Key、非法字符串、数组型
  请求值、只改地址时保留 Key、跨源拒绝；保存响应不回显 Key。
- Playwright + 本地 PHP + 假 docker CLI：1440 / 1100 / 700 px、中英文共 7 次渲染通过，
  卡片等高、无横向溢出、无控制台错误或失败请求。额外覆盖显示/隐藏、自定义 Key
  保存、刷新后持久化、客户端示例同步、空值重置、非法值拒绝，以及 `Status.php` 不返回
  Key。页面截图保存在 `.research/preview/shots/`。
- 四个插件 PHP 文件与 `tests/settings.php` 通过 `php -l`；PHP 启发式语法检查、两份
  108 键翻译文件检查和 `git diff --check` 通过。诊断包不再自动收集插件配置目录，
  避免把客户端 API Key 放进支持诊断文件。

> 本节为本地验证；该设置尚未在真实 Unraid 主机上端到端验证。
