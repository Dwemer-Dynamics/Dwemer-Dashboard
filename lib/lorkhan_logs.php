<?php
declare(strict_types=1);

/** Read a bounded tail without exposing an incomplete first line or invalid UTF-8. */
function dashboard_lorkhan_log_tail(string $path, int $maxBytes = 262144, int $maxLines = 200): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return null;
    try {
        $size = fstat($handle)['size'] ?? 0;
        $truncated = $size > $maxBytes;
        if ($truncated) fseek($handle, -$maxBytes, SEEK_END);
        $text = stream_get_contents($handle, $maxBytes);
    } finally { fclose($handle); }
    if (!is_string($text)) return null;
    if ($truncated) $text = str_contains($text, "\n") ? substr($text, strpos($text, "\n") + 1) : '';
    $lines = preg_split('/\R/u', mb_scrub(rtrim($text, "\r\n"), 'UTF-8')) ?: [];
    return implode("\n", array_slice($lines, -$maxLines));
}

/** Remove credential forms before text is rendered, searched, expanded or downloaded. */
function dashboard_lorkhan_redact_log(string $text): string
{
    $text = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [REDACTED]', $text) ?? $text;
    $keys = 'authorization|(?:provider[_-]?)?api[_-]?key|provider[_-]?key|access[_-]?token|pairing[_-]?token|client[_-]?secret|secret|password|token|cookie|set-cookie';
    $text = preg_replace_callback('/((?:"?(?:'.$keys.')"?)\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|(?:Bearer|Basic)\s+[^\s,;]+|[^\s,;]+)/i',
        static fn(array $match): string => $match[1].(str_starts_with($match[2], '"') ? '"[REDACTED]"' : '[REDACTED]'), $text) ?? $text;
    return preg_replace('/([?&](?:key|'.$keys.')=)[^&\s"\x27]+/i', '$1[REDACTED]', $text) ?? $text;
}

