# 技术设计

## 一、Unraid 插件机制速查

| 机制 | 说明 |
|---|---|
| 插件定义 | `*.plg`（XML），放在 `/boot/config/plugins/` |
| 开机执行 | `/etc/rc.d/rc.local` 遍历 `/boot/config/plugins/*.plg`，逐个 `plugin install` |
| 安装器 | `/usr/local/sbin/plugin`（PHP），下载 `<FILE>` → 校验 `<SHA256>` → 执行 `<FILE Run>` |
| 已装标记 | 成功后建软链 `/var/log/plugins/<name>.plg → /boot/config/plugins/<name>.plg` |
| 文件缓存 | `<FILE Name="/boot/config/plugins/<name>/xxx">` 下载后缓存在 flash 上，开机复用不重复下载 |
| 包管理 | Slackware 原生：`installpkg` / `upgradepkg --install-new` / `removepkg` / `makepkg` |
| 卸载 | `<FILE Run="..." Method="remove">` |

> 参考实现：`unraid/unraid-tailscale` 的 `plugin/tailscale.plg`（结构最标准）。

## 二、为什么是「CLI 插件 + 容器」两层

```
┌─────────────────────────────────────────────────────────┐
│  docker CLI  (shipped with Unraid, /usr/bin/docker)     │
│    └── cli-plugins/docker-model   <- installed by this   │
│           │                          plugin              │
│           │ docker model install-runner                  │
│           ▼                                              │
│  container docker-model-runner  (docker/model-runner)    │
│    ├── llama.cpp backend (CPU / CUDA / ROCm / Vulkan)    │
│    ├── volume docker-model-runner-models -> /models      │
│    └── listening on 127.0.0.1:12434 (+ 172.17.0.1:12434) │
└─────────────────────────────────────────────────────────┘
```

**关键点：CLI 插件和 runner 容器是两个独立的东西。**

- 插件（28MB 静态二进制）只负责把 `docker model` 这个子命令加进 docker CLI
- runner（969MB 镜像）才是真正跑推理的容器，由 `docker model install-runner` 拉起

本插件**只分发 CLI 插件**，runner 镜像让用户按需拉取。理由：

1. 969MB 镜像塞进 flash（FAT32）不现实，且 Unraid flash 只有 32GB 上限
2. runner 镜像有 `cpu` / `cuda` / `rocm` / `vllm-cuda` 多个变体，取决于用户硬件
3. 镜像走 `docker pull` 天然支持 registry mirror，插件走文件分发不支持

## 三、安装流程（`.plg` 的 `<FILE Run>`）

```
1. Preflight
   - does /usr/libexec/docker/cli-plugins exist? (Unraid 7.0+ only)
   - has the txz already been downloaded by the plugin installer?
2. upgradepkg --install-new <txz>
   -> installs docker-model into /usr/libexec/docker/cli-plugins/docker-model
3. Remove stale copies left on vfat
   /root/.docker -> /boot/config/plugins/dockerMan (vfat, no chmod support)
   A leftover ~/.docker/cli-plugins/docker-model shadows the system directory
   and fails with "permission denied", so it must be deleted
4. Verify with docker model version
5. Print the next steps (install-runner / gh-proxy usage)
```

**幂等性**：`upgradepkg --install-new` 对已装同版本是 no-op，`rm -f` 对不存在文件是 no-op，
因此开机反复执行安全。

## 四、卸载流程

```
1. If the runner is still running, tell the user to run uninstall-runner
   manually (never auto-remove, to avoid deleting models by accident)
2. removepkg unraid-plugin-docker-model
3. Clean up leftovers (/usr/libexec/... , ~/.docker/... , the cached txz on flash)
```

## 五、构建流程（`scripts/build-plugin.sh`）

```
Packages index ──► read SHA256/Size/Filename
                        │
                        ▼
              curl the deb ──► byte-for-byte SHA256 check
                        │
                        ▼
          pure Python ar parse ──► data.tar.xz ──► extract docker-model
                        │
                        ▼
      stage/usr/libexec/docker/cli-plugins/docker-model + install/slack-desc
                        │
                        ▼
             tar -cJf dist/unraid-plugin-docker-model-<ver>-x86_64-1.txz
                        │
                        ▼
      render dist/unraid-plugin-docker-model.plg (with the txz SHA256)
```

### 为什么必须自己解析 ar

测试机实测：

```
$ which ar dpkg dpkg-deb
which: no ar in (...)
which: no dpkg in (...)
which: no dpkg-deb in (...)
```

Unraid base OS 只有 `tar` / `xz` / `python3`（且 python3 来自 NerdTools，非 base）。
所以脚本用 **Python 优先 + 纯 shell 兜底**：

- Python 路径：按 ar 头固定布局（name[16] + ... + size[10]）逐块切分
- shell 路径：用 `dd` + `wc -c` 做同样的偏移计算

> 注意：构建脚本跑在**开发机**上（macOS/Linux 都行），不是跑在 Unraid 上。
> Unraid 上只需要能 `upgradepkg`，不需要 `ar`。

## 六、镜像拉取：`gh-proxy.org/docker/` 前缀

**实测结论：`docker model pull` 完全不读 `daemon.json` 的 `registry-mirrors`。**

runner 容器内部用 containerd 的 resolver 直连 `registry-1.docker.io`，测试机上表现为：

```
failed to do request: Head "https://registry-1.docker.io/v2/ai/smollm2/manifests/...":
  dial tcp 192.133.77.191:443: connect: connection refused
```

绕过方式：**把代理前缀写进镜像名本身**。

```bash
# models
docker model pull gh-proxy.org/docker/ai/smollm2:360M-Q4_K_M

# runner image (pull and tag it manually before install-runner)
docker pull gh-proxy.org/docker/docker/model-runner:latest
docker tag  gh-proxy.org/docker/docker/model-runner:latest docker/model-runner:latest
docker model install-runner --gpu none
```

已验证 `gh-proxy.org/docker/` 对以下路径均可用：

| 路径 | 结果 |
|---|---|
| `gh-proxy.org/docker/nginx:latest` | ✅ |
| `gh-proxy.org/docker/library/nginx:latest` | ✅ |
| `gh-proxy.org/docker/grafana/grafana:latest` | ✅ |
| `gh-proxy.org/docker/docker/model-runner:latest` | ✅ |
| `gh-proxy.org/docker/ai/smollm2:latest` | ✅ |
| `gh-proxy.org/docker/model-runner:latest`（缺一级） | ❌ 401 denied |

> 规律：**`gh-proxy.org/docker/<registry 路径>`**，即 Docker Hub 上的完整 repo 名。
> `nginx` 这种官方镜像需要 `library/nginx` 或直接 `nginx`（代理会补 `library/`）。

## 七、许可与合规

| 组件 | 许可 | 说明 |
|---|---|---|
| `docker-model` CLI | Apache-2.0 | 来自 Docker Inc.，本插件原样分发二进制，保留 upstream 声明 |
| `docker/model-runner` 镜像 | Apache-2.0 | 由用户自行 `docker pull`，不在本项目分发 |
| llama.cpp（镜像内） | MIT | 由 runner 镜像自带 |
| 本项目脚本/文档 | MIT | |

**分发说明**：`.plg` 的 `CHANGES` 里注明二进制来源与版本，`docs/01-design-decisions.md` 记录校验链，
符合 Apache-2.0 的 NOTICE 要求（上游仓库根目录已有 LICENSE）。

## 八、版本锁定策略

| 锁定项 | 值 | 原因 |
|---|---|---|
| CLI 插件版本 | `1.2.9` | 与 runner 镜像同源 tag，避免协议不匹配 |
| runner 镜像 tag | `latest`（默认）或 `v1.2.9` | `install-runner` 默认拉 `latest`；如需固定可 `MODEL_RUNNER_CONTROLLER_VERSION=v1.2.9` |
| 最低 Unraid | `7.0.0` | 7.0 起才有 `/usr/libexec/docker/cli-plugins` |

**升级 CLI 与镜像要同步**：CLI 1.2.9 对 runner 1.0.0 是兼容的（实测通过），
但跨大版本（如 CLI 1.0 → 1.2）建议一起升。
