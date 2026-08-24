<?php
namespace MilardovichFMM\Scanner;

use MilardovichFMM\Storage\SnapshotStorage;
use MilardovichFMM\Diff\DiffGenerator;
use MilardovichFMM\Downloader\WordPressOrgDownloader;
use MilardovichFMM\Core\Logger;

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

        $this->snapshot_from_disk($plugin_file, $version);
        return true;
    }

    /**
     * Store the files currently on disk as the baseline. Used as the fallback
     * when WordPress.org has nothing for us, and by accept_changes().
     */
    private function snapshot_from_disk($plugin_file, $version = '')
    {
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

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
            return;
        }
        $dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
        foreach ($this->scan_directory($dir, $dir) as $f) {
            $this->storage->save_plugin_snapshot($plugin_slug, $f['path'], $f['content'], $version);
        }
    }

    private function get_base_dir($plugin_file)
    {
        if (dirname($plugin_file) === '.' || dirname($plugin_file) === '') {
            return WP_PLUGIN_DIR;
        }
        return WP_PLUGIN_DIR . '/' . dirname($plugin_file);
    }

    /**
     * Adopt the current files as the baseline, so the local edits stop being
     * reported. Note this is NOT what refresh_snapshot() does: that one goes
     * back to WordPress.org and would flag the same edits again.
     */
    public function accept_changes($plugin_file)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        $version     = '';
        if (file_exists($plugin_path)) {
            $data    = get_plugin_data($plugin_path, false, false);
            $version = isset($data['Version']) ? $data['Version'] : '';
        }

        $this->storage->clear_plugin_snapshots($plugin_slug);
        $this->snapshot_from_disk($plugin_file, $version);
        Logger::log('Accepted local changes as the new baseline for ' . $plugin_slug);
        return true;
    }

    /**
     * Put the baseline copy back on disk: modified files are overwritten,
     * files deleted locally are recreated, and files added locally are
     * removed. Destructive by design -- the caller confirms first.
     */
    public function restore_from_baseline($plugin_file)
    {
        $plugin_slug = $this->downloader->get_plugin_slug_from_file($plugin_file);
        $base_dir    = $this->get_base_dir($plugin_file);
        $result      = ['restored' => 0, 'removed' => 0, 'failed' => 0];

        list($disk_files, $stored_hashes) = $this->gather_for_comparison($plugin_file);

        foreach ($this->detect_changed_paths($disk_files, $stored_hashes) as $path) {
            if (isset($stored_hashes[$path])) {
                $snapshot = $this->storage->get_plugin_snapshot($plugin_slug, $path);
                if (!$snapshot) {
                    $result['failed']++;
                    continue;
                }
                if ($this->write_baseline_file($base_dir, $path, $snapshot['file_content'])) {
                    $result['restored']++;
                } else {
                    $result['failed']++;
                }
            } elseif ($this->delete_local_file($base_dir, $path)) {
                $result['removed']++;
            } else {
                $result['failed']++;
            }
        }

        Logger::log(sprintf(
            'Restored %s from baseline: %d rewritten, %d removed, %d failed',
            $plugin_slug,
            $result['restored'],
            $result['removed'],
            $result['failed']
        ));
        return $result;
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
