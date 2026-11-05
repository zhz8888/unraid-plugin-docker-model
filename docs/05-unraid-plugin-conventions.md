# Unraid 官方插件规范对照

本文记录本插件与 Unraid 官方插件规范的逐条对照关系：每一条规范是什么、官方依据在哪里、
本项目怎么落地、以及哪些点仍然无法验证。

对照对象是 Limetech 官方维护的插件与官方安装器源码：

| 参考对象 | 地址 |
|---|---|
| 官方安装器源码 | `unraid/webgui` → `emhttp/plugins/dynamix.plugin.manager/scripts/plugin`（990 行 PHP CLI） |
| 官方插件示例 | [`unraid/unraid-tailscale`](https://github.com/unraid/unraid-tailscale) |
| 官方插件示例 | [`unraid/apprise-plugin`](https://github.com/unraid/apprise-plugin) |
| 官方插件示例 | [`unraid/unraid-nvidia-driver`](https://github.com/unraid/unraid-nvidia-driver) |
| 第三方插件示例 | [`johnpwhite/unraid-plg-zram`](https://github.com/johnpwhite/unraid-plg-zram) |
| CA 收录指南 | <https://ca.unraid.net/submit/help> |

> 安装器在运行时位于 `/usr/local/sbin/plugin`，它是指向
> `/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin` 的软链。

---

## 一、硬性要求（违反即失效）

### 1.1 版本号必须能用 `strcmp()` 正确排序

官方安装器比较插件版本用的是 **字节序字符串比较**，不是语义化比较：

```php
if (strcmp($version, $installed_version) < 0) {
    write("plugin: not installing older version\n");
```

这意味着语义化版本会出事：`strcmp("1.2.10", "1.2.9") < 0`，也就是
**1.2.10 会被 Unraid 判定成比 1.2.9 更旧而拒绝安装**。

> 本项目此前的 `version="1.2.9"`（跟随上游版本号）正好踩在这个坑里：上游一旦发布
> 1.2.10，用户就收不到更新。

**本项目的做法**：插件版本改用官方同款日期格式 `YYYY.MM.DD.HHMM`
（固定宽度，字节序与时间序一致），上游版本另存为 `upstream` 实体：

```xml
<!ENTITY version  "2026.11.05.2050">
<!ENTITY upstream "1.2.9">
```

### 1.2 安装脚本绝不能返回非零

安装器把 `<FILE Run=...>` 的退出码透传；非零会中止整次安装：

```php
if ($retval != 0) {
    $error = "run failed: '$command' returned $retval";
    return false;
}
```

而 `plugin install` 失败时：

```php
if (plugin('install', $plugin_file, $error) === false) {
    write("plugin: $error\n");
    if (dirname($plugin_file) == "$boot") {
        move($plugin_file, "$boot-error");     // -> /boot/config/plugins-error/
    }
    post_hooks($error);
    done(1);
}
```

后果链：`/var/log/plugins/<name>.plg` 软链**不会建立** → WebGUI 的
`glob("/var/log/plugins/*.plg")` 扫不到 → 插件不出现在「Plugins」页 →
**用户连卸载按钮都没有**；同时 `.plg` 被移走，下次开机也不会再被处理。

> 本项目此前的安装脚本在预检失败时 `exit 1`，正好会触发这条路径。

**本项目的做法**：安装脚本所有失败分支都打印明确错误后 `exit 0`，脚本末尾统一
`exit 0`。插件保持「已注册」状态，用户可以在 WebGUI 里卸载或更新。

### 1.3 安装脚本必须幂等

开机时 `/etc/rc.d/rc.local` 会对 `/boot/config/plugins/*.plg` 逐个执行
`/usr/local/sbin/plugin install`：

```sh
for PLUGIN in $CONFIG/plugins/*.plg; do
    PLUGIN_NAME=$(basename "$PLUGIN")
    /usr/local/sbin/plugin install "$PLUGIN" | log
done
```

「已安装」登记表在 `/var/log/plugins/`，而 `/var/log` 是 tmpfs
（`rc.S`：`mount -t tmpfs -o size=128m tmpfs /var/log`），开机即清空；
`/usr` 本身是 squashfs + RAM overlay，同样不跨重启。
所以**每次开机都是全新安装，安装脚本每次都会执行**。

**本项目的做法**：安装脚本用 `upgradepkg --install-new`、所有清理用 `rm -f`，
重复执行安全；flash 上旧包在每次安装后清掉。

### 1.4 `<FILE Name=...>` 指向的文件不会被覆盖

安装器只在「文件不存在」或「校验和不匹配」时重新下载。**版本号必须写进缓存文件名**，
否则新版本不会生效。

**本项目的做法**：`unraid-plugin-docker-model-<插件版本>-x86_64-1.txz`，
文件名带版本，每次发布都是新路径。

### 1.5 `<FILE>` 下载必须带 `<SHA256>`

官方插件仍在用 `<MD5>`，现代第三方插件都用 `<SHA256>`。安装器两者都认，且
`<SHA256>` 优先。

**本项目的做法**：`<SHA256>` 固定 txz 哈希；`<!ENTITY binarySHA256 ...>` 额外固定
二进制本身的哈希，安装脚本在装完后会再校验一次。

### 1.6 必须提供卸载路径

安装器自身只删 `/var/log/plugins` 软链、把 `.plg` 移到
`/boot/config/plugins-removed/`，其余要插件自己清理。

**本项目的做法**：`<FILE Run="/bin/bash" Method="remove">` 调 `removepkg`，
再清掉二进制、残留副本、WebGUI 目录和 flash 缓存。**刻意不删** runner 容器、
镜像和模型卷，避免误删用户数据。

### 1.7 WebGUI 页面放在 `/usr/local/emhttp/plugins/<name>/`

插件 `name` 属性对应的目录约定：
`/usr/local/emhttp/plugins/<name>/` 放页面与图标，
`/boot/config/plugins/<name>/` 放持久化配置。

**本项目的做法**：目录名与插件名一致（`unraid-plugin-docker-model`），
WebGUI 页面在包里，随 txz 一起安装。

### 1.8 CA 收录的硬性要求

| 要求 | 本项目状态 |
|---|---|
| 仓库公开且未归档 | ✅ |
| 仓库根目录有 OSI 认可的 `LICENSE` | ✅ MIT |
| 仓库根目录有 `ca_profile.xml`，且 `<Profile>` 非空 | ✅ 见 `ca_profile.xml` |
| 每个插件一个 wrapper XML，含 `<Name>` 与 `<PluginURL>` | ✅ 见 `ca/unraid-plugin-docker-model.xml` |
| wrapper 的 `<PluginURL>` 与 `.plg` 的 `pluginURL` **完全一致** | ✅ 两者都是 `releases/latest/download/unraid-plugin-docker-model.plg` |
| `.plg` 已带 `support` 时，wrapper 的 `<Support>` 可选 | ✅ 两者都有 |
| 兼容性窗口写在 `.plg` 的 `min`/`max` 里 | ✅ `min="7.0.0"` |

---

## 二、最佳实践（官方惯例）

| # | 惯例 | 官方依据 | 本项目落地 |
|---|---|---|---|
| 1 | 一个插件只发布一个 `.plg` | 安装器按 `.plg` **文件名**建软链；两个同名 `.plg` 会在「Plugins」页显示两条 | 发布一个主 `.plg`；代理版仅供无法直连 GitHub 的网络使用，主版本安装时自动删除代理版（与官方 tailscale 处理 main/preview 分支的做法一致） |
| 2 | 用 XML 实体避免重复字面量 | `unraid/apprise-plugin` | `.plg` 用 `<!ENTITY>` 定义 name/version/URL/哈希 |
| 3 | `<CHANGES>` 以 `##<name>` 开头、每版一个 `###<version>` | `unraid/apprise-plugin` 的自检脚本 | 已对齐 |
| 4 | 提供 `author` / `support` / `icon` / `launch` | 安装器 usage 文本 | 全部提供；`launch="Settings/DockerModelRunner"` 指向本插件的 `.page` 文件，`icon` 解析到插件目录下的 PNG |
| 5 | 插件目录放 `README.md`，Plugins 页会渲染成说明 | `ShowPlugins.php` | 包内含 `README.md`（含版本占位符，构建时替换） |
| 6 | 用 Slackware 包路线（`upgradepkg` / `removepkg`），不要手拷二进制 | 三个官方插件都这么做 | `src/` 是包源码树，构建时打进 txz |
| 7 | 提供 `settings.json` 与 `diagnostics.json` | `unraid/unraid-tailscale` | 已提供 |
| 8 | 提供 `<Name>.page` 页面文件 | Dynamix 页面机制 | `DockerModelRunner.page` + `DockerModelRunner-1-Settings.page` |
| 9 | PHP 用 `$docroot` 解析路径 | `unraid/plugin`、`PluginHelpers.php` | `.page` 文件统一走 `$docroot` |
| 10 | 安装脚本不能有交互提示 | 安装器用 `popen()` 非交互执行 | 卸载 runner 只给提示，不自动执行 |
| 11 | `donate` / `webUI` 属性**不被安装器识别** | 全仓库检索无对应处理 | 不使用这两个属性 |
| 12 | `Type="hook"` **不是合法的 `<FILE>` 属性** | 安装器只认 `Type="base64"` | 不使用；真正的钩子是 `dynamix.plugin.manager` 的 pre/post-hooks 或插件目录下的 `event/` 脚本 |
| 13 | **CDATA 里的实体引用不会被展开** | XML 规范：CDATA 段是字面文本 | `.plg` 里 CDATA（`<CHANGES>`、`<INLINE>`）一律用构建期占位符 `@@...@@`；实体只用在属性值和元素文本里。`lint-plg.sh` 会检查这一点 |

### 两种占位机制的分工（容易踩坑）

| 位置 | 机制 | 例子 |
|---|---|---|
| `<PLUGIN>` 属性 | XML 实体（解析器展开） | `name="&name;"` |
| `<FILE Name=...>` 属性 | XML 实体 | `Name="/boot/config/plugins/&name;/&txzName;"` |
| `<URL>`、`<SHA256>` 元素文本 | XML 实体 | `<URL>&txzURL;</URL>` |
| `<CHANGES>` 里的 CDATA | **构建期占位符** | `##@@PLUGIN_NAME@@` |
| `<INLINE>` 里的 CDATA | **构建期占位符** | `PLUGIN_NAME="@@PLUGIN_NAME@@"` |

CDATA 里的 `&name;` 不会被 XML 解析器展开，会原样写进安装脚本里，脚本就废了。
官方 `unraid/apprise-plugin` 的 `<INLINE>` 故意**不**用 CDATA（它靠实体展开），
官方 `unraid/unraid-tailscale` 的 `<INLINE>` 用 CDATA（它里面是字面量路径）。
本项目两者混用：属性用实体，CDATA 用占位符，并用 `lint-plg.sh` 把这条规则固定下来。

---

## 三、本次修订修掉的问题

| # | 问题 | 影响 | 修订 |
|---|---|---|---|
| 1 | 插件版本等于上游版本 | 上游发 1.2.10 时 `strcmp` 判为更旧，用户收不到更新 | 改为日期版本号，上游版本独立记录 |
| 2 | 安装脚本预检失败时 `exit 1` | 安装中止，`.plg` 被移到 `plugins-error/`，插件无法卸载 | 全部失败分支改为提示 + `exit 0` |
| 3 | 清理了 `/root/.docker` 之外的 Docker CLI 插件目录缺失 | 其他目录里的同名副本会**遮蔽**已安装的二进制 | 按 Docker CLI 的查找顺序清理全部 4 个目录 |
| 4 | flash 上的旧 txz 从不清理 | flash 空间被历次安装的包占满 | 每次安装后清掉旧包 |
| 5 | 没有 `install/doinst.sh` | 目标目录缺失时安装会失败 | 增加 `doinst.sh`，确保目录与权限 |
| 6 | 没有 WebGUI 页面 | 不是完整的一等插件 | 增加 Settings 页（状态 + 部署 runner） |
| 7 | 没有图标、没有插件说明 | Plugins 页显示默认图标与空白说明 | 增加图标与包内 `README.md` |
| 8 | 没有 CA 收录所需文件 | 无法提交 CA | 增加 `ca_profile.xml` 与 wrapper XML |

---

## 四、仍未验证的点

以下内容无法在本地环境确认，需要在真实 Unraid 主机上验证：

1. **`.plg` 安装器全流程尚未在真机跑通**。`docs/03-verification-log.md` 记录的
   真机验证是**手工**装二进制得到的，不是走 `.plg` 装的。`docs/04-roadmap.md` 的
   P3 仍然是下一步。
2. **WebGUI 页面未在真机验证**。页面按官方 `.page` 约定编写，但没有在 Unraid
   的 Dynamix 里实际渲染过。
3. **开机重装行为**依赖官方安装器源码（已逐行核对）与 `rc.local`（已核对），
   但没有在本机上观察到实际的开机日志。
4. **CA 的分类名** `Tools:System` 取自已收录的 `unraid-plg-zram`，但提交前应以
   CA 的字段参考为准。
5. **`launch` 的取值**取自官方示例的 `Settings/<页面文件名>` 形式
   （`Settings/Tailscale`、`Settings/UnraidZramCard`），但没有文档明确写出
   Dynamix 的路由规则。
6. **代理版 `.plg` 与主版共存**时的清理顺序依赖文件名字典序
   （`unraid-plugin-docker-model-proxy.plg` 排在 `unraid-plugin-docker-model.plg`
   之前），未经真机验证。

---

## 五、参考资料

- 安装器源码：<https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/scripts/plugin>
- 开机流程：<https://github.com/unraid/webgui/blob/master/etc/rc.d/rc.local>
- 插件列表页：<https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/include/ShowPlugins.php>
- CA 收录指南：<https://ca.unraid.net/submit/help>
- CA 仓库说明：<https://ca.unraid.net/submit/help/repository-info-xml>
- CA 字段参考：<https://ca.unraid.net/submit/help/xml-field-reference>
- 官方插件示例：<https://github.com/unraid/unraid-tailscale>、
  <https://github.com/unraid/apprise-plugin>、
  <https://github.com/unraid/unraid-nvidia-driver>
