<?php
/**
 * B2 → WordPress 官方 WXR/XML 导出工具
 *
 * 功能：
 * 1. 导出 B2 文章为 WordPress 官方 WXR 1.2 格式
 * 2. 自动读取 B2 下载资源
 * 3. 自动转换为 [erphpdown]下载地址[/erphpdown]
 * 4. 下载资源追加到文章正文底部
 * 5. 新站可以直接使用 WordPress 官方「WordPress 导入器」导入
 *
 * 使用方法：
 * 1. 将本文件上传到 WordPress 根目录（与 wp-load.php 同级）
 * 2. 浏览器访问：https://你的域名/b2-export.php
 * 3. 点击「导出 WordPress XML」
 * 4. 导入完成后立即删除本文件
 */

declare(strict_types=1);

// ---------------------------------------------------------
// 加载 WordPress
// ---------------------------------------------------------

$wp_load = __DIR__ . '/wp-load.php';

if (!file_exists($wp_load)) {
    exit('错误：请把 b2-export.php 放在 WordPress 根目录。');
}

require_once $wp_load;


// ---------------------------------------------------------
// 权限检查
// ---------------------------------------------------------

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    status_header(403);
    exit('无权限：请先登录 WordPress 管理员账号后再访问。');
}

global $wpdb;


// ---------------------------------------------------------
// PHP 设置
// ---------------------------------------------------------

@set_time_limit(0);

if (function_exists('ini_set')) {
    @ini_set('memory_limit', '512M');
}


// ---------------------------------------------------------
// XML CDATA 安全处理
// ---------------------------------------------------------

function sky_xml_cdata(string $value): string {

    // XML CDATA 不能直接包含 ]]>
    $value = str_replace(
        ']]>',
        ']]]]><![CDATA[>',
        $value
    );

    return '<![CDATA[' . $value . ']]>';
}


// ---------------------------------------------------------
// XML 属性安全处理
// ---------------------------------------------------------

function sky_xml_attr(string $value): string {

    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_XML1,
        'UTF-8'
    );
}


// ---------------------------------------------------------
// 解析 B2 下载资源
//
// 格式：
// 资源名称|下载地址|tq=提取码,jy=解压码
// ---------------------------------------------------------

function sky_parse_b2_resource(string $line): array {

    $line = trim($line);

    if ($line === '') {
        return ['', '', '', ''];
    }

    $parts = explode('|', $line, 3);

    $name = trim($parts[0] ?? '');
    $url  = trim($parts[1] ?? '');
    $code = trim($parts[2] ?? '');

    $tq = '';
    $jy = '';

    if ($code !== '') {

        foreach (preg_split('/\s*,\s*/', $code) as $item) {

            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (preg_match('/^tq\s*=\s*(.*)$/i', $item, $m)) {

                $tq = trim($m[1]);

            } elseif (preg_match('/^jy\s*=\s*(.*)$/i', $item, $m)) {

                $jy = trim($m[1]);
            }
        }
    }

    return [
        $name,
        $url,
        $tq,
        $jy
    ];
}


// ---------------------------------------------------------
// 获取 B2 下载资源
//
// CMB2：
// b2_single_post_download_group
// ---------------------------------------------------------

function sky_get_b2_resources(int $post_id): array {

    $raw = get_post_meta(
        $post_id,
        'b2_single_post_download_group',
        true
    );

    if (empty($raw)) {
        return [];
    }

    if (is_string($raw)) {
        $raw = maybe_unserialize($raw);
    }

    if (!is_array($raw)) {
        return [];
    }

    $resources = [];

    foreach ($raw as $group) {

        if (!is_array($group)) {
            continue;
        }

        $url_text = isset($group['url'])
            ? (string) $group['url']
            : '';

        if ($url_text === '') {
            continue;
        }

        // 一个 url 字段支持多行资源
        $lines = preg_split('/\R/u', $url_text);

        foreach ($lines as $line) {

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [
                $name,
                $url,
                $tq,
                $jy
            ] = sky_parse_b2_resource($line);

            if ($name === '' && $url === '') {
                continue;
            }

            $resources[] = [
                'name' => $name,
                'url'  => $url,
                'tq'   => $tq,
                'jy'   => $jy,
            ];
        }
    }

    return $resources;
}


// ---------------------------------------------------------
// 构建 Erphpdown 下载区域
// ---------------------------------------------------------

function sky_build_erphpdown_content(array $resources): string {

    if (!$resources) {
        return '';
    }

    $html = '';

    $html .= "\n\n";
    $html .= '<div class="sky-erphpdown-downloads">';
    $html .= "\n";

    $html .= '<p><strong>下载资源</strong></p>';
    $html .= "\n";

    foreach ($resources as $resource) {

        $name = trim((string) ($resource['name'] ?? ''));
        $url  = trim((string) ($resource['url'] ?? ''));
        $tq   = trim((string) ($resource['tq'] ?? ''));
        $jy   = trim((string) ($resource['jy'] ?? ''));

        // 没有下载地址，不生成短代码
        if ($url === '') {
            continue;
        }

        // 资源名称
        if ($name !== '') {

            $html .= '<p>';
            $html .= '<strong>';
            $html .= esc_html($name);
            $html .= '</strong>';
            $html .= '</p>';
            $html .= "\n";
        }

        // Erphpdown 短代码
        $html .= '[erphpdown]';
        $html .= $url;
        $html .= '[/erphpdown]';
        $html .= "\n";

        // 提取码
        if ($tq !== '') {

            $html .= '<p>';
            $html .= '提取码：';
            $html .= esc_html($tq);
            $html .= '</p>';
            $html .= "\n";
        }

        // 解压码
        if ($jy !== '') {

            $html .= '<p>';
            $html .= '解压码：';
            $html .= esc_html($jy);
            $html .= '</p>';
            $html .= "\n";
        }

        $html .= "<br>\n";
    }

    $html .= '</div>';

    return $html;
}


// ---------------------------------------------------------
// 获取文章作者登录名
// ---------------------------------------------------------

function sky_get_author_login(int $user_id): string {

    if ($user_id > 0) {

        $user = get_userdata($user_id);

        if ($user && !empty($user->user_login)) {
            return (string) $user->user_login;
        }
    }

    // 找不到作者时使用当前管理员
    $current_user = wp_get_current_user();

    if ($current_user && !empty($current_user->user_login)) {
        return (string) $current_user->user_login;
    }

    return 'admin';
}


// ---------------------------------------------------------
// 获取文章分类
// ---------------------------------------------------------

function sky_get_post_categories(int $post_id): array {

    $terms = get_the_category($post_id);

    if (!$terms || is_wp_error($terms)) {
        return [];
    }

    $result = [];

    foreach ($terms as $term) {

        $result[] = [
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
        ];
    }

    return $result;
}


// ---------------------------------------------------------
// 获取文章标签
// ---------------------------------------------------------

function sky_get_post_tags(int $post_id): array {

    $terms = get_the_tags($post_id);

    if (!$terms || is_wp_error($terms)) {
        return [];
    }

    $result = [];

    foreach ($terms as $term) {

        $result[] = [
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
        ];
    }

    return $result;
}


// ---------------------------------------------------------
// 导出
// ---------------------------------------------------------

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'xml'
) {

    @set_time_limit(0);

    // 防止之前输出任何内容
    if (ob_get_level()) {
        while (ob_get_level()) {
            @ob_end_clean();
        }
    }

    // -----------------------------------------------------
    // 获取站点信息
    // -----------------------------------------------------

    $site_url = home_url('/');
    $site_name = get_bloginfo('name');

    $filename =
        'wordpress-b2-export-' .
        date('Y-m-d_H-i-s') .
        '.xml';


    // -----------------------------------------------------
    // HTTP Header
    // -----------------------------------------------------

    header('Content-Type: application/xml; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="' .
        $filename .
        '"'
    );

    header('Pragma: no-cache');
    header('Expires: 0');


    // -----------------------------------------------------
    // XML 开始
    // -----------------------------------------------------

    echo '<?xml version="1.0" encoding="UTF-8" ?>';
    echo "\n";

    echo '<!-- WordPress WXR file generated by B2 Export Tool -->';
    echo "\n";

    echo '<rss version="2.0"';
    echo ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"';
    echo ' xmlns:content="http://purl.org/rss/1.0/modules/content/"';
    echo ' xmlns:wfw="http://wellformedweb.org/CommentAPI/"';
    echo ' xmlns:dc="http://purl.org/dc/elements/1.1/"';
    echo ' xmlns:wp="http://wordpress.org/export/1.2/"';
    echo '>';
    echo "\n";

    echo "<channel>\n";

    echo '<title>';
    echo sky_xml_cdata($site_name);
    echo "</title>\n";

    echo '<link>';
    echo sky_xml_cdata($site_url);
    echo "</link>\n";

    echo '<description>';
    echo sky_xml_cdata(get_bloginfo('description'));
    echo "</description>\n";

    echo '<pubDate>';
    echo esc_html(
        gmdate('D, d M Y H:i:s +0000')
    );
    echo "</pubDate>\n";

    echo '<language>';
    echo esc_html(
        get_bloginfo('language')
    );
    echo "</language>\n";

    echo '<wp:wxr_version>1.2</wp:wxr_version>';
    echo "\n";

    echo '<wp:base_site_url>';
    echo sky_xml_cdata($site_url);
    echo "</wp:base_site_url>\n";

    echo '<wp:base_blog_url>';
    echo sky_xml_cdata($site_url);
    echo "</wp:base_blog_url>\n";


    // -----------------------------------------------------
    // 获取作者
    // -----------------------------------------------------

    $authors = get_users([
        'fields' => [
            'ID',
            'user_login',
            'user_email',
            'display_name'
        ],
    ]);

    foreach ($authors as $author) {

        echo "<wp:author>\n";

        echo '<wp:author_id>';
        echo (int) $author->ID;
        echo "</wp:author_id>\n";

        echo '<wp:author_login>';
        echo sky_xml_cdata(
            (string) $author->user_login
        );
        echo "</wp:author_login>\n";

        echo '<wp:author_email>';
        echo sky_xml_cdata(
            (string) $author->user_email
        );
        echo "</wp:author_email>\n";

        echo '<wp:author_display_name>';
        echo sky_xml_cdata(
            (string) $author->display_name
        );
        echo "</wp:author_display_name>\n";

        echo "</wp:author>\n";
    }


    // -----------------------------------------------------
    // 获取全部文章
    // -----------------------------------------------------

    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => [
            'publish',
            'draft',
            'pending',
            'private',
            'future'
        ],
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'all',
    ]);


    // -----------------------------------------------------
    // 收集分类和标签
    // -----------------------------------------------------

    $all_categories = [];
    $all_tags = [];

    foreach ($posts as $post) {

        $categories =
            sky_get_post_categories((int) $post->ID);

        foreach ($categories as $category) {

            $key = $category['slug'];

            $all_categories[$key] = $category;
        }


        $tags =
            sky_get_post_tags((int) $post->ID);

        foreach ($tags as $tag) {

            $key = $tag['slug'];

            $all_tags[$key] = $tag;
        }
    }


    // -----------------------------------------------------
    // 输出分类
    // -----------------------------------------------------

    foreach ($all_categories as $category) {

        echo "<wp:category>\n";

        echo '<wp:term_id>';
        echo '0';
        echo "</wp:term_id>\n";

        echo '<wp:category_nicename>';
        echo sky_xml_cdata(
            $category['slug']
        );
        echo "</wp:category_nicename>\n";

        echo '<wp:category_parent>';
        echo '';
        echo "</wp:category_parent>\n";

        echo '<wp:cat_name>';
        echo sky_xml_cdata(
            $category['name']
        );
        echo "</wp:cat_name>\n";

        echo "</wp:category>\n";
    }


    // -----------------------------------------------------
    // 输出标签
    // -----------------------------------------------------

    foreach ($all_tags as $tag) {

        echo "<wp:tag>\n";

        echo '<wp:term_id>';
        echo '0';
        echo "</wp:term_id>\n";

        echo '<wp:tag_slug>';
        echo sky_xml_cdata(
            $tag['slug']
        );
        echo "</wp:tag_slug>\n";

        echo '<wp:tag_name>';
        echo sky_xml_cdata(
            $tag['name']
        );
        echo "</wp:tag_name>\n";

        echo "</wp:tag>\n";
    }


    // -----------------------------------------------------
    // 输出文章
    // -----------------------------------------------------

    $count_posts = 0;
    $count_resources = 0;

    foreach ($posts as $post) {

        $count_posts++;

        $post_id = (int) $post->ID;

        // B2 下载资源
        $resources =
            sky_get_b2_resources($post_id);

        if ($resources) {
            $count_resources += count($resources);
        }

        // 原正文
        $content =
            (string) $post->post_content;

        // -------------------------------------------------
        // 自动生成 Erphpdown 内容
        // -------------------------------------------------

        $download_content =
            sky_build_erphpdown_content(
                $resources
            );

        // -------------------------------------------------
        // 下载资源追加到正文底部
        // -------------------------------------------------

        if ($download_content !== '') {

            $content .= $download_content;
        }


        // -------------------------------------------------
        // 作者
        // -------------------------------------------------

        $author_login =
            sky_get_author_login(
                (int) $post->post_author
            );


        // -------------------------------------------------
        // 分类
        // -------------------------------------------------

        $categories =
            sky_get_post_categories($post_id);


        // -------------------------------------------------
        // 标签
        // -------------------------------------------------

        $tags =
            sky_get_post_tags($post_id);


        // -------------------------------------------------
        // 时间
        // -------------------------------------------------

        $post_date =
            (string) $post->post_date;

        $post_date_gmt =
            (string) $post->post_date_gmt;

        if ($post_date_gmt === '0000-00-00 00:00:00') {

            $timestamp =
                strtotime($post_date);

            if ($timestamp !== false) {

                $post_date_gmt =
                    gmdate(
                        'Y-m-d H:i:s',
                        $timestamp
                    );
            }
        }


        // -------------------------------------------------
        // 开始 item
        // -------------------------------------------------

        echo "<item>\n";


        // 标题
        echo '<title>';
        echo sky_xml_cdata(
            (string) $post->post_title
        );
        echo "</title>\n";


        // 原始文章 URL
        echo '<link>';
        echo sky_xml_cdata(
            (string) get_permalink($post_id)
        );
        echo "</link>\n";


        // 作者
        echo '<dc:creator>';
        echo sky_xml_cdata(
            $author_login
        );
        echo "</dc:creator>\n";


        // 分类
        foreach ($categories as $category) {

            echo '<category domain="category" nicename="';
            echo sky_xml_attr(
                $category['slug']
            );
            echo '">';

            echo sky_xml_cdata(
                $category['name']
            );

            echo "</category>\n";
        }


        // 标签
        foreach ($tags as $tag) {

            echo '<category domain="post_tag" nicename="';
            echo sky_xml_attr(
                $tag['slug']
            );
            echo '">';

            echo sky_xml_cdata(
                $tag['name']
            );

            echo "</category>\n";
        }


        // 正文
        echo '<content:encoded>';
        echo sky_xml_cdata(
            $content
        );
        echo "</content:encoded>\n";


        // 摘要
        echo '<excerpt:encoded>';
        echo sky_xml_cdata(
            (string) $post->post_excerpt
        );
        echo "</excerpt:encoded>\n";


        // 发布时间
        echo '<pubDate>';
        echo esc_html(
            gmdate(
                'D, d M Y H:i:s +0000',
                strtotime($post_date)
            )
        );
        echo "</pubDate>\n";


        // WordPress 内部字段
        echo '<wp:post_id>';
        echo $post_id;
        echo "</wp:post_id>\n";

        echo '<wp:post_date>';
        echo sky_xml_cdata(
            $post_date
        );
        echo "</wp:post_date>\n";

        echo '<wp:post_date_gmt>';
        echo sky_xml_cdata(
            $post_date_gmt
        );
        echo "</wp:post_date_gmt>\n";

        echo '<wp:post_modified>';
        echo sky_xml_cdata(
            (string) $post->post_modified
        );
        echo "</wp:post_modified>\n";

        echo '<wp:post_modified_gmt>';
        echo sky_xml_cdata(
            (string) $post->post_modified_gmt
        );
        echo "</wp:post_modified_gmt>\n";

        echo '<wp:comment_status>';
        echo sky_xml_cdata(
            (string) $post->comment_status
        );
        echo "</wp:comment_status>\n";

        echo '<wp:ping_status>';
        echo sky_xml_cdata(
            (string) $post->ping_status
        );
        echo "</wp:ping_status>\n";

        echo '<wp:post_name>';
        echo sky_xml_cdata(
            (string) $post->post_name
        );
        echo "</wp:post_name>\n";

        echo '<wp:status>';
        echo sky_xml_cdata(
            (string) $post->post_status
        );
        echo "</wp:status>\n";

        echo '<wp:post_parent>';
        echo (int) $post->post_parent;
        echo "</wp:post_parent>\n";

        echo '<wp:menu_order>';
        echo (int) $post->menu_order;
        echo "</wp:menu_order>\n";

        echo '<wp:post_type>';
        echo sky_xml_cdata(
            (string) $post->post_type
        );
        echo "</wp:post_type>\n";

        echo '<wp:post_password>';
        echo sky_xml_cdata(
            (string) $post->post_password
        );
        echo "</wp:post_password>\n";

        echo '<wp:is_sticky>';
        echo (
            get_post_meta(
                $post_id,
                '_sticky',
                true
            )
            ? '1'
            : '0'
        );
        echo "</wp:is_sticky>\n";


        // -------------------------------------------------
        // item 结束
        // -------------------------------------------------

        echo "</item>\n";
    }


    // -----------------------------------------------------
    // XML 结束
    // -----------------------------------------------------

    echo "</channel>\n";
    echo "</rss>\n";

    exit;
}


// ---------------------------------------------------------
// 页面
// ---------------------------------------------------------

$nonce = wp_create_nonce('sky_b2_export');

?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>B2 → WordPress XML 导出</title>

<style>

body{
    font-family:
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    Arial,
    sans-serif;

    background:#f5f6f7;

    margin:0;

    padding:40px;

    color:#222;
}

.box{
    max-width:720px;

    margin:0 auto;

    background:#fff;

    padding:30px;

    border-radius:12px;

    box-shadow:
    0 4px 20px rgba(0,0,0,.06);
}

h1{
    font-size:22px;
    margin-top:0;
}

p{
    line-height:1.7;
    color:#555;
}

.btn{
    display:inline-block;

    background:#2271b1;

    color:#fff;

    text-decoration:none;

    padding:12px 22px;

    border-radius:6px;

    font-size:15px;
}

.warn{
    background:#fff4e5;

    border-left:
    4px solid #f0ad4e;

    padding:12px 15px;

    margin:20px 0;

    line-height:1.7;
}

.info{
    background:#eef7ff;

    border-left:
    4px solid #2271b1;

    padding:12px 15px;

    margin:20px 0;

    line-height:1.7;
}

.success{
    background:#edf9f0;

    border-left:
    4px solid #46b450;

    padding:12px 15px;

    margin:20px 0;

    line-height:1.7;
}

code{
    background:#f1f1f1;

    padding:2px 5px;

    border-radius:3px;
}

</style>

</head>

<body>

<div class="box">

<h1>B2 → WordPress 官方 XML 导出</h1>

<p>
本工具会将 B2 网站文章直接导出为
<strong>WordPress 官方 WXR/XML 格式</strong>。
</p>

<div class="info">

<strong>导出内容：</strong><br>

文章标题、正文、状态、发布时间、文章别名、
分类、标签、作者等 WordPress 文章信息。

</div>

<div class="success">

<strong>下载资源自动转换：</strong><br>

B2 下载资源会自动转换为：

<br><br>

<code>[erphpdown]下载地址[/erphpdown]</code>

<br><br>

并追加到文章正文底部。

</div>

<p>
导出的 XML 可以直接在新站后台进入：
<br>
<strong>工具 → 导入 → WordPress</strong>
<br>
然后导入此 XML 文件。
</p>

<a
    class="btn"
    href="?export=xml&_wpnonce=<?php
        echo esc_attr($nonce);
    ?>"
    onclick="return confirm(
        '确定导出全站文章为 WordPress XML 吗？\\n\\n文章较多时可能需要一些时间。'
    );"
>
    导出 WordPress XML
</a>

<div class="warn">

<strong>安全提示：</strong><br>

导出完成后，请立即从网站服务器删除
<code>b2-export.php</code>。

</div>

</div>

</body>
</html>