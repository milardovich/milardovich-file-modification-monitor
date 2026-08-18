<?php
namespace WPCodeGuardian\Downloader;

use WPCodeGuardian\Core\Logger;

class WordPressOrgDownloader
{
    private $temp_dir;
    private $ignored_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'pdf', 'zip', 'rar'];
    // Mirrors BaseScanner::$scan_extensions: the baseline must hold exactly
    // the files the disk scan enumerates, or the difference reads as changes.
    private $scan_extensions    = ['php', 'js', 'css', 'json', 'xml', 'html', 'htm', 'txt', 'md'];

    /**
     * Create the downloader, ensuring the working temp directory exists.
     */
    public function __construct()
    {
        $this->temp_dir = trailingslashit(WP_CONTENT_DIR) . 'uploads/wp-code-guardian-temp/';
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
    }

    /**
     * Returns true if the given plugin slug responds 200 on the WordPress.org API.
     */
    public function plugin_exists_on_wporg($plugin_slug)
    {
        $url      = 'https://api.wordpress.org/plugins/info/1.0/' . rawurlencode($plugin_slug) . '.json';
        $response = wp_remote_head($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return false;
        }
        return (int) wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Returns true if the given theme slug responds 200 on the WordPress.org API.
     */
    public function theme_exists_on_wporg($theme_slug)
    {
        $url      = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . rawurlencode($theme_slug);
        $response = wp_remote_head($url, ['timeout' => 15]);
        if (is_wp_error($response)) {
            return false;
        }
        return (int) wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Download and extract a plugin from WordPress.org. Returns the extracted directory or false on failure.
     */
    public function download_plugin_original($plugin_slug, $version = null)
    {
        $url      = 'https://api.wordpress.org/plugins/info/1.0/' . rawurlencode($plugin_slug) . '.json';
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            Logger::log('error fetching plugin info for ' . $plugin_slug . ': ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $info = json_decode($body, true);
        if (!is_array($info) || empty($info['download_link'])) {
            Logger::log('no download_link for plugin ' . $plugin_slug);
            return false;
        }
        $download_url = $info['download_link'];
        if ($version && !empty($info['versions'][$version])) {
            $download_url = $info['versions'][$version];
        }
        return $this->download_and_extract($download_url, $plugin_slug, 'plugin');
    }

    /**
     * Download and extract a theme from WordPress.org. Returns the extracted directory or false on failure.
     */
    public function download_theme_original($theme_slug, $version = null)
    {
        $url      = 'https://api.wordpress.org/themes/info/1.1/?action=theme_information&request[slug]=' . rawurlencode($theme_slug);
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            Logger::log('error fetching theme info for ' . $theme_slug . ': ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $info = json_decode($body, true);
        if (!is_array($info) || empty($info['download_link'])) {
            Logger::log('no download_link for theme ' . $theme_slug);
            return false;
        }
        $download_url = $info['download_link'];
        if ($version && !empty($info['versions'][$version])) {
            $download_url = $info['versions'][$version];
        }
        return $this->download_and_extract($download_url, $theme_slug, 'theme');
    }

    /**
     * Stream a zip to disk, extract it, and return the extracted root directory.
     */
    private function download_and_extract($download_url, $slug, $type)
    {
        $zip_file    = $this->temp_dir . $slug . '-' . $type . '.zip';
        $extract_dir = $this->temp_dir . $slug . '-' . $type . '/';

        $response = wp_remote_get($download_url, [
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $zip_file,
        ]);
        if (is_wp_error($response)) {
            Logger::log('zip download failed for ' . $slug . ': ' . $response->get_error_message());
            if (file_exists($zip_file)) {
                unlink($zip_file);
            }
            return false;
        }

        if (!class_exists('ZipArchive')) {
            Logger::log('ZipArchive is not available');
            if (file_exists($zip_file)) {
                unlink($zip_file);
            }
            return false;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_file) !== true) {
            Logger::log('could not open zip for ' . $slug);
            if (file_exists($zip_file)) {
                unlink($zip_file);
            }
            return false;
        }

        if (file_exists($extract_dir)) {
            $this->delete_directory($extract_dir);
        }
        wp_mkdir_p($extract_dir);

        $zip->extractTo($extract_dir);
        $zip->close();
        if (file_exists($zip_file)) {
            unlink($zip_file);
        }

        $entries = array_values(array_diff(scandir($extract_dir) ?: [], ['.', '..']));
        if (count($entries) === 1 && is_dir($extract_dir . $entries[0])) {
            return $extract_dir . $entries[0];
        }
        return $extract_dir;
    }

    /**
     * Walk a downloaded plugin/theme tree and return file entries skipping the ignored binary extensions.
     */
    public function scan_extracted_files($directory)
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $base_path = rtrim($directory, '/\\');
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, $this->ignored_extensions, true)) {
                continue;
            }
            // Keep the baseline to the same extensions the disk scan walks,
            // otherwise the extra files register as deletions forever.
            if (!in_array($ext, $this->scan_extensions, true)) {
                continue;
            }
            $full_path = $file->getPathname();
            $rel_path  = ltrim(str_replace($base_path, '', $full_path), '/\\');
            $files[] = [
                'path'      => $rel_path,
                'full_path' => $full_path,
                'content'   => file_get_contents($full_path),
            ];
        }
        return $files;
    }

    /**
     * Remove a working directory if it still exists.
     */
    public function cleanup($directory)
    {
        if (file_exists($directory)) {
            $this->delete_directory($directory);
        }
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function delete_directory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Derive a slug from a plugin file path: "akismet/akismet.php" -> "akismet"; "hello.php" -> "hello".
     */
    public function get_plugin_slug_from_file($plugin_file)
    {
        $dir = dirname($plugin_file);
        if ($dir === '.' || $dir === '' || $dir === DIRECTORY_SEPARATOR) {
            return basename($plugin_file, '.php');
        }
        return $dir;
    }
}
