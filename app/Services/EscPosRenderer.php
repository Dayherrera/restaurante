<?php

namespace App\Services;

use App\Models\PrintJob;

class EscPosRenderer
{
    public function render(PrintJob $job): string
    {
        $output = "\x1B\x40";
        foreach (explode("\n", $job->payload) as $index => $line) {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
            $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $text);
            $style = ($job->line_styles ?? [])[$index] ?? null;
            // Double height keeps the full 32-column width on 58 mm paper.
            if ($style === 'large') $output .= "\x1B\x45\x01\x1D\x21\x01";
            elseif ($style === 'bold') $output .= "\x1B\x45\x01";
            $output .= $text."\n";
            if (in_array($style, ['large', 'bold'], true)) $output .= "\x1D\x21\x00\x1B\x45\x00";
        }

        return $output."\n\n\x1D\x56\x00";
    }
}
