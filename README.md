# B2 → WordPress XML 导出工具

将 **B2 主题文章**直接导出为 WordPress 官方 **WXR/XML** 格式，方便迁移到其他 WordPress 网站。

## 功能

* 导出文章标题、正文、状态、发布时间等信息
* 保留文章分类、标签、作者
* 自动读取 B2 下载资源
* 自动将下载地址转换为 `[erphpdown]下载地址[/erphpdown]`
* 下载资源自动追加到文章正文底部
* 导出的 XML 可直接使用 WordPress 官方导入器导入

## 使用方法

1. 将 `b2-export.php` 上传到 B2 网站 WordPress 根目录
2. 登录 WordPress 管理员账号
3. 浏览器访问：

`https://你的域名/b2-export.php`

4. 点击 **导出 WordPress XML**
5. 在新站进入 **工具 → 导入 → WordPress**
6. 上传生成的 XML 文件完成迁移
7. 导出完成后删除 `b2-export.php`

## 下载资源格式

支持 B2 原有格式：

```text
资源名称|下载地址|tq=提取码,jy=解压码
```

导出后自动转换为：

```text
[erphpdown]下载地址[/erphpdown]
```

## 注意

本工具仅用于 WordPress/B2 站点迁移，请在导出完成后及时删除服务器上的 `b2-export.php`。
