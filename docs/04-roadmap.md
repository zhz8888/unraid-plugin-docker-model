# 路线图

## 状态总览

| 阶段 | 内容 | 状态 |
|---|---|---|
| P0 | 选型评估（apt 解包 vs 源码编译） | ✅ 完成 |
| P0 | 构建脚本 + 插件模板 | ✅ 完成 |
| P1 | 本地构建验证（txz + plg 生成、hash 一致） | ✅ 完成 |
| P2 | 发布仓库（GitHub Release + raw .plg） | ⬜ 待办 |
| P3 | 在真实 Unraid 主机上通过 `.plg` 正式安装验证 | ⬜ 待办 |
| P4 | CA（Community Applications）上架 | ⬜ 待办 |
| P5 | 可选：图形界面（Settings 页） | ⬜ 待办 |

---

## P2 · 发布仓库

仓库已就绪，剩余步骤是打 tag 并发布首个 Release：

1. 打 tag：`git tag 1.2.9 && git push origin 1.2.9`
2. CI（`.github/workflows/build.yml`）会自动构建并附加产物到 Release
3. 确认 Release 里同时有 `unraid-plugin-docker-model-1.2.9-x86_64-1.txz`
   和 `unraid-plugin-docker-model.plg`
4. 确认 `pluginURL` 指向的 raw URL 可访问

手动发布（不走 CI 时）：

```bash
# release command (example)
make build lint verify
gh release create 1.2.9 dist/unraid-plugin-docker-model-1.2.9-x86_64-1.txz \
  --title "unraid-plugin-docker-model 1.2.9" \
  --notes-file CHANGELOG.md
```

**发布后必须跑 `scripts/verify-release.sh <plg-url>`** 确认线上 SHA256 与本地一致。

---

## P3 · 正式安装验证

当前测试机上是**手工**装好的（直接 `cp` 二进制 + `docker tag` 镜像）。
需要用 `.plg` 走一遍完整流程：

```bash
# 1. remove the manually installed copy (no removepkg record existed, delete the file)
ssh root@<unraid-host> "rm -f /usr/libexec/docker/cli-plugins/docker-model"

# 2. upload the plg
scp dist/unraid-plugin-docker-model.plg root@<unraid-host>:/boot/config/plugins/

# 3. install
ssh root@<unraid-host> "plugin install /boot/config/plugins/unraid-plugin-docker-model.plg"

# 4. verify
ssh root@<unraid-host> "docker model version; docker model status"

# 5. reboot to verify persistence
ssh root@<unraid-host> "reboot"
# once it is back up
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
  <Plugin>https://raw.githubusercontent.com/zhz8888/unraid-plugin-docker-model/main/plugin/unraid-plugin-docker-model.plg</Plugin>
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

工作量估计：2–3 天。优先级低于 P2/P3。

---

## 待确认问题

1. **是否要支持 arm64**：Unraid 只跑 x86_64，但脚本已支持 `DMR_ARCH=arm64`。
2. **runner 镜像是否要一并缓存到 flash**：969MB，flash 只有 32GB，建议不缓存。
3. **是否需要多版本并存**：目前 `.plg` 只装一个版本，升级即覆盖。

---

## 维护节奏

上游 `docker-model-plugin` 发版频率约 2–4 周一次。流程：

1. `.github/workflows/upstream-check.yml` 每天检查 apt 索引，发现新版本会自动开 issue
2. 按 issue 提示跑 `DMR_VERSION=x.y.z ./scripts/build-plugin.sh`
3. `make lint && make verify`，并在测试机上 smoke test（`docker model version` + `docker model status`）
4. 更新 `CHANGELOG.md`，合并后打 tag，CI 自动发布 Release
