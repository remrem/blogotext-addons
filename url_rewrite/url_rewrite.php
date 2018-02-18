<?php
# *** LICENSE ***
# This file is a addon for BlogoText.
# You can redistribute it under the terms of the MIT / X11 Licence.
# *** LICENSE ***


/**
 * Changelog
 *
 * 1.0.0 2018/02/15 @RemRem
 *  - first release
 */

/**
 * DevNote
 *
 * 2018/02/15 @RemRem
 *   The v1 is not really the best solution, waiting for other possibilities with BT 3.8:
 *     - use of regex on all HTML content.
 *     - management of the db on the public rendering
 *     - No options to clean the db (article removal)
 */

$declaration = array(
    'tag' => 'url_rewrite',

    'name' => array(
        'en' => 'URL rewriter',
        'fr' => 'Réécriture d`URL',
    ),

    'desc' => array(
        'en' => 'Simplifies the format of URLs for articles. See the README file.',
        'fr' => 'Simplifie le format des URLs pour les articles. Voir le fichier README.',
    ),

    'url' => 'https://blogotext.org/',

    'version' => '1.0.0',
    'compliancy' => '3.7',

    'settings' => array(
        'url_article' => array(
            'type' => 'text',
            'label' => array(
                'en' => 'Parameter name in the URL (?example=)',
                'fr' => 'Nom du paramétre dans l`URL (?example=)'
            ),
            'value' => 'article',
        ),
    ),

    'hook-push' => array(
        'system-start' => array(
                'callback' => 'a_url_rewrite_on_boot',
                'priority' => 200
            ),
        'conversion_theme_addons_end' => array(
                'callback' => 'a_url_rewrite_converter_html',
                'priority' => 200
            ),
        'before_show_rss_no_cache' => array(
                'callback' => 'a_url_rewrite_converter_xml',
                'priority' => 200
            ),
        'before_show_atom_no_cache' => array(
                'callback' => 'a_url_rewrite_converter_xml',
                'priority' => 200
            ),
    )
);


function a_url_rewrite_converter_xml($hook_datas)
{
    if (!a_url_rewrite_init()) {
        return $hook_datas;
    }

    foreach ($hook_datas['1'] as &$article) {
        if ($article['bt_type'] == 'article') {
            $article['bt_link'] = URL_ROOT.'?'.$GLOBALS['addon_url_rewrite']['url_article'].'='.a_url_rewrite_slug($article['bt_link']);
        }
    }
    return $hook_datas;
}

/**
 * rewrite all URL in HTML content
 * based on href="...
 * called by hook
 *
 * @params array $args, the arguments from the hook
 * @return array, the modified $args
 */
function a_url_rewrite_converter_html($hook_datas)
{
    // return $hook_datas;
    if (!a_url_rewrite_init()) {
        return $hook_datas;
    }

    $content = $hook_datas['1'];

    // first, try to replace, quickly
    foreach ($GLOBALS['addon_url_rewrite']['db'] as $new => $old) {
        $hook_datas['1'] = str_replace('?d='.$old, '?'.$GLOBALS['addon_url_rewrite']['url_article'].'='.$new, $hook_datas['1']);
    }

    $regex_url_root = str_replace(array('http://' ,'https://'), 'http[s]?://', URL_ROOT);
    // some regex, try to target BT articles URL
    $regex = '@href=(["\'])('.$regex_url_root.'|/)?\?d=([0-9/]{19,}[a-zA-Z0-9\-_]*)["\']?@';
    // a more specific regex
    // $regex = '@(["\'])?('.$regex_url_root.'|/)?\?d=([0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{2}/[0-9]{2}/[0-9]{2}-[a-zA-Z0-9\-_]*)@';

    $db_count = count($GLOBALS['addon_url_rewrite']['db']);
    // try to find URL not in the addon db
    $hook_datas['1'] = preg_replace_callback(
        $regex,
        function($found)
        {
            return 'href='.$found['1'].URL_ROOT.'?'.$GLOBALS['addon_url_rewrite']['url_article'].'='.a_url_rewrite_slug($found['3']).$found['1'];
        },
        $hook_datas['1']
    );

    // new url found ?
    if ($db_count != count($GLOBALS['addon_url_rewrite']['db'])) {
        a_url_rewrite_db_build();
    }

    return $hook_datas;
}

/**
 * convert a blogotext article URL to a rewrited URL
 *
 * @params string $source_url
 * @params string
 */
function a_url_rewrite_slug($source_url)
{
    $source_url = trim(mb_strtolower(str_replace(URL_ROOT, '', $source_url)));
    $source_url = trim(mb_strtolower(str_replace('?d=', '', $source_url)));

    // check for url anchor #
    $anchor = '';
    if (strpos($source_url, '#') !== false) {
        list($source_url, $anchor) = explode('#', $source_url);
        $anchor = '#'.$anchor;
    }

    // source url already in db, return the new url
    if (in_array($source_url, $GLOBALS['addon_url_rewrite']['db'])) {
        return array_search($source_url, $GLOBALS['addon_url_rewrite']['db']).$anchor;
    }

    // format a basic new url
    $new_url = preg_replace('@^([0-9]{4}/[0-9]{2}/[0-9]{2}/[0-9]{2}/[0-9]{2}/[0-9]{2})-@', '', $source_url);

    // test the new url (must be uniq)
    $add = '';
    $i = 0;
    while (isset($GLOBALS['addon_url_rewrite']['db'][$new_url.$add])) {
        ++$i;
        $add = '-'.$i;
    }

    // return the final url
    return $new_url.$add.$anchor;
}

/**
 * init the addon
 * set the db path, load the db...
 */
function a_url_rewrite_init()
{
    if (isset($GLOBALS['addon_url_rewrite']['db'])) {
        return true;
    }

    $GLOBALS['addon_url_rewrite']['db_path'] = addon_get_vhost_cache_path('url_rewrite').'/db_urls_articles.php';
    $GLOBALS['addon_url_rewrite']['db'] = array();
    $GLOBALS['addon_url_rewrite']['url_article'] = addon_get_setting('url_rewrite', 'url_article');

    if (!file_exists($GLOBALS['addon_url_rewrite']['db_path'])) {
        a_url_rewrite_db_build();
    }

    // $db = unserialize(file_get_contents($GLOBALS['addon_url_rewrite']['db_path']));
    $db = json_decode(file_get_contents($GLOBALS['addon_url_rewrite']['db_path']), true);
    if (!is_array($db)) {
        return false;
    }
    $GLOBALS['addon_url_rewrite']['db'] = $db;

    return true;
}

/**
 * Build the addon db
 * read the BT db to get all articles urls
 */
function a_url_rewrite_db_build()
{
    a_url_rewrite_init();

    // blog article
    $db_article = addon_get_vhost_cache_path('url_rewrite').'/db_urls_articles.php';

    /* work on article */
    // list all articles url (older to newer)
    $query = 'SELECT `ID`, `bt_date`, `bt_id`, `bt_title`, `bt_link` 
                FROM `articles` 
               WHERE `bt_date` <= '.date('YmdHis').' 
                 AND `bt_statut` = 1 
            ORDER BY `bt_date` ASC';
    $originals = liste_elements($query, array(), 'articles');

    $articles = array();
    $GLOBALS['addon_url_rewrite']['db'] = array();

    foreach ($originals as $original) {
        $source_url = trim(mb_strtolower(str_replace(URL_ROOT.'?d=', '', $original['bt_link'])));
        if (!in_array($source_url, $GLOBALS['addon_url_rewrite']['db'])) {
            // convert title to url
            $new_url = a_url_rewrite_slug($source_url);
             // push in db
            $GLOBALS['addon_url_rewrite']['db'][$new_url] = $source_url;
        }
    }

    // write the file
    $article_writed = a_url_rewrite_db_write($db_article);
}

/**
 * write the addon db
 */
function a_url_rewrite_db_write($path)
{
    // return (file_put_contents($path, serialize($db)) !== false);
    return (file_put_contents($path, json_encode($GLOBALS['addon_url_rewrite']['db'], JSON_PRETTY_PRINT)) !== false);
}

/**
 * convert an rewrited URL to a BT URL
 * Loaded on the BT boot.
 */
function a_url_rewrite_on_boot()
{
    if (!isset($_GET['article']) // request article ?
     || !a_url_rewrite_init() // load db
    ) {
        return true;
    }

    // sanitize the requested URL
    $addon_url = htmlspecialchars($_GET['article'], ENT_QUOTES);

    // check if in the addon db
    if (!isset($GLOBALS['addon_url_rewrite']['db'][$addon_url])) {
        return true;
    }

    // convert the url
    $_GET['d'] = $GLOBALS['addon_url_rewrite']['db'][$addon_url];

    return true;
}
