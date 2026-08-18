<?php
namespace WPCodeGuardian\Downloader;

use WPCodeGuardian\Core\Logger;

class WordPressOrgDownloader
{
    private $temp_dir = null;
    private $ignored_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'pdf', 'zip', 'rar'];
    // Mirrors BaseScanner::$scan_extensions: the baseline must hold exactly
    // the files the disk scan enumerates, or the difference reads as changes.
    private $scan_extensions    = ['php', 'js', 'css', 'json', 'xml', 'html', 'htm', 'txt', 'md'];

    /**
     * Work outside the web root. Extracting a plugin zip under uploads/ would
     * leave its PHP files reachable over HTTP until cleanup ran, so the
     * scratch space is the system temp directory instead. Created lazily:
     * the downloader is constructed on every request, but only the scan path
     * ever needs somewhere to unpack to.
     */
    private function temp_dir()
    {
        if ($this->temp_dir === null) {
            $this->temp_dir = trailingslashit(get_temp_dir()) . 'wp-code-guardian/';
        }
        if (!is_dir($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
        return $this->temp_dir;
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
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // unzip_file() does not bootstrap WP_Filesystem itself; it expects the
        // caller to have done it, and returns "Could not access filesystem"
        // otherwise.
        global $wp_filesystem;
        if (!($wp_filesystem instanceof \WP_Filesystem_Base) && !WP_Filesystem()) {
            Logger::log('WP_Filesystem unavailable, cannot unpack ' . $slug);
            return false;
        }

        $extract_dir = $this->temp_dir() . $slug . '-' . $type . '/';

        // download_url() and unzip_file() are core's own routines for this:
        // they stream to a temp file and unpack through WP_Filesystem, with a
        // PclZip fallback where ZipArchive is missing.
        $zip_file = download_url($download_url, 300);
        if (is_wp_error($zip_file)) {
            Logger::log('zip download failed for ' . $slug . ': ' . $zip_file->get_error_message());
            return false;
        }

        if (is_dir($extract_dir)) {
            $this->delete_directory($extract_dir);
        }
        wp_mkdir_p($extract_dir);

        $unzipped = unzip_file($zip_file, $extract_dir);
        wp_delete_file($zip_file);

        if (is_wp_error($unzipped)) {
            Logger::log('could not unpack zip for ' . $slug . ': ' . $unzipped->get_error_message());
            $this->delete_directory($extract_dir);
            return false;
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
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        global $wp_filesystem;
        if (!($wp_filesystem instanceof \WP_Filesystem_Base) && !WP_Filesystem()) {
            return;
        }
        // WP_Filesystem::delete() recurses for us, so there is no manual walk
        // and no direct rmdir()/unlink() call to justify.
        $wp_filesystem->delete(trailingslashit($dir), true);
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
