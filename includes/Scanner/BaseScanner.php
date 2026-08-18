<?php
namespace WPCodeGuardian\Scanner;

use WPCodeGuardian\Storage\SnapshotStorage;
use WPCodeGuardian\Diff\DiffGenerator;

abstract class BaseScanner
{
    protected $storage;
    protected $diff_generator;
    protected $ignored_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'pdf', 'zip', 'rar'];
    protected $scan_extensions    = ['php', 'js', 'css', 'json', 'xml', 'html', 'htm', 'txt', 'md'];

    public function __construct(SnapshotStorage $storage, DiffGenerator $diff_generator)
    {
        $this->storage        = $storage;
        $this->diff_generator = $diff_generator;
    }

    protected function should_scan_file($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '') {
            return false;
        }
        if (in_array($ext, $this->ignored_extensions, true)) {
            return false;
        }
        if (!empty($this->scan_extensions) && !in_array($ext, $this->scan_extensions, true)) {
            return false;
        }
        return true;
    }

    /**
     * Enumerate scannable files in a directory without reading their contents.
     * Returns a map of relative path => absolute path.
     */
    protected function enumerate_files($directory, $base_path)
    {
        $files = [];
        if (!is_dir($directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $base = rtrim($base_path, '/\\');
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            if (!$this->should_scan_file($full)) {
                continue;
            }
            $rel = ltrim(str_replace($base, '', $full), '/\\');
            $files[$rel] = $full;
        }
        return $files;
    }

    protected function scan_directory($directory, $base_path)
    {
        $files = [];
        foreach ($this->enumerate_files($directory, $base_path) as $rel => $full) {
            $files[] = [
                'path'      => $rel,
                'full_path' => $full,
                'content'   => file_get_contents($full),
            ];
        }
        return $files;
    }

    /**
     * Fast change detection: compares on-disk file hashes against the stored
     * baseline hashes, short-circuiting on the first difference. Never loads
     * file contents from the DB nor generates diffs — used for the boolean
     * "has changes?" checks that drive the dashboard counters and badges.
     *
     * @param array $disk_files    Map of relative path => absolute path.
     * @param array $stored_hashes Map of relative path => sha256 hash.
     */
    protected function detect_has_changes(array $disk_files, array $stored_hashes)
    {
        $stored_hashes = $this->filter_scannable($stored_hashes);
        $seen = [];
        foreach ($disk_files as $rel => $full) {
            $seen[$rel] = true;
            if (!isset($stored_hashes[$rel])) {
                return true; // new file on disk
            }
            if (hash_file('sha256', $full) !== $stored_hashes[$rel]) {
                return true; // modified file
            }
        }
        foreach ($stored_hashes as $rel => $hash) {
            if (!isset($seen[$rel])) {
                return true; // file deleted from disk
            }
        }
        return false;
    }

    /**
     * Like detect_has_changes() but returns the list of changed relative paths
     * (modified, new and deleted) without generating diffs. Used to count
     * changes for the listing tables; the actual diffs are produced on demand
     * by get_changes() when the user opens the diff modal.
     *
     * @param array $disk_files    Map of relative path => absolute path.
     * @param array $stored_hashes Map of relative path => sha256 hash.
     * @return string[] Changed relative paths.
     */
    protected function detect_changed_paths(array $disk_files, array $stored_hashes)
    {
        $stored_hashes = $this->filter_scannable($stored_hashes);
        $changed = [];
        $seen    = [];
        foreach ($disk_files as $rel => $full) {
            $seen[$rel] = true;
            if (!isset($stored_hashes[$rel]) || hash_file('sha256', $full) !== $stored_hashes[$rel]) {
                $changed[] = $rel;
            }
        }
        foreach ($stored_hashes as $rel => $hash) {
            if (!isset($seen[$rel])) {
                $changed[] = $rel;
            }
        }
        return $changed;
    }

    /**
     * Drop baseline entries the disk side never looks at. The WordPress.org
     * download stores every non-binary file it finds, while enumerate_files()
     * only walks the scannable extensions -- so .scss, .ts, .csv and friends
     * would otherwise be reported as deleted on every single comparison.
     *
     * @param array $stored_hashes Map of relative path => sha256 hash.
     */
    protected function filter_scannable(array $stored_hashes)
    {
        $filtered = [];
        foreach ($stored_hashes as $rel => $hash) {
            if ($this->should_scan_file($rel)) {
                $filtered[$rel] = $hash;
            }
        }
        return $filtered;
    }

    /**
     * Reject anything that would write outside the item's own directory or
     * touch a file type the scanner does not track. Relative paths come out
     * of the database, and the item they belong to comes from a request, so
     * neither is trusted here.
     *
     * @return string|false Absolute path, or false when it is not safe.
     */
    protected function resolve_writable_path($base_dir, $rel_path)
    {
        if ($rel_path === '' || !$this->should_scan_file($rel_path)) {
            return false;
        }
        $segments = preg_split('#[/\\\\]+#', $rel_path);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '') {
                return false;
            }
        }
        $base = realpath($base_dir);
        if ($base === false) {
            return false;
        }
        return $base . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Write a baseline file back over the copy on disk, creating any missing
     * directories on the way (a file deleted locally takes its folder with it).
     */
    protected function write_baseline_file($base_dir, $rel_path, $content)
    {
        $target = $this->resolve_writable_path($base_dir, $rel_path);
        if ($target === false) {
            return false;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }
        return file_put_contents($target, $content) !== false;
    }

    /**
     * Remove a file that exists on disk but not in the baseline.
     */
    protected function delete_local_file($base_dir, $rel_path)
    {
        $target = $this->resolve_writable_path($base_dir, $rel_path);
        if ($target === false) {
            return false;
        }
        if (!file_exists($target)) {
            return true;
        }
        return unlink($target);
    }

    abstract public function scan_all();
    abstract public function create_snapshot($item);
    abstract public function has_changes($item);
    abstract public function get_changes($item);
    abstract public function has_stored_snapshots($item);
}
