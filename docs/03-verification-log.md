# 验证记录

所有数据来自 **2026-10-26** 在测试机（Unraid 7.3.2 / Docker 29.5.3 / 双路 Xeon E5-2680 v2，64GB 内存）上的实测。

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
24 条命令完全一致
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
