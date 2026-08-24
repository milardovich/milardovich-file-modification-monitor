<?php
namespace MilardovichFMM\Storage;

class SnapshotStorage
{
    private $table_plugins;
    private $table_themes;

    public function __construct()
    {
        global $wpdb;
        $this->table_plugins = $wpdb->prefix . 'milardovich_fmm_plugins';
        $this->table_themes  = $wpdb->prefix . 'milardovich_fmm_themes';
    }

    public function create_tables()
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        $sql_plugins = "CREATE TABLE {$this->table_plugins} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            plugin_slug varchar(255) NOT NULL,
            file_path text NOT NULL,
            file_hash varchar(64) NOT NULL,
            file_content longtext NOT NULL,
            snapshot_time datetime DEFAULT CURRENT_TIMESTAMP,
            version varchar(20) DEFAULT '',
            PRIMARY KEY  (id),
            KEY plugin_slug (plugin_slug),
            KEY file_hash (file_hash)
        ) {$charset_collate};";

        $sql_themes = "CREATE TABLE {$this->table_themes} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            theme_slug varchar(255) NOT NULL,
            file_path text NOT NULL,
            file_hash varchar(64) NOT NULL,
            file_content longtext NOT NULL,
            snapshot_time datetime DEFAULT CURRENT_TIMESTAMP,
            version varchar(20) DEFAULT '',
            PRIMARY KEY  (id),
            KEY theme_slug (theme_slug),
            KEY file_hash (file_hash)
        ) {$charset_collate};";

        dbDelta($sql_plugins);
        dbDelta($sql_themes);
    }

    public function save_plugin_snapshot($slug, $path, $content, $version)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; there is nothing in the object cache to read or invalidate.
        $wpdb->replace(
            $this->table_plugins,
            [
                'plugin_slug'   => $slug,
                'file_path'     => $path,
                'file_hash'     => hash('sha256', $content),
                'file_content'  => $content,
                'snapshot_time' => current_time('mysql'),
                'version'       => $version,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function save_theme_snapshot($slug, $path, $content, $version)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; there is nothing in the object cache to read or invalidate.
        $wpdb->replace(
            $this->table_themes,
            [
                'theme_slug'    => $slug,
                'file_path'     => $path,
                'file_hash'     => hash('sha256', $content),
                'file_content'  => $content,
                'snapshot_time' => current_time('mysql'),
                'version'       => $version,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function get_plugin_snapshot($slug, $path)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_plugins}
                 WHERE plugin_slug = %s AND file_path = %s
                 ORDER BY snapshot_time DESC LIMIT 1",
                $slug,
                $path
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    public function get_theme_snapshot($slug, $path)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_themes}
                 WHERE theme_slug = %s AND file_path = %s
                 ORDER BY snapshot_time DESC LIMIT 1",
                $slug,
                $path
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Return a map of file_path => file_hash for the latest snapshot of each
     * file, in a single query and without loading the (longtext) file_content.
     * Used for fast change detection on large plugins/themes.
     */
    public function get_plugin_file_hashes($slug)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.file_path, t.file_hash
                 FROM {$this->table_plugins} t
                 INNER JOIN (
                     SELECT file_path, MAX(id) AS max_id
                     FROM {$this->table_plugins}
                     WHERE plugin_slug = %s
                     GROUP BY file_path
                 ) m ON t.id = m.max_id",
                $slug
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $map = [];
        foreach ($rows as $row) {
            $map[$row['file_path']] = $row['file_hash'];
        }
        return $map;
    }

    public function get_theme_file_hashes($slug)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.file_path, t.file_hash
                 FROM {$this->table_themes} t
                 INNER JOIN (
                     SELECT file_path, MAX(id) AS max_id
                     FROM {$this->table_themes}
                     WHERE theme_slug = %s
                     GROUP BY file_path
                 ) m ON t.id = m.max_id",
                $slug
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $map = [];
        foreach ($rows as $row) {
            $map[$row['file_path']] = $row['file_hash'];
        }
        return $map;
    }

    public function get_all_plugin_files($slug)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT file_path FROM {$this->table_plugins} WHERE plugin_slug = %s",
                $slug
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    public function get_all_theme_files($slug)
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the table name is an internal constant built from $wpdb->prefix, so it cannot be passed as a placeholder; every value in these queries is. These are the plugin's own tables and the results are not cacheable across requests.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT file_path FROM {$this->table_themes} WHERE theme_slug = %s",
                $slug
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    public function clear_plugin_snapshots($slug)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; there is nothing in the object cache to read or invalidate.
        $wpdb->delete($this->table_plugins, ['plugin_slug' => $slug], ['%s']);
    }

    public function clear_theme_snapshots($slug)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; there is nothing in the object cache to read or invalidate.
        $wpdb->delete($this->table_themes, ['theme_slug' => $slug], ['%s']);
    }
}
