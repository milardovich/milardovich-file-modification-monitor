<?php
namespace WPCodeGuardian\Scanner;

use WPCodeGuardian\Storage\SnapshotStorage;
use WPCodeGuardian\Diff\DiffGenerator;
use WPCodeGuardian\Downloader\WordPressOrgDownloader;
use WPCodeGuardian\Core\Logger;

class ThemeScanner extends BaseScanner
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
        $themes = wp_get_themes();
        foreach ($themes as $slug => $theme) {
            $this->create_snapshot($slug);
        }
        return true;
    }

    public function create_snapshot($theme_slug)
    {
        $this->last_source_remote = false;
        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            return false;
        }
        $version = (string) $theme->get('Version');

        if ($this->downloader->theme_exists_on_wporg($theme_slug)) {
            $extract_dir = $this->downloader->download_theme_original($theme_slug, $version);
            if ($extract_dir) {
                $files = $this->downloader->scan_extracted_files($extract_dir);
                foreach ($files as $f) {
                    $this->storage->save_theme_snapshot($theme_slug, $f['path'], $f['content'], $version);
                }
                $this->downloader->cleanup(dirname($extract_dir));
                Logger::log('Created baseline for ' . $theme_slug . ' from WordPress.org');
                $this->last_source_remote = true;
                return true;
            }
        }

        Logger::log('Could not download from WordPress.org, using current files for ' . $theme_slug);

        $dir   = $theme->get_stylesheet_directory();
        $files = $this->scan_directory($dir, $dir);
        foreach ($files as $f) {
            $this->storage->save_theme_snapshot($theme_slug, $f['path'], $f['content'], $version);
        }
        return true;
    }

    public function has_changes($theme_slug)
    {
        list($disk_files, $stored_hashes) = $this->gather_for_comparison($theme_slug);
        return $this->detect_has_changes($disk_files, $stored_hashes);
    }

    public function count_changes($theme_slug)
    {
        list($disk_files, $stored_hashes) = $this->gather_for_comparison($theme_slug);
        return count($this->detect_changed_paths($disk_files, $stored_hashes));
    }

    /**
     * Resolve the on-disk file map and the stored baseline hash map for a
     * theme, ready for fast hash-based comparison (no file contents loaded).
     *
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private function gather_for_comparison($theme_slug)
    {
        $theme = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            return [[], []];
        }
        $stored_hashes = $this->storage->get_theme_file_hashes($theme_slug);
        $dir           = $theme->get_stylesheet_directory();
        $disk_files    = $this->enumerate_files($dir, $dir);

        return [$disk_files, $stored_hashes];
    }

    public function get_changes($theme_slug)
    {
        $changes = [];
        $theme   = wp_get_theme($theme_slug);
        if (!$theme->exists()) {
            return $changes;
        }
        $dir           = $theme->get_stylesheet_directory();
        $current_files = $this->scan_directory($dir, $dir);

        $seen_paths = [];
        foreach ($current_files as $file) {
            $seen_paths[$file['path']] = true;
            $snapshot = $this->storage->get_theme_snapshot($theme_slug, $file['path']);
            if ($snapshot) {
                $current_hash = hash('sha256', $file['content']);
                if ($current_hash !== $snapshot['file_hash']) {
                    $changes[] = [
                        'file'        => $file['path'],
                        'diff'        => $this->diff_generator->generate($snapshot['file_content'], $file['content']),
                        'old_content' => $snapshot['file_content'],
                        'new_content' => $file['content'],
                        'is_new'      => false,
                        'is_deleted'  => false,
                    ];
                }
            } else {
                $changes[] = [
                    'file'        => $file['path'],
                    'diff'        => $this->diff_generator->generate('', $file['content']),
                    'old_content' => '',
                    'new_content' => $file['content'],
                    'is_new'      => true,
                    'is_deleted'  => false,
                ];
            }
        }

        $stored = $this->storage->get_all_theme_files($theme_slug);
        foreach ($stored as $row) {
            $path = $row['file_path'];
            if (!isset($seen_paths[$path])) {
                $snapshot = $this->storage->get_theme_snapshot($theme_slug, $path);
                if ($snapshot) {
                    $changes[] = [
                        'file'        => $path,
                        'diff'        => $this->diff_generator->generate($snapshot['file_content'], ''),
                        'old_content' => $snapshot['file_content'],
                        'new_content' => '',
                        'is_new'      => false,
                        'is_deleted'  => true,
                    ];
                }
            }
        }

        return $changes;
    }

    public function has_stored_snapshots($theme_slug)
    {
        $rows = $this->storage->get_all_theme_files($theme_slug);
        return !empty($rows);
    }

    public function refresh_snapshot($theme_slug)
    {
        $this->storage->clear_theme_snapshots($theme_slug);
        $this->create_snapshot($theme_slug);
        return [
            'remote' => $this->last_source_remote,
        ];
    }

    public function last_source_was_remote()
    {
        return $this->last_source_remote;
    }
}
