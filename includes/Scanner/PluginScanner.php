<?php
namespace WPCodeGuardian\Scanner;

use WPCodeGuardian\Storage\SnapshotStorage;
use WPCodeGuardian\Diff\DiffGenerator;
use WPCodeGuardian\Downloader\WordPressOrgDownloader;
use WPCodeGuardian\Core\Logger;

class PluginScanner extends BaseScanner
{
    private $downloader;
    private $last_source_remote = false;

    public function __construct(SnapshotStorage $storage, DiffGenerator $diff_generator)
    {
        parent::__construct($storage, $diff_generator);
        $this->downloader = new WordPressOrgDownloader();
    }

    public function scan_all()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        foreach ($plugins as $plugin_file => $data) {
            $this->create_snapshot($plugin_file);
        }
        return true;
    }

    public function create_snapshot($plugin_file)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->last_source_remote = false;
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        $version     = '';
        if (file_exists($plugin_path)) {
            $data    = get_plugin_data($plugin_path, false, false);
            $version = isset($data['Version']) ? $data['Version'] : '';
        }

        if ($this->downloader->plugin_exists_on_wporg($plugin_slug)) {
            $extract_dir = $this->downloader->download_plugin_original($plugin_slug, $version);
            if ($extract_dir) {
                $files = $this->downloader->scan_extracted_files($extract_dir);
                foreach ($files as $f) {
                    $this->storage->save_plugin_snapshot($plugin_slug, $f['path'], $f['content'], $version);
                }
                $this->downloader->cleanup(dirname($extract_dir));
                Logger::log('Created baseline for ' . $plugin_slug . ' from WordPress.org');
                $this->last_source_remote = true;
                return true;
            }
        }

        Logger::log('Could not download from WordPress.org, using current files for ' . $plugin_slug);

        // Fallback: snapshot current disk contents.
        if (dirname($plugin_file) === '.' || dirname($plugin_file) === '') {
            // Single-file plugin
            if (file_exists($plugin_path)) {
                $this->storage->save_plugin_snapshot(
                    $plugin_slug,
                    basename($plugin_file),
                    file_get_contents($plugin_path),
                    $version
                );
            }
        } else {
            $dir   = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            $files = $this->scan_directory($dir, $dir);
            foreach ($files as $f) {
                $this->storage->save_plugin_snapshot($plugin_slug, $f['path'], $f['content'], $version);
            }
        }
        return true;
    }

    public function has_changes($plugin_file)
    {
        list($disk_files, $stored_hashes) = $this->gather_for_comparison($plugin_file);
        return $this->detect_has_changes($disk_files, $stored_hashes);
    }

    public function count_changes($plugin_file)
    {
        list($disk_files, $stored_hashes) = $this->gather_for_comparison($plugin_file);
        return count($this->detect_changed_paths($disk_files, $stored_hashes));
    }

    /**
     * Resolve the on-disk file map and the stored baseline hash map for a
     * plugin, ready for fast hash-based comparison (no file contents loaded).
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function gather_for_comparison($plugin_file)
    {
        $plugin_slug   = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $stored_hashes = $this->storage->get_plugin_file_hashes($plugin_slug);

        if (dirname($plugin_file) === '.' || dirname($plugin_file) === '') {
            // Single-file plugin
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $disk_files  = file_exists($plugin_path) ? [basename($plugin_file) => $plugin_path] : [];
        } else {
            $dir        = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            $disk_files = $this->enumerate_files($dir, $dir);
        }

        return [$disk_files, $stored_hashes];
    }

    /**
     * Full diffs for a plugin. The changed paths are resolved from hashes
     * first, so only those files have their contents pulled back out of the
     * baseline: a large plugin is overwhelmingly made of untouched files, and
     * fetching a row per file is what made this take minutes.
     */
    public function get_changes($plugin_file)
    {
        $changes     = [];
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);

        list($disk_files, $stored_hashes) = $this->gather_for_comparison($plugin_file);

        foreach ($this->detect_changed_paths($disk_files, $stored_hashes) as $path) {
            $on_disk     = isset($disk_files[$path]);
            $in_baseline = isset($stored_hashes[$path]);

            $new_content = $on_disk ? file_get_contents($disk_files[$path]) : '';
            $old_content = '';
            if ($in_baseline) {
                $snapshot    = $this->storage->get_plugin_snapshot($plugin_slug, $path);
                $old_content = $snapshot ? $snapshot['file_content'] : '';
            }

            $changes[] = [
                'file'        => $path,
                'diff'        => $this->diff_generator->generate($old_content, $new_content),
                'old_content' => $old_content,
                'new_content' => $new_content,
                'is_new'      => !$in_baseline,
                'is_deleted'  => !$on_disk,
            ];
        }

        return $changes;
    }

    public function has_stored_snapshots($plugin_file)
    {
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $rows        = $this->storage->get_all_plugin_files($plugin_slug);
        return !empty($rows);
    }

    public function refresh_snapshot($plugin_file)
    {
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $this->storage->clear_plugin_snapshots($plugin_slug);
        $this->create_snapshot($plugin_file);
        return [
            'remote' => $this->last_source_remote,
        ];
    }

    public function last_source_was_remote()
    {
        return $this->last_source_remote;
    }
}
