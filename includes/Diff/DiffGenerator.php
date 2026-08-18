<?php
namespace WPCodeGuardian\Diff;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

class DiffGenerator
{
    private $differ;

    public function __construct()
    {
        $builder = new UnifiedDiffOutputBuilder("--- Original\n+++ Modified\n", false);
        $this->differ = new Differ($builder);
    }

    public function generate($original, $modified)
    {
        if ($original === '' && $modified === '') {
            return '';
        }
        try {
            return $this->differ->diff((string) $original, (string) $modified);
        } catch (\Exception $e) {
            return 'Error generating diff: ' . $e->getMessage();
        }
    }

    public function format_for_html($diff)
    {
        $out = [];
        if ($diff === '') {
            return $out;
        }
        $lines = explode("\n", $diff);
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $class  = 'diff-context';
            $prefix = '';

            if (strpos($line, '+++') === 0) {
                $class = 'diff-header-new';
            } elseif (strpos($line, '---') === 0) {
                $class = 'diff-header-old';
            } elseif (strpos($line, '@@') === 0) {
                $class = 'diff-range';
            } elseif (isset($line[0]) && $line[0] === '+') {
                $class  = 'diff-added';
                $prefix = '+';
            } elseif (isset($line[0]) && $line[0] === '-') {
                $class  = 'diff-removed';
                $prefix = '-';
            }

            $out[] = [
                'line'   => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                'class'  => $class,
                'prefix' => $prefix,
            ];
        }
        return $out;
    }

    public function get_stats($diff)
    {
        $additions = 0;
        $deletions = 0;
        if ($diff === '') {
            return [
                'additions'     => 0,
                'deletions'     => 0,
                'files_changed' => 0,
            ];
        }
        $lines = explode("\n", $diff);
        foreach ($lines as $line) {
            if (strpos($line, '+++') === 0 || strpos($line, '---') === 0) {
                continue;
            }
            if (isset($line[0]) && $line[0] === '+') {
                $additions++;
            } elseif (isset($line[0]) && $line[0] === '-') {
                $deletions++;
            }
        }
        return [
            'additions'     => $additions,
            'deletions'     => $deletions,
            'files_changed' => 0,
        ];
    }
}
