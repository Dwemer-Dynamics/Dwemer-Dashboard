<?php
declare(strict_types=1);

error_reporting(E_ERROR);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sanitizeId(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? $value;
    $value = trim($value, '_');
    return $value === '' ? 'log' : $value;
}

function candidateVariants(string $path): array
{
    $variants = [trim($path)];
    if (DIRECTORY_SEPARATOR === '\\') {
        $variants[] = str_replace('/', '\\', $path);
    } else {
        $variants[] = str_replace('\\', '/', $path);
    }
    $variants = array_values(array_unique(array_filter($variants, static fn($item) => trim((string)$item) !== '')));
    return $variants;
}

function resolveExistingPath(array $candidates): string
{
    foreach ($candidates as $candidate) {
        foreach (candidateVariants((string)$candidate) as $variant) {
            if (is_file($variant)) {
                return $variant;
            }
        }
    }
    return '';
}

function buildFileCandidates(array $directories, array $filenames): array
{
    $candidates = [];
    foreach ($directories as $dir) {
        $dirValue = trim((string)$dir);
        if ($dirValue === '') {
            continue;
        }
        $normalizedDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dirValue), DIRECTORY_SEPARATOR);
        foreach ($filenames as $filename) {
            $fileValue = trim((string)$filename);
            if ($fileValue === '') {
                continue;
            }
            $normalizedFile = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fileValue), DIRECTORY_SEPARATOR);
            $candidates[] = $normalizedDir . DIRECTORY_SEPARATOR . $normalizedFile;
        }
    }

    return array_values(array_unique(array_filter($candidates, static fn($item) => trim((string)$item) !== '')));
}

function tailFile(string $filepath, int $lines = 2000): array
{
    if (!is_file($filepath) || !is_readable($filepath)) {
        return [];
    }

    $file = @fopen($filepath, 'r');
    if (!$file) {
        return [];
    }

    $bufferSize = 4096;
    $output = [];
    $chunk = '';

    fseek($file, 0, SEEK_END);
    $position = ftell($file);
    if ($position === false) {
        fclose($file);
        return [];
    }

    while ($position > 0 && count($output) < $lines) {
        $readLength = min($position, $bufferSize);
        $position -= $readLength;
        fseek($file, $position);
        $chunk = fread($file, $readLength) . $chunk;

        while (($newlinePos = strrpos($chunk, "\n")) !== false && count($output) < $lines) {
            $line = rtrim(substr($chunk, $newlinePos + 1), "\r\n");
            array_unshift($output, $line);
            $chunk = substr($chunk, 0, $newlinePos);
        }
    }

    $chunk = rtrim($chunk, "\r\n");
    if ($chunk !== '' && count($output) < $lines) {
        array_unshift($output, $chunk);
    }

    fclose($file);
    return $output;
}

function normalizeLogLevel(string $value): string
{
    $level = strtolower(trim($value));
    if ($level === '') {
        return '';
    }

    $map = [
        'warning' => 'warn',
        'warn' => 'warn',
        'notice' => 'info',
        'information' => 'info',
        'info' => 'info',
        'debug' => 'debug',
        'trace' => 'trace',
        'critical' => 'error',
        'crit' => 'error',
        'fatal' => 'error',
        'alert' => 'error',
        'emerg' => 'error',
        'emergency' => 'error',
        'error' => 'error',
    ];

    return $map[$level] ?? '';
}

function isStandaloneLevelToken(string $value): bool
{
    return preg_match('/^(TRACE|DEBUG|INFO|WARN(?:ING)?|ERROR|CRIT(?:ICAL)?|FATAL)$/i', trim($value)) === 1;
}

function parseStructuredStartLine(string $line): ?array
{
    $timestamp = '';
    $level = '';
    $message = $line;

    if (preg_match('/^\[(.*?)\]\s+\[(TRACE|DEBUG|INFO|WARN|WARNING|ERROR)\]\s*(.*)$/i', $line, $matches) === 1) {
        $timestamp = trim($matches[1]);
        $level = normalizeLogLevel($matches[2]);
        $message = trim($matches[3]);
        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message === '' ? $line : $message,
        ];
    }

    if (preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\](?:\s+\[[^\]]+\])?\s*(.*)$/', $line, $matches) === 1) {
        $timestamp = trim($matches[1]);
        $token = trim($matches[2]);
        $parts = explode(':', strtolower($token));
        $levelToken = trim(end($parts) ?: $token);
        $level = normalizeLogLevel($levelToken);
        $message = trim($matches[3]);
        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message === '' ? $line : $message,
        ];
    }

    if (preg_match('/^\[([A-Za-z][^\]]*)\]\s*(.*)$/', $line, $matches) === 1) {
        $token = trim($matches[1]);
        $message = trim($matches[2]);
        $level = normalizeLogLevel($token);

        if ($level === '') {
            $message = '[' . $token . ']' . ($message !== '' ? ' ' . $message : '');
        }

        return [
            'timestamp' => '',
            'level' => $level,
            'message' => $message === '' ? $line : $message,
        ];
    }

    if (
        preg_match(
            '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:\s*(?:UTC|Z|[+\-]\d{2}:?\d{2}|[A-Z]{2,5}))?)\s+(TRACE|DEBUG|INFO|WARN(?:ING)?|ERROR|CRIT(?:ICAL)?|FATAL)\b\s*(.*)$/i',
            $line,
            $matches
        ) === 1
    ) {
        $timestamp = trim($matches[1]);
        $level = normalizeLogLevel($matches[2]);
        $message = trim($matches[3]);
        return [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message === '' ? $line : $message,
        ];
    }

    if (
        preg_match(
            '/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:\s*(?:UTC|Z|[+\-]\d{2}:?\d{2}|[A-Z]{2,5}))?)$/i',
            $line,
            $matches
        ) === 1
    ) {
        return [
            'timestamp' => trim($matches[1]),
            'level' => '',
            'message' => '',
        ];
    }

    return null;
}

function parseStructuredLogEntries(array $lines): array
{
    $entries = [];

    foreach ($lines as $line) {
        $line = trim(strval($line));
        if ($line === '') {
            continue;
        }

        $startEntry = parseStructuredStartLine($line);
        if ($startEntry !== null) {
            $entries[] = $startEntry;
            continue;
        }

        $lastIndex = count($entries) - 1;
        if ($lastIndex >= 0) {
            if ($entries[$lastIndex]['level'] === '' && isStandaloneLevelToken($line)) {
                $entries[$lastIndex]['level'] = normalizeLogLevel($line);
                continue;
            }

            if ($entries[$lastIndex]['timestamp'] !== '' || $entries[$lastIndex]['level'] !== '') {
                $entries[$lastIndex]['message'] = $entries[$lastIndex]['message'] === ''
                    ? $line
                    : ($entries[$lastIndex]['message'] . "\n" . $line);
                continue;
            }
        }

        $level = '';
        if (preg_match('/\b(TRACE|DEBUG|INFO|WARN(?:ING)?|ERROR|CRIT(?:ICAL)?|FATAL)\b/i', $line, $matches) === 1) {
            $level = normalizeLogLevel($matches[1]);
        }

        $entries[] = [
            'timestamp' => '',
            'level' => $level,
            'message' => $line,
        ];
    }

    return $entries;
}

function timestampToIso8601(string $timestamp): ?string
{
    $value = trim($timestamp);
    if ($value === '') {
        return null;
    }

    $timezoneName = date_default_timezone_get();
    $tz = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
    $formats = [
        'Y-m-d H:i:s.u',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:sP',
        'D M d H:i:s.u Y',
        'D M d H:i:s Y',
        DateTime::RFC3339,
        DateTime::ATOM,
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value, $tz);
        if ($dt instanceof DateTime) {
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format(DateTime::ATOM);
        }
    }

    $parsed = strtotime($value);
    if ($parsed !== false) {
        $dt = new DateTime('@' . strval($parsed));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format(DateTime::ATOM);
    }

    return null;
}

function parseLlmContextBlocks(array $lines): array
{
    $blocks = [];
    $currentTimestamp = '';
    $currentContentLines = [];
    $inPayload = false;

    $pushBlock = static function (string $timestamp, array $contentLines) use (&$blocks): void {
        $content = rtrim(implode("\n", $contentLines), "\r\n");
        if ($timestamp === '' || $content === '') {
            return;
        }
        $blocks[] = [
            'timestamp' => $timestamp,
            'content' => $content,
        ];
    };

    foreach ($lines as $rawLine) {
        $line = rtrim(strval($rawLine), "\r\n");
        $trimmed = trim($line);

        if ($trimmed === '=') {
            if ($currentTimestamp === '') {
                continue;
            }
            if (!$inPayload) {
                $inPayload = true;
                continue;
            }

            $pushBlock($currentTimestamp, $currentContentLines);
            $currentTimestamp = '';
            $currentContentLines = [];
            $inPayload = false;
            continue;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $trimmed) === 1) {
            if ($currentTimestamp !== '' && !empty($currentContentLines)) {
                $pushBlock($currentTimestamp, $currentContentLines);
            }
            $currentTimestamp = $trimmed;
            $currentContentLines = [];
            $inPayload = false;
            continue;
        }

        if ($currentTimestamp !== '' && $inPayload) {
            $currentContentLines[] = $line;
        }
    }

    if ($currentTimestamp !== '' && !empty($currentContentLines)) {
        $pushBlock($currentTimestamp, $currentContentLines);
    }

    usort($blocks, static function (array $a, array $b): int {
        $aTime = strtotime(strval($a['timestamp'] ?? '')) ?: 0;
        $bTime = strtotime(strval($b['timestamp'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    return $blocks;
}

function parseLlmOutputBlocks(array $lines): array
{
    $blocks = [];
    $currentBlock = null;
    $inBlock = false;
    $timestampPattern = '\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})';

    $pushBlock = static function (?array $block) use (&$blocks): void {
        if (!is_array($block)) {
            return;
        }
        $content = $block['content'] ?? [];
        if (!is_array($content) || count($content) === 0) {
            return;
        }
        $blocks[] = $block;
    };

    foreach ($lines as $rawLine) {
        $line = trim(strval($rawLine));
        if ($line === '' || $line === '==') {
            continue;
        }

        if (preg_match('/^(?:==\s+)?(' . $timestampPattern . ')\s+START$/', $line, $matches) === 1) {
            if ($inBlock) {
                $pushBlock($currentBlock);
            }
            $currentBlock = [
                'start_time' => trim($matches[1]),
                'content' => [],
            ];
            $inBlock = true;
            continue;
        }

        if (preg_match('/^(?:==\s+)?(' . $timestampPattern . ')\s+END$/', $line, $matches) === 1) {
            if ($inBlock && is_array($currentBlock)) {
                $currentBlock['end_time'] = trim($matches[1]);
                $pushBlock($currentBlock);
            }
            $currentBlock = null;
            $inBlock = false;
            continue;
        }

        if ($inBlock && is_array($currentBlock)) {
            $currentBlock['content'][] = $line;
        }
    }

    if ($inBlock) {
        $pushBlock($currentBlock);
    }

    usort($blocks, static function (array $a, array $b): int {
        $aTime = strtotime(strval($a['start_time'] ?? '')) ?: 0;
        $bTime = strtotime(strval($b['start_time'] ?? '')) ?: 0;
        return $bTime <=> $aTime;
    });

    return $blocks;
}

function renderLogSection(array $source): void
{
    $id = sanitizeId(strval($source['id'] ?? 'log'));
    $title = strval($source['title'] ?? 'Log');
    $candidates = array_values(array_filter(array_map('strval', $source['candidates'] ?? []), static fn($item) => $item !== ''));
    $specialMode = strtolower(trim(strval($source['special'] ?? '')));
    $isLlmContextMode = $specialMode === 'llm_context';
    $isLlmOutputMode = $specialMode === 'llm_output';
    $isSpecialMode = $isLlmContextMode || $isLlmOutputMode;
    $rawMode = !$isSpecialMode && !empty($source['raw']);

    $resolvedPath = resolveExistingPath($candidates);
    $displayPath = $resolvedPath !== '' ? $resolvedPath : ($candidates[0] ?? '');
    $exists = $resolvedPath !== '' && is_file($resolvedPath);
    $readable = $exists && is_readable($resolvedPath);
    $fileSize = $exists ? intval(@filesize($resolvedPath)) : 0;
    $rawLines = [];
    $entries = [];
    $contextBlocks = [];
    $outputBlocks = [];

    if ($exists && $readable) {
        $rawLines = tailFile($resolvedPath, 2000);
        if ($isLlmContextMode) {
            $contextBlocks = parseLlmContextBlocks($rawLines);
        } elseif ($isLlmOutputMode) {
            $outputBlocks = parseLlmOutputBlocks($rawLines);
        } elseif (!$rawMode) {
            $entries = parseStructuredLogEntries($rawLines);
        }
    }

    echo '<section class="log-section">';
    echo '<div class="section-header">';
    echo '<h2>' . h($title) . '</h2>';
    echo '<button class="expand-button" type="button" data-source="' . h($id) . '_container" data-modal="' . h($id) . '_modal" title="Expand">';
    echo '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>';
    echo '</button>';
    echo '</div>';
    echo '<div class="source-meta">Source: <code>' . h($displayPath !== '' ? $displayPath : 'Path unresolved') . '</code></div>';
    echo '<div class="search-container">';
    echo '<input class="search-input" type="text" placeholder="Search in ' . h($title) . '..." data-target="' . h($id) . '_container">';
    echo '</div>';

    if (!$rawMode && !$isSpecialMode) {
        echo '<div class="log-filter-container" id="' . h($id) . '_filters">';
        echo '<div class="filter-header">Filter by Level:</div>';
        echo '<div class="filter-controls">';
        echo '<button class="filter-btn filter-btn-sm" type="button" data-action="all" data-container="' . h($id) . '">All</button>';
        echo '<button class="filter-btn filter-btn-sm" type="button" data-action="none" data-container="' . h($id) . '">None</button>';
        echo '</div>';
        foreach (['error', 'warn', 'info', 'debug', 'trace'] as $level) {
            $checked = in_array($level, ['error', 'warn'], true) ? 'checked' : '';
            echo '<label class="filter-checkbox">';
            echo '<input type="checkbox" class="level-filter" data-container="' . h($id) . '" data-level="' . h($level) . '" ' . $checked . '>';
            echo '<span class="filter-badge ' . h($level) . '-badge">' . strtoupper($level) . ' <span class="level-count" id="' . h($id . '_' . $level . '_count') . '">0</span></span>';
            echo '</label>';
        }
        echo '</div>';
    }

    echo '<div class="log-container" id="' . h($id) . '_container" data-level-filter="' . (($rawMode || $isSpecialMode) ? '0' : '1') . '">';
    if (!$exists) {
        echo '<div class="info-message">Log file does not exist yet.';
        if (count($candidates) > 0) {
            echo '<div class="checked-paths">';
            echo '<span>Checked paths:</span>';
            foreach ($candidates as $path) {
                echo '<code>' . h($path) . '</code>';
            }
            echo '</div>';
        }
        echo '</div>';
    } elseif (!$readable) {
        echo '<div class="error-message">Log file is not readable: <code>' . h($resolvedPath) . '</code></div>';
    } elseif (($isLlmContextMode ? count($contextBlocks) : ($isLlmOutputMode ? count($outputBlocks) : ($rawMode ? count($rawLines) : count($entries)))) === 0) {
        echo '<div class="info-message">Log is empty (' . h(strval($fileSize)) . ' bytes).</div>';
    } else {
        if ($isLlmContextMode) {
            foreach ($contextBlocks as $block) {
                $timestamp = trim(strval($block['timestamp'] ?? ''));
                $content = trim(strval($block['content'] ?? ''));
                if ($content === '') {
                    continue;
                }

                $iso = timestampToIso8601($timestamp);

                echo '<div class="log-entry llm-block">';
                echo '<div class="timestamp llm-block-head">';
                echo '<span class="time-label">Time:</span> ';
                if ($iso !== null) {
                    echo '<span class="time-value" data-utc="' . h($iso) . '" data-timezone-label="UTC">' . h($timestamp) . ' UTC</span>';
                } else {
                    echo '<span class="time-value">' . h($timestamp) . '</span>';
                }
                echo '<button type="button" class="copy-llm-btn" title="Copy to clipboard" aria-label="Copy log block">';
                echo '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2.5 1A1.5 1.5 0 0 0 1 2.5v8A1.5 1.5 0 0 0 2.5 12H3V2.5A1.5 1.5 0 0 1 4.5 1h-2z"/><path d="M4.5 2A1.5 1.5 0 0 0 3 3.5v10A1.5 1.5 0 0 0 4.5 15h8a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 12.5 2h-8zm0 1h8a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5z"/></svg>';
                echo '</button>';
                echo '</div>';
                echo '<div class="log-message"><pre class="llm-content llm-copy-content">' . h($content) . '</pre></div>';
                echo '</div>';
            }
        } elseif ($isLlmOutputMode) {
            foreach ($outputBlocks as $block) {
                $startTime = trim(strval($block['start_time'] ?? ''));
                $endTime = trim(strval($block['end_time'] ?? ''));
                $contentLines = $block['content'] ?? [];
                if (!is_array($contentLines) || count($contentLines) === 0) {
                    continue;
                }

                $startIso = timestampToIso8601($startTime);
                $endIso = $endTime !== '' ? timestampToIso8601($endTime) : null;

                echo '<div class="log-entry llm-block llm-output-block">';
                echo '<div class="timestamp llm-block-head">';
                echo '<div class="llm-time-wrap">';
                echo '<span class="time-label">Start:</span> ';
                if ($startIso !== null) {
                    echo '<span class="time-value" data-utc="' . h($startIso) . '" data-timezone-label="UTC">' . h($startTime) . ' UTC</span>';
                } else {
                    echo '<span class="time-value">' . h($startTime) . '</span>';
                }
                if ($endTime !== '') {
                    echo ' <span class="time-separator">&rarr;</span> ';
                    echo '<span class="time-label">End:</span> ';
                    if ($endIso !== null) {
                        echo '<span class="time-value" data-utc="' . h($endIso) . '" data-timezone-label="UTC">' . h($endTime) . ' UTC</span>';
                    } else {
                        echo '<span class="time-value">' . h($endTime) . '</span>';
                    }
                }
                echo '</div>';
                echo '<button type="button" class="copy-llm-btn" title="Copy to clipboard" aria-label="Copy log block">';
                echo '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2.5 1A1.5 1.5 0 0 0 1 2.5v8A1.5 1.5 0 0 0 2.5 12H3V2.5A1.5 1.5 0 0 1 4.5 1h-2z"/><path d="M4.5 2A1.5 1.5 0 0 0 3 3.5v10A1.5 1.5 0 0 0 4.5 15h8a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 12.5 2h-8zm0 1h8a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5z"/></svg>';
                echo '</button>';
                echo '</div>';
                echo '<div class="log-message llm-output-message">';
                foreach ($contentLines as $line) {
                    $lineText = trim(strval($line));
                    if ($lineText === '') {
                        continue;
                    }
                    echo '<div class="llm-output-line llm-copy-content">' . h($lineText) . '</div>';
                }
                echo '</div>';
                echo '</div>';
            }
        } elseif ($rawMode) {
            foreach ($rawLines as $line) {
                $text = strval($line);
                if ($text === '') {
                    $text = ' ';
                }
                echo '<div class="log-entry raw-entry">';
                echo '<div class="log-message raw-line">' . h($text) . '</div>';
                echo '</div>';
            }
        } else {
            foreach ($entries as $entry) {
                $timestamp = trim(strval($entry['timestamp'] ?? ''));
                $level = trim(strval($entry['level'] ?? ''));
                $message = strval($entry['message'] ?? '');

                $classes = 'log-entry';
                $levelAttr = '';
                if ($level !== '') {
                    $classes .= ' ' . $level . '-level';
                    $levelAttr = ' data-level="' . h($level) . '"';
                }

                echo '<div class="' . h($classes) . '"' . $levelAttr . '>';
                if ($timestamp !== '') {
                    $iso = timestampToIso8601($timestamp);
                    if ($iso !== null) {
                        echo '<div class="timestamp" data-utc="' . h($iso) . '" data-timezone-label="UTC">' . h($timestamp) . ' UTC</div>';
                    } else {
                        echo '<div class="timestamp">' . h($timestamp) . '</div>';
                    }
                }
                if ($level !== '') {
                    echo '<div class="log-level">' . h(strtoupper($level)) . '</div>';
                }
                echo '<div class="log-message">' . h($message) . '</div>';
                echo '</div>';
            }
        }
    }
    echo '</div>';
    echo '</section>';

    echo '<div id="' . h($id) . '_modal" class="log-modal">';
    echo '<div class="log-modal-content">';
    echo '<div class="log-modal-header">';
    echo '<h2 class="log-modal-title">' . h($title) . '</h2>';
    echo '<button class="close-modal" type="button" data-close-modal="' . h($id) . '_modal">&times;</button>';
    echo '</div>';
    echo '<div class="modal-search-container">';
    echo '<input type="text" class="modal-search-input" placeholder="Search in ' . h($title) . '..." data-target="' . h($id) . '_modal_content">';
    echo '</div>';
    echo '<div class="log-modal-body"><div id="' . h($id) . '_modal_content"></div></div>';
    echo '</div>';
    echo '</div>';
}

function normalizeMcpApiSource(string $value): string
{
    $source = strtolower(trim($value));
    return $source === 'stobe' ? 'stobe' : 'herika';
}

function loadMcpConnectionPreference(string $filepath): array
{
    $default = [
        'api_key_source' => 'herika',
        'updated_at' => '',
    ];

    if (!is_file($filepath) || !is_readable($filepath)) {
        return $default;
    }

    $raw = @file_get_contents($filepath);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return $default;
    }

    return [
        'api_key_source' => normalizeMcpApiSource(strval($parsed['api_key_source'] ?? 'herika')),
        'updated_at' => trim(strval($parsed['updated_at'] ?? '')),
    ];
}

function saveMcpConnectionPreference(string $filepath, string $source): array
{
    $dir = dirname($filepath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return [
            'success' => false,
            'path' => $filepath,
            'error' => 'Failed to create directory: ' . $dir,
        ];
    }

    $payload = [
        'api_key_source' => normalizeMcpApiSource($source),
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return [
            'success' => false,
            'path' => $filepath,
            'error' => 'Failed to encode MCP connection JSON payload.',
        ];
    }

    $writeResult = @file_put_contents($filepath, $json . PHP_EOL, LOCK_EX);
    if ($writeResult === false) {
        $firstError = error_get_last();
        $writeResult = @file_put_contents($filepath, $json . PHP_EOL);
        if ($writeResult === false) {
            $secondError = error_get_last();
            $firstMessage = trim(strval($firstError['message'] ?? ''));
            $secondMessage = trim(strval($secondError['message'] ?? ''));
            return [
                'success' => false,
                'path' => $filepath,
                'error' => $secondMessage !== '' ? $secondMessage : ($firstMessage !== '' ? $firstMessage : 'file_put_contents failed'),
            ];
        }
    }

    return [
        'success' => true,
        'path' => $filepath,
    ];
}

function resolveDwemerPrimaryApiKey(sql $db): array
{
    $empty = [
        'api_key' => '',
        'label' => 'OpenRouter',
        'entry_found' => false,
    ];

    $queries = [
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
           AND COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), '')) IS NOT NULL
           AND COALESCE(c.is_default, false) = true
         ORDER BY c.id ASC
         LIMIT 1",
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
           AND COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), '')) IS NOT NULL
         ORDER BY COALESCE(c.is_default, false) DESC, c.id ASC
         LIMIT 1",
        "SELECT
            id AS badge_id,
            COALESCE(NULLIF(BTRIM(api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(label), ''), 'OpenRouter') AS label
         FROM core_api_badge
         WHERE LOWER(COALESCE(label, '')) LIKE '%openrouter%'
           AND NULLIF(BTRIM(api_key), '') IS NOT NULL
         ORDER BY id ASC
         LIMIT 1",
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
         ORDER BY COALESCE(c.is_default, false) DESC, c.id ASC
         LIMIT 1",
        "SELECT
            id AS badge_id,
            COALESCE(NULLIF(BTRIM(api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(label), ''), 'OpenRouter') AS label
         FROM core_api_badge
         WHERE LOWER(COALESCE(label, '')) LIKE '%openrouter%'
         ORDER BY id ASC
         LIMIT 1",
    ];

    foreach ($queries as $query) {
        $row = $db->fetchOne($query);
        if (!is_array($row) || count($row) === 0) {
            continue;
        }
        $apiKey = trim(strval($row['api_key'] ?? ''));
        $label = trim(strval($row['label'] ?? 'OpenRouter'));
        return [
            'api_key' => $apiKey,
            'label' => $label !== '' ? $label : 'OpenRouter',
            'entry_found' => true,
        ];
    }

    return $empty;
}

function resolveStobePrimaryApiKey(string $connectionString): array
{
    $empty = [
        'api_key' => '',
        'label' => 'OpenRouter',
        'entry_found' => false,
    ];
    if (!function_exists('pg_connect')) {
        return $empty;
    }

    $connection = @pg_connect($connectionString);
    if (!$connection) {
        return $empty;
    }

    $queries = [
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), NULLIF(BTRIM(c.name), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
           AND COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), '')) IS NOT NULL
           AND COALESCE(c.is_default, false) = true
         ORDER BY c.id ASC
         LIMIT 1",
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), NULLIF(BTRIM(c.name), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
           AND COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), '')) IS NOT NULL
         ORDER BY COALESCE(c.is_default, false) DESC, c.id ASC
         LIMIT 1",
        "SELECT
            id AS badge_id,
            COALESCE(NULLIF(BTRIM(api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(label), ''), 'OpenRouter') AS label
         FROM core_api_badge
         WHERE LOWER(COALESCE(label, '')) LIKE '%openrouter%'
           AND NULLIF(BTRIM(api_key), '') IS NOT NULL
         ORDER BY id ASC
         LIMIT 1",
        "SELECT
            c.id AS connector_id,
            b.id AS badge_id,
            COALESCE(NULLIF(BTRIM(c.api_key), ''), NULLIF(BTRIM(b.api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(b.label), ''), NULLIF(BTRIM(c.label), ''), NULLIF(BTRIM(c.name), ''), 'OpenRouter') AS label
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
         ORDER BY COALESCE(c.is_default, false) DESC, c.id ASC
         LIMIT 1",
        "SELECT
            id AS badge_id,
            COALESCE(NULLIF(BTRIM(api_key), ''), '') AS api_key,
            COALESCE(NULLIF(BTRIM(label), ''), 'OpenRouter') AS label
         FROM core_api_badge
         WHERE LOWER(COALESCE(label, '')) LIKE '%openrouter%'
         ORDER BY id ASC
         LIMIT 1",
    ];

    foreach ($queries as $query) {
        $result = @pg_query($connection, $query);
        if (!$result) {
            continue;
        }
        $row = @pg_fetch_assoc($result);
        if (!is_array($row) || count($row) === 0) {
            continue;
        }
        $apiKey = trim(strval($row['api_key'] ?? ''));
        @pg_close($connection);
        return [
            'api_key' => $apiKey,
            'label' => trim(strval($row['label'] ?? 'OpenRouter')) ?: 'OpenRouter',
            'entry_found' => true,
        ];
    }

    @pg_close($connection);
    return $empty;
}

function summarizeOpenRouterState(array $resolved): array
{
    $label = trim(strval($resolved['label'] ?? 'OpenRouter'));
    return [
        'entry_found' => !empty($resolved['entry_found']),
        'key_present' => trim(strval($resolved['api_key'] ?? '')) !== '',
        'label' => $label !== '' ? $label : 'OpenRouter',
    ];
}

function resolveDwemerOpenRouterBadgeId(sql $db): int
{
    $queries = [
        "SELECT id FROM core_api_badge WHERE LOWER(COALESCE(label, '')) = 'openrouter' ORDER BY id ASC LIMIT 1",
        "SELECT b.id
         FROM core_llm_connector c
         LEFT JOIN core_api_badge b ON b.id = c.api_badge_id
         WHERE LOWER(COALESCE(c.service, '')) = 'openrouter'
           AND b.id IS NOT NULL
         ORDER BY COALESCE(c.is_default, false) DESC, c.id ASC
         LIMIT 1",
    ];

    foreach ($queries as $query) {
        $row = $db->fetchOne($query);
        $badgeId = intval($row['id'] ?? 0);
        if ($badgeId > 0) {
            return $badgeId;
        }
    }

    return 0;
}

function applyMcpApiSourceSelection(string $source, ?sql $dwemerDb, string $stobeConnectionString): array
{
    $normalizedSource = normalizeMcpApiSource($source);
    if (!$dwemerDb) {
        return [
            'success' => false,
            'error' => 'HerikaServer database connection is unavailable.',
        ];
    }

    try {
        $resolved = $normalizedSource === 'stobe'
            ? resolveStobePrimaryApiKey($stobeConnectionString)
            : resolveDwemerPrimaryApiKey($dwemerDb);
        if (empty($resolved['entry_found'])) {
            $sourceLabel = $normalizedSource === 'stobe' ? 'STOBE' : 'CHIM';
            return [
                'success' => false,
                'error' => 'OpenRouter badge was not found for ' . $sourceLabel . '.',
            ];
        }
        $apiKey = trim(strval($resolved['api_key'] ?? ''));
        if ($apiKey === '') {
            $sourceLabel = $normalizedSource === 'stobe' ? 'STOBE' : 'CHIM';
            return [
                'success' => false,
                'error' => 'OpenRouter API key is empty for ' . $sourceLabel . '.',
            ];
        }

        $badgeId = resolveDwemerOpenRouterBadgeId($dwemerDb);
        if ($badgeId <= 0) {
            return [
                'success' => false,
                'error' => 'OpenRouter badge not found in CHIM.',
            ];
        }

        $dwemerDb->upsertRowOnConflict('conf_opts', [
            'id' => 'MCP/api_badge_id',
            'value' => strval($badgeId),
        ], 'id');

        $dwemerDb->updateRow('core_api_badge', [
            'api_key' => $apiKey,
        ], 'id=' . $badgeId);

        return [
            'success' => true,
            'source' => $normalizedSource,
            'badge_id' => $badgeId,
            'badge_label' => 'OpenRouter',
            'connector_id' => 0,
            'resolved_label' => trim(strval($resolved['label'] ?? '')),
        ];
    } catch (Throwable $exception) {
        return [
            'success' => false,
            'error' => 'Failed to apply MCP key source: ' . $exception->getMessage(),
        ];
    }
}

$title = 'Distro Debugger';
$hasCustomBackground = file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'background.jpg');

$resolveServerRoot = static function (array $candidates): string {
    foreach ($candidates as $candidate) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$candidate);
        if (is_dir($normalized)) {
            return realpath($normalized) ?: $normalized;
        }
    }
    return '';
};

$herikaRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'HerikaServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'HerikaServer',
    '/var/www/html/HerikaServer',
]);

$stobeRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'StobeServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'StobeServer',
    '/var/www/html/StobeServer',
]);

$dashboardDataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$mcpConnectionConfigPath = $dashboardDataDir . DIRECTORY_SEPARATOR . 'mcp_connection_config.json';
$mcpConnectionPreference = loadMcpConnectionPreference($mcpConnectionConfigPath);
$mcpApiKeySource = normalizeMcpApiSource(strval($mcpConnectionPreference['api_key_source'] ?? 'herika'));

$herikaLogDirs = array_values(array_filter([
    $herikaRoot !== '' ? $herikaRoot . DIRECTORY_SEPARATOR . 'log' : '',
    '/var/www/html/HerikaServer/log',
]));

$stobeLogDirs = array_values(array_filter([
    $stobeRoot !== '' ? $stobeRoot . DIRECTORY_SEPARATOR . 'log' : '',
    '/var/www/html/StobeServer/log',
]));

$distroLogSources = [
    [
        'id' => 'apache_error',
        'title' => 'Apache Errors (error.log)',
        'candidates' => [
            '/var/log/apache2/error.log',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\var\\log\\apache2\\error.log',
        ],
    ],
    [
        'id' => 'xtts',
        'title' => 'Dwemer Distro XTTS (xtts-api-server/log.txt)',
        'raw' => true,
        'candidates' => [
            '/home/dwemer/xtts-api-server/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\xtts-api-server\\log.txt',
        ],
    ],
    [
        'id' => 'chatterbox',
        'title' => 'Chatterbox (chatterbox/log.txt)',
        'candidates' => [
            '/home/dwemer/chatterbox/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\chatterbox\\log.txt',
        ],
    ],
    [
        'id' => 'pockettts',
        'title' => 'PocketTTS (pocket-tts/log.txt)',
        'candidates' => [
            '/home/dwemer/pocket-tts/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\pocket-tts\\log.txt',
        ],
    ],
    [
        'id' => 'melotts',
        'title' => 'MeloTTS (MeloTTS/melo/log.txt)',
        'raw' => true,
        'candidates' => [
            '/home/dwemer/MeloTTS/melo/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\MeloTTS\\melo\\log.txt',
        ],
    ],
    [
        'id' => 'piper',
        'title' => 'Piper-TTS (piper/log.txt)',
        'candidates' => [
            '/home/dwemer/piper/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\piper\\log.txt',
        ],
    ],
    [
        'id' => 'localwhisper',
        'title' => 'LocalWhisper STT (remote-faster-whisper/log.txt)',
        'raw' => true,
        'candidates' => [
            '/home/dwemer/remote-faster-whisper/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\remote-faster-whisper\\log.txt',
        ],
    ],
    [
        'id' => 'parakeet',
        'title' => 'Parakeet STT (parakeet-api-server/log.txt)',
        'raw' => true,
        'candidates' => [
            '/home/dwemer/parakeet-api-server/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\parakeet-api-server\\log.txt',
        ],
    ],
    [
        'id' => 'mimic3',
        'title' => 'Mimic3 TTS (mimic3/log.txt)',
        'candidates' => [
            '/home/dwemer/mimic3/log.txt',
            '\\\\wsl.localhost\\DwemerAI4Skyrim3\\home\\dwemer\\mimic3\\log.txt',
        ],
    ],
];

$chimLogSources = [
    [
        'id' => 'chim_apache_error',
        'title' => 'Apache Mirror (apache_error.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['apache_error.log']),
    ],
    [
        'id' => 'chim_core',
        'title' => 'CHIM Log (chim.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['chim.log']),
    ],
    [
        'id' => 'chim_llm_output',
        'title' => 'LLM Output (output_from_llm.log)',
        'special' => 'llm_output',
        'candidates' => buildFileCandidates($herikaLogDirs, ['output_from_llm.log']),
    ],
    [
        'id' => 'chim_llm_context',
        'title' => 'LLM Context (context_sent_to_llm.log)',
        'special' => 'llm_context',
        'candidates' => buildFileCandidates($herikaLogDirs, ['context_sent_to_llm.log']),
    ],
    [
        'id' => 'chim_llm_context_fast',
        'title' => 'LLM Context Fast (context_sent_to_llm_fast.log)',
        'special' => 'llm_context',
        'candidates' => buildFileCandidates($herikaLogDirs, ['context_sent_to_llm_fast.log']),
    ],
    [
        'id' => 'chim_plugin_output',
        'title' => 'Plugin Output (ouput_to_plugin.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['ouput_to_plugin.log', 'output_to_plugin.log']),
    ],
    [
        'id' => 'chim_stt',
        'title' => 'Speech-to-Text Log (stt.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['stt.log']),
    ],
    [
        'id' => 'chim_monitor',
        'title' => 'Monitor Log (monitor.log)',
        'raw' => true,
        'candidates' => buildFileCandidates($herikaLogDirs, ['monitor.log']),
    ],
    [
        'id' => 'chim_vision',
        'title' => 'Vision Log (vision.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['vision.log']),
    ],
    [
        'id' => 'chim_service',
        'title' => 'Service Log (service.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['service.log']),
    ],
    [
        'id' => 'chim_debugstream',
        'title' => 'Debug Stream Log (debugstream.log)',
        'candidates' => buildFileCandidates($herikaLogDirs, ['debugstream.log']),
    ],
];

$stobeLogSources = [
    [
        'id' => 'stobe_server',
        'title' => 'Stobe Server (stobeserver.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['stobeserver.log']),
    ],
    [
        'id' => 'stobe_llm_output',
        'title' => 'LLM Output (output_from_llm.log)',
        'special' => 'llm_output',
        'candidates' => buildFileCandidates($stobeLogDirs, ['output_from_llm.log']),
    ],
    [
        'id' => 'stobe_llm_context',
        'title' => 'LLM Context (context_sent_to_llm.log)',
        'special' => 'llm_context',
        'candidates' => buildFileCandidates($stobeLogDirs, ['context_sent_to_llm.log']),
    ],
    [
        'id' => 'stobe_php_error',
        'title' => 'PHP Errors (php_error.log)',
        'raw' => true,
        'candidates' => buildFileCandidates($stobeLogDirs, ['php_error.log']),
    ],
    [
        'id' => 'stobe_import',
        'title' => 'Stobe Import (stobe_import.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['stobe_import.log']),
    ],
    [
        'id' => 'stobe_llm_context_fast',
        'title' => 'LLM Context Fast (context_sent_to_llm_fast.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['context_sent_to_llm_fast.log']),
    ],
    [
        'id' => 'stobe_plugin_output',
        'title' => 'Output To Plugin (output_to_plugin.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['output_to_plugin.log', 'ouput_to_plugin.log']),
    ],
    [
        'id' => 'stobe_stt',
        'title' => 'STT Input (stt.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['stt.log']),
    ],
    [
        'id' => 'stobe_audit_request_file',
        'title' => 'Audit Request File (audit_request.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['audit_request.log']),
    ],
    [
        'id' => 'stobe_relationship_worker',
        'title' => 'Relationship Worker (relationship_worker.log)',
        'candidates' => buildFileCandidates($stobeLogDirs, ['relationship_worker.log']),
    ],
];

$mcpHost = 'localhost';
$mcpPort = 3100;
$herikaMcpConfigApi = '/HerikaServer/ui/api/chim_mcp_config.php';
$herikaLogsUrl = '/HerikaServer/ui/tests/apache2err.php';
$stobePgConnectionString = 'host=localhost dbname=stobe user=dwemer password=dwemer connect_timeout=2';
$herikaDb = null;

if ($herikaRoot !== '') {
    try {
        $profileLoader = $herikaRoot . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'profile_loader.php';
        if (is_file($profileLoader)) {
            require_once($profileLoader);
            $dbDriver = trim(strval($GLOBALS['DBDRIVER'] ?? ''));
            if ($dbDriver !== '') {
                $dbClassPath = $herikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $dbDriver . '.class.php';
                if (is_file($dbClassPath)) {
                    require_once($dbClassPath);
                    $herikaDb = new sql();
                    $hostRow = $herikaDb->fetchOne("SELECT value FROM conf_opts WHERE id = 'Network/WSL_IP' LIMIT 1");
                    $hostValue = trim(strval($hostRow['value'] ?? ''));
                    if ($hostValue !== '') {
                        $mcpHost = $hostValue;
                    }

                    $portRow = $herikaDb->fetchOne("SELECT value FROM conf_opts WHERE id = 'MCP/port' LIMIT 1");
                    $portValue = intval($portRow['value'] ?? 0);
                    if ($portValue >= 1 && $portValue <= 65535) {
                        $mcpPort = $portValue;
                    }
                }
            }
        }
    } catch (Throwable $exception) {
        // Keep defaults when config probe fails.
    }
}

if (isset($_GET['mcp_connection_config']) && strval($_GET['mcp_connection_config']) === '1') {
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $chimAvailable = is_object($herikaDb);
        $stobeAvailable = function_exists('pg_connect');
        $chimOpenRouter = $chimAvailable ? resolveDwemerPrimaryApiKey($herikaDb) : ['api_key' => '', 'label' => 'OpenRouter', 'entry_found' => false];
        $stobeOpenRouter = $stobeAvailable ? resolveStobePrimaryApiKey($stobePgConnectionString) : ['api_key' => '', 'label' => 'OpenRouter', 'entry_found' => false];

        echo json_encode([
            'success' => true,
            'data' => [
                'api_key_source' => $mcpApiKeySource,
                'updated_at' => strval($mcpConnectionPreference['updated_at'] ?? ''),
                'available' => [
                    'chim' => $chimAvailable,
                    'herika' => $chimAvailable,
                    'stobe' => $stobeAvailable,
                ],
                'openrouter' => [
                    'chim' => summarizeOpenRouterState($chimOpenRouter),
                    'stobe' => summarizeOpenRouterState($stobeOpenRouter),
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode(is_string($rawBody) ? $rawBody : '', true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid JSON payload',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $requestedSource = normalizeMcpApiSource(strval($payload['api_key_source'] ?? 'herika'));
        $applyResult = applyMcpApiSourceSelection($requestedSource, is_object($herikaDb) ? $herikaDb : null, $stobePgConnectionString);
        if (empty($applyResult['success'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => strval($applyResult['error'] ?? 'Failed to update MCP key source'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $persistResult = saveMcpConnectionPreference($mcpConnectionConfigPath, $requestedSource);
        $persisted = !empty($persistResult['success']);
        $mcpApiKeySource = $requestedSource;
        $responsePayload = [
            'success' => true,
            'persisted' => $persisted,
            'data' => [
                'api_key_source' => $mcpApiKeySource,
                'badge_id' => intval($applyResult['badge_id'] ?? 0),
                'badge_label' => strval($applyResult['badge_label'] ?? ''),
                'connector_id' => intval($applyResult['connector_id'] ?? 0),
                'resolved_label' => strval($applyResult['resolved_label'] ?? ''),
                'updated_at' => gmdate('c'),
            ],
        ];
        if (!$persisted) {
            $warning = 'MCP key source applied, but failed to persist dashboard JSON config.';
            $errorText = trim(strval($persistResult['error'] ?? ''));
            $errorPath = trim(strval($persistResult['path'] ?? $mcpConnectionConfigPath));
            if ($errorText !== '') {
                $warning .= ' Reason: ' . $errorText . '.';
            }
            if ($errorPath !== '') {
                $warning .= ' Path: ' . $errorPath . '.';
            }
            $responsePayload['warning'] = $warning;
        }

        echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['mcp_status']) && strval($_GET['mcp_status']) === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $probePort = intval($_GET['port'] ?? $mcpPort);
    if ($probePort < 1 || $probePort > 65535) {
        $probePort = $mcpPort;
    }
    $probeBaseUrl = 'http://' . $mcpHost . ':' . strval($probePort);

    $result = [
        'ok' => false,
        'url' => $probeBaseUrl,
        'http_code' => 0,
        'latency_ms' => 0,
        'message' => 'MCP server unreachable',
    ];

    $start = microtime(true);
    $probeUrl = $probeBaseUrl . '/health';
    $ch = @curl_init($probeUrl);
    if ($ch) {
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $responseBody = @curl_exec($ch);
        $httpCode = intval(@curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlError = trim(strval(@curl_error($ch)));
        @curl_close($ch);

        $result['http_code'] = $httpCode;
        $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
        if ($httpCode >= 200 && $httpCode < 500) {
            $result['ok'] = true;
            $result['message'] = 'MCP server reachable';
            $decoded = json_decode(strval($responseBody), true);
            if (is_array($decoded)) {
                $result['health'] = $decoded;
            }
        } elseif ($curlError !== '') {
            $result['message'] = $curlError;
        } else {
            $result['message'] = 'HTTP ' . strval($httpCode) . ' from MCP health probe';
        }
    } else {
        $result['latency_ms'] = intval(round((microtime(true) - $start) * 1000));
        $result['message'] = 'Failed to initialize MCP health probe';
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$mcpApiSourceLabel = $mcpApiKeySource === 'stobe' ? 'STOBE' : 'CHIM';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/distro-debugger.css">
    <style>
        <?php if ($hasCustomBackground): ?>
        body {
            background:
                linear-gradient(180deg, rgba(24, 28, 35, 0.55) 0%, rgba(15, 17, 22, 0.82) 100%),
                url('images/background.jpg') center center / cover no-repeat fixed;
        }
        <?php endif; ?>
    </style>
</head>
<body>
<div class="loading-overlay active" id="loadingOverlay">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <p class="loading-text">Loading debugger...</p>
    </div>
</div>

<div class="debugger-page-layout">
<main class="debugger-shell">
    <header class="page-header">
        <div>
            <h1>Distro Debugger</h1>
        </div>
        <a class="back-link" href="index.php">Back to Dashboard</a>
    </header>

    <div class="tab-nav" role="tablist" aria-label="Debugger Tabs">
        <button class="tab-button active" type="button" data-tab="distro" role="tab" aria-selected="true" aria-controls="tab-distro">
            <img class="tab-button-icon" src="images/kagrenac-icon.png" alt="" aria-hidden="true">
            <span class="tab-distro-label">Distro</span>
        </button>
        <button class="tab-button" type="button" data-tab="chim" role="tab" aria-selected="false" aria-controls="tab-chim">
            <img class="tab-button-icon" src="images/chim-icon.png" alt="" aria-hidden="true">
            <img class="tab-button-logo" src="images/chim-logo.png" alt="CHIM">
        </button>
        <button class="tab-button" type="button" data-tab="stobe" role="tab" aria-selected="false" aria-controls="tab-stobe">
            <img class="tab-button-icon" src="images/stobe-icon.png" alt="" aria-hidden="true">
            <img class="tab-button-logo" src="images/stobe-logo.png" alt="STOBE">
        </button>
    </div>

    <section class="tab-panel active" id="tab-distro" role="tabpanel">
        <div class="title-container">
            <h2>Distro Service Logs</h2>
            <div class="toolbar-actions">
                <button class="refresh-button tab-refresh-button" type="button" data-panel="tab-distro" title="Reload Distro logs">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3a5 5 0 0 0-5 5H1l3.5 3.5L8 8H6a2 2 0 1 1 2 2v2a4 4 0 1 0-4-4H2a6 6 0 1 1 6 6v-2a4 4 0 0 0 0-8z"/></svg>
                    <span>Refresh Logs</span>
                </button>
                <button class="refresh-button tab-download-button" type="button" data-panel="tab-distro" data-download-prefix="distro" title="Download visible Distro log entries">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg>
                    <span>Download Logs</span>
                </button>
                <button class="refresh-button tab-timezone-button" type="button" title="Toggle UTC/local browser time">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                    <span>Timezone: UTC</span>
                </button>
            </div>
        </div>
        <div class="title-helper">
        </div>

        <div class="file-log-grid">
            <?php foreach ($distroLogSources as $source): ?>
                <?php renderLogSection($source); ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tab-panel" id="tab-chim" role="tabpanel">
        <div class="title-container">
            <h2>CHIM Server Logs</h2>
            <div class="toolbar-actions">
                <button class="refresh-button tab-refresh-button" type="button" data-panel="tab-chim" title="Reload CHIM logs">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3a5 5 0 0 0-5 5H1l3.5 3.5L8 8H6a2 2 0 1 1 2 2v2a4 4 0 1 0-4-4H2a6 6 0 1 1 6 6v-2a4 4 0 0 0 0-8z"/></svg>
                    <span>Refresh Logs</span>
                </button>
                <button class="refresh-button tab-download-button" type="button" data-panel="tab-chim" data-download-prefix="chim" title="Download visible CHIM log entries">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg>
                    <span>Download Logs</span>
                </button>
                <button class="refresh-button tab-timezone-button" type="button" title="Toggle UTC/local browser time">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                    <span>Timezone: UTC</span>
                </button>
            </div>
        </div>
        <div class="title-helper">
        </div>
        <div class="file-log-grid">
            <?php foreach ($chimLogSources as $source): ?>
                <?php renderLogSection($source); ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="tab-panel" id="tab-stobe" role="tabpanel">
        <div class="title-container">
            <h2>STOBE Server Logs</h2>
            <div class="toolbar-actions">
                <button class="refresh-button tab-refresh-button" type="button" data-panel="tab-stobe" title="Reload STOBE logs">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3a5 5 0 0 0-5 5H1l3.5 3.5L8 8H6a2 2 0 1 1 2 2v2a4 4 0 1 0-4-4H2a6 6 0 1 1 6 6v-2a4 4 0 0 0 0-8z"/></svg>
                    <span>Refresh Logs</span>
                </button>
                <button class="refresh-button tab-download-button" type="button" data-panel="tab-stobe" data-download-prefix="stobe" title="Download visible STOBE log entries">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 0a1 1 0 0 1 1 1v6h2.586l-2.293 2.293a1 1 0 0 1-1.414 0L5.586 7H8V1a1 1 0 0 1 1-1zM4 11h8a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 2-2z"/></svg>
                    <span>Download Logs</span>
                </button>
                <button class="refresh-button tab-timezone-button" type="button" title="Toggle UTC/local browser time">
                    <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm7-8A7 7 0 1 1 1 8a7 7 0 0 1 14 0z"/></svg>
                    <span>Timezone: UTC</span>
                </button>
            </div>
        </div>
        <div class="title-helper">
        </div>
        <div class="file-log-grid">
            <?php foreach ($stobeLogSources as $source): ?>
                <?php renderLogSection($source); ?>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<div class="mcp-side-shell">
    <aside class="kagrenac-panel" id="mcpPanel">
        <div class="kagrenac-header">
            <div class="kagrenac-header-row">
                <div class="kagrenac-title-wrap">
                    <img class="kagrenac-icon" src="images/kagrenac-icon.png" alt="Kagrenac MCP icon">
                    <h2>Kagernac Debugger (MCP)</h2>
                </div>
                <button class="kagrenac-toggle-settings" type="button" id="mcpOpenSettingsBtnTop">
                    <svg class="kagrenac-toggle-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <path d="M6.405 1.05a1 1 0 0 1 1.19 0l.62.46a1 1 0 0 0 .976.114l.741-.294a1 1 0 0 1 1.18.46l.47.803a1 1 0 0 0 .812.5l.92.07a1 1 0 0 1 .91.765l.17.92a1 1 0 0 0 .624.759l.85.338a1 1 0 0 1 .58 1.043l-.114.922a1 1 0 0 0 .322.928l.69.608a1 1 0 0 1 .182 1.18l-.42.827a1 1 0 0 0 0 .983l.42.827a1 1 0 0 1-.182 1.18l-.69.608a1 1 0 0 0-.322.928l.114.922a1 1 0 0 1-.58 1.043l-.85.338a1 1 0 0 0-.624.759l-.17.92a1 1 0 0 1-.91.765l-.92.07a1 1 0 0 0-.812.5l-.47.803a1 1 0 0 1-1.18.46l-.741-.294a1 1 0 0 0-.976.114l-.62.46a1 1 0 0 1-1.19 0l-.62-.46a1 1 0 0 0-.976-.114l-.741.294a1 1 0 0 1-1.18-.46l-.47-.803a1 1 0 0 0-.812-.5l-.92-.07a1 1 0 0 1-.91-.765l-.17-.92a1 1 0 0 0-.624-.759l-.85-.338a1 1 0 0 1-.58-1.043l.114-.922a1 1 0 0 0-.322-.928l-.69-.608a1 1 0 0 1-.182-1.18l.42-.827a1 1 0 0 0 0-.983l-.42-.827a1 1 0 0 1 .182-1.18l.69-.608a1 1 0 0 0 .322-.928l-.114-.922a1 1 0 0 1 .58-1.043l.85-.338a1 1 0 0 0 .624-.759l.17-.92a1 1 0 0 1 .91-.765l.92-.07a1 1 0 0 0 .812-.5l.47-.803a1 1 0 0 1 1.18-.46l.741.294a1 1 0 0 0 .976-.114l.62-.46zM8 10.5A2.5 2.5 0 1 0 8 5.5a2.5 2.5 0 0 0 0 5z"></path>
                    </svg>
                    <span>Settings</span>
                </button>
            </div>
        </div>
        <div class="kagrenac-status">
            <span class="mcp-pill" id="mcpStatusPill">Checking...</span>
            <div class="mcp-meta" id="mcpStatusMeta">
                Status pending...<br>
                AI Key Source: <?= h($mcpApiSourceLabel) ?>
            </div>
        </div>
        <div class="mcp-chat">
            <div class="mcp-chat-history" id="mcpChatHistory"></div>
            <div class="mcp-chat-compose">
                <textarea class="mcp-chat-input" id="mcpChatInput" placeholder="Ask MCP..."></textarea>
                <button class="kag-btn" type="button" id="mcpSendBtn">Send</button>
            </div>
        </div>
    </aside>
</div>
</div>

<div id="mcpSettingsModal" class="log-modal mcp-settings-modal">
    <div class="log-modal-content mcp-settings-content">
        <div class="log-modal-header">
            <h2 class="log-modal-title">MCP Settings</h2>
            <button class="close-modal" type="button" data-close-modal="mcpSettingsModal">&times;</button>
        </div>
        <div class="mcp-settings-grid">
            <div class="mcp-settings-full">
                <label for="mcpModalApiSourceSelect">API Source</label>
                <select id="mcpModalApiSourceSelect">
                    <option value="herika" <?= $mcpApiKeySource === 'stobe' ? '' : 'selected' ?>>CHIM</option>
                    <option value="stobe" <?= $mcpApiKeySource === 'stobe' ? 'selected' : '' ?>>STOBE</option>
                </select>
                <div class="settings-hint">Choose where MCP reads the OpenRouter API key from.</div>
                <div class="settings-hint mcp-openrouter-status" id="mcpModalOpenRouterStatus">OpenRouter key status: checking...</div>
            </div>
            <div>
                <label>API Badge</label>
                <div class="mcp-readonly-value">OpenRouter</div>
            </div>
            <div>
                <label for="mcpModalModel">Model</label>
                <input id="mcpModalModel" type="text" placeholder="anthropic/claude-sonnet-4.5">
            </div>
            <div>
                <label for="mcpModalTemperature">Temperature</label>
                <input id="mcpModalTemperature" type="number" min="0" max="2" step="0.01" placeholder="0.7">
            </div>
            <div>
                <label for="mcpModalMaxRounds">Max Tool Rounds</label>
                <input id="mcpModalMaxRounds" type="number" min="1" max="60" step="1" placeholder="20">
            </div>
            <div class="mcp-settings-full">
                <label for="mcpModalSystemPrompt">System Prompt Override</label>
                <textarea id="mcpModalSystemPrompt" placeholder="Defaults to direct, non-roleplay MCP debugger behavior"></textarea>
            </div>
        </div>
        <div class="settings-actions mcp-modal-actions">
            <button class="settings-btn" id="mcpModalSaveBtn" type="button">Save Settings</button>
            <button class="settings-btn" id="mcpModalReloadBtn" type="button">Reload</button>
        </div>
    </div>
</div>

<script>
(function() {
    const TIMEZONE_KEY = 'distro_debugger_timezone';
    const TAB_KEY = 'distro_debugger_active_tab';
    const UTC_MODE = 'utc';
    const LOCAL_MODE = 'local';

    let useLocalTime = (localStorage.getItem(TIMEZONE_KEY) || UTC_MODE) === LOCAL_MODE;
    const mcpStatusApiBase = '<?= h($_SERVER['PHP_SELF'] ?? 'distro_debugger.php') ?>';
    const mcpConnectionConfigApi = mcpStatusApiBase + '?mcp_connection_config=1';
    const mcpHost = '<?= h($mcpHost) ?>';
    const defaultMcpPort = <?= intval($mcpPort) ?>;
    const herikaMcpConfigApi = '<?= h($herikaMcpConfigApi) ?>';
    const defaultMcpApiSource = '<?= h($mcpApiKeySource) ?>';

    const mcpStatusPill = document.getElementById('mcpStatusPill');
    const mcpStatusMeta = document.getElementById('mcpStatusMeta');
    const mcpChatHistory = document.getElementById('mcpChatHistory');
    const mcpChatInput = document.getElementById('mcpChatInput');
    const mcpSendBtn = document.getElementById('mcpSendBtn');
    const mcpOpenSettingsBtnTop = document.getElementById('mcpOpenSettingsBtnTop');
    const mcpSettingsModal = document.getElementById('mcpSettingsModal');
    const mcpModalApiSourceSelect = document.getElementById('mcpModalApiSourceSelect');
    const mcpModalOpenRouterStatus = document.getElementById('mcpModalOpenRouterStatus');
    const mcpModalModel = document.getElementById('mcpModalModel');
    const mcpModalTemperature = document.getElementById('mcpModalTemperature');
    const mcpModalMaxRounds = document.getElementById('mcpModalMaxRounds');
    const mcpModalSystemPrompt = document.getElementById('mcpModalSystemPrompt');
    const mcpModalSaveBtn = document.getElementById('mcpModalSaveBtn');
    const mcpModalReloadBtn = document.getElementById('mcpModalReloadBtn');
    const DEFAULT_MCP_MODEL = 'anthropic/claude-sonnet-4.5';
    const DEFAULT_MCP_TEMPERATURE = '0.7';
    const DEFAULT_MCP_MAX_ROUNDS = '20';
    const DEFAULT_MCP_SYSTEM_PROMPT =
        'You are the CHIM MCP debugging assistant. Respond directly and concisely with technical answers only. ' +
        'No roleplay, no character persona, no theatrical language.';

    let mcpResolvedPort = defaultMcpPort;
    let mcpRequestInFlight = false;
    let mcpApiKeySource = (defaultMcpApiSource === 'stobe') ? 'stobe' : 'herika';
    let mcpOpenRouterState = {
        herika: { entry_found: false, key_present: false, label: 'OpenRouter' },
        stobe: { entry_found: false, key_present: false, label: 'OpenRouter' },
    };

    function setTimezoneMode(mode) {
        localStorage.setItem(TIMEZONE_KEY, mode);
    }

    function formatUtc(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return date.getUTCFullYear() + '-' +
            pad(date.getUTCMonth() + 1) + '-' +
            pad(date.getUTCDate()) + ' ' +
            pad(date.getUTCHours()) + ':' +
            pad(date.getUTCMinutes()) + ':' +
            pad(date.getUTCSeconds()) + ' UTC';
    }

    function formatLocal(date) {
        return date.toLocaleString() + ' Local';
    }

    function convertTimestampElements(scope) {
        const root = scope || document;
        root.querySelectorAll('.timestamp[data-utc], .time-value[data-utc]').forEach((element) => {
            const iso = element.getAttribute('data-utc');
            if (!iso) {
                return;
            }
            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) {
                return;
            }
            element.textContent = useLocalTime ? formatLocal(date) : formatUtc(date);
        });
    }

    function updateTimezoneToggleText() {
        const label = useLocalTime ? 'Timezone: Local' : 'Timezone: UTC';
        document.querySelectorAll('.tab-timezone-button').forEach((toggle) => {
            const span = toggle.querySelector('span');
            if (span) {
                span.textContent = label;
            } else {
                toggle.textContent = label;
            }
        });
    }

    function currentMcpBaseUrl() {
        return 'http://' + mcpHost + ':' + String(mcpResolvedPort);
    }

    function normalizeMcpSource(value) {
        const source = String(value || '').trim().toLowerCase();
        if (source === 'stobe') {
            return 'stobe';
        }
        return 'herika';
    }

    function mcpSourceLabel(source) {
        return source === 'stobe' ? 'STOBE' : 'CHIM';
    }

    function renderOpenRouterStatus() {
        if (!mcpModalOpenRouterStatus) {
            return;
        }

        const state = mcpApiKeySource === 'stobe' ? mcpOpenRouterState.stobe : mcpOpenRouterState.herika;
        const sourceLabel = mcpSourceLabel(mcpApiKeySource);
        mcpModalOpenRouterStatus.classList.remove('ok', 'warn');

        if (!state || !state.entry_found) {
            mcpModalOpenRouterStatus.textContent = 'OpenRouter key status: OpenRouter badge not found in ' + sourceLabel + '.';
            mcpModalOpenRouterStatus.classList.add('warn');
            return;
        }

        if (state.key_present) {
            mcpModalOpenRouterStatus.textContent = 'OpenRouter key status: configured in ' + sourceLabel + '.';
            mcpModalOpenRouterStatus.classList.add('ok');
            return;
        }

        mcpModalOpenRouterStatus.textContent = 'OpenRouter key status: empty in ' + sourceLabel + '.';
        mcpModalOpenRouterStatus.classList.add('warn');
    }

    function updateMcpSourceUi() {
        if (mcpModalApiSourceSelect) {
            mcpModalApiSourceSelect.value = mcpApiKeySource;
        }
        renderOpenRouterStatus();
    }

    function renderMcpMeta(connected) {
        if (!mcpStatusMeta) {
            return;
        }
        mcpStatusMeta.innerHTML =
            'Status: ' + (connected ? 'Connected' : 'Disconnected') + '<br>' +
            'AI Key Source: ' + mcpSourceLabel(mcpApiKeySource);
    }

    function escapeMcpHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatMcpMessage(value) {
        const escaped = escapeMcpHtml(value);
        return escaped.replace(/\*\*([\s\S]+?)\*\*/g, '<strong>$1</strong>');
    }

    async function copyMcpMessageToClipboard(text) {
        const value = String(text || '');
        if (value === '') {
            return false;
        }

        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                return true;
            }
        } catch (error) {
            // Fallback path below.
        }

        try {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            textarea.setSelectionRange(0, value.length);
            const copied = document.execCommand('copy');
            document.body.removeChild(textarea);
            return copied;
        } catch (error) {
            return false;
        }
    }

    function buildMcpCopyButton(rawMessage) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mcp-copy-btn';
        button.setAttribute('aria-label', 'Copy message');
        button.setAttribute('title', 'Copy message');
        button.innerHTML =
            '<svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">' +
            '<path d="M2.5 1A1.5 1.5 0 0 0 1 2.5v8A1.5 1.5 0 0 0 2.5 12H3V2.5A1.5 1.5 0 0 1 4.5 1h-2z"/>' +
            '<path d="M4.5 2A1.5 1.5 0 0 0 3 3.5v10A1.5 1.5 0 0 0 4.5 15h8a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 12.5 2h-8zm0 1h8a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5z"/>' +
            '</svg>';

        button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const copied = await copyMcpMessageToClipboard(rawMessage);
            button.classList.remove('copied', 'failed');
            button.classList.add(copied ? 'copied' : 'failed');
            button.setAttribute('title', copied ? 'Copied' : 'Copy failed');
            window.setTimeout(() => {
                button.classList.remove('copied', 'failed');
                button.setAttribute('title', 'Copy message');
            }, 1200);
        });

        return button;
    }

    function appendMcpMessage(kind, message) {
        if (!mcpChatHistory) {
            return;
        }
        const row = document.createElement('div');
        row.className = 'mcp-chat-row ' + kind;
        const bubble = document.createElement('div');
        bubble.className = 'mcp-chat-bubble';
        const rawMessage = String(message || '');
        bubble.innerHTML = formatMcpMessage(rawMessage);
        if (kind !== 'user') {
            bubble.classList.add('has-copy');
            bubble.appendChild(buildMcpCopyButton(rawMessage));
        }
        row.appendChild(bubble);
        mcpChatHistory.appendChild(row);
        mcpChatHistory.scrollTop = mcpChatHistory.scrollHeight;
    }

    async function loadMcpConnectionConfig(showMessage) {
        try {
            const response = await fetch(mcpConnectionConfigApi, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();
            if (!payload || !payload.success || !payload.data) {
                throw new Error('Dashboard MCP config unavailable');
            }
            const source = normalizeMcpSource(payload.data.api_key_source || 'herika');
            const openRouter = payload.data.openrouter || {};
            const chimState = openRouter.chim || {};
            const stobeState = openRouter.stobe || {};
            mcpOpenRouterState = {
                herika: {
                    entry_found: Boolean(chimState.entry_found),
                    key_present: Boolean(chimState.key_present),
                    label: String(chimState.label || 'OpenRouter'),
                },
                stobe: {
                    entry_found: Boolean(stobeState.entry_found),
                    key_present: Boolean(stobeState.key_present),
                    label: String(stobeState.label || 'OpenRouter'),
                },
            };
            mcpApiKeySource = source;
            updateMcpSourceUi();
            if (showMessage) {
                appendMcpMessage('assistant', 'MCP AI key source reloaded: ' + mcpSourceLabel(mcpApiKeySource) + '.');
            }
        } catch (error) {
            if (showMessage) {
                appendMcpMessage('error', 'Failed to load MCP key source: ' + String(error && error.message ? error.message : error));
            }
        }
    }

    async function saveMcpConnectionConfig() {
        if (!mcpModalSaveBtn) {
            return;
        }

        const requestedSource = normalizeMcpSource(
            mcpModalApiSourceSelect ? mcpModalApiSourceSelect.value : mcpApiKeySource
        );
        const sourceState = requestedSource === 'stobe' ? mcpOpenRouterState.stobe : mcpOpenRouterState.herika;
        if (!sourceState || !sourceState.entry_found) {
            appendMcpMessage('error', 'Cannot save MCP settings: OpenRouter badge is missing in ' + mcpSourceLabel(requestedSource) + '.');
            return;
        }
        if (!sourceState.key_present) {
            appendMcpMessage('error', 'Cannot save MCP settings: OpenRouter key is empty in ' + mcpSourceLabel(requestedSource) + '.');
            return;
        }

        const roundsRaw = mcpModalMaxRounds ? String(mcpModalMaxRounds.value || DEFAULT_MCP_MAX_ROUNDS).trim() : DEFAULT_MCP_MAX_ROUNDS;
        const parsedRounds = parseInt(roundsRaw, 10);
        const temperatureRaw = mcpModalTemperature ? String(mcpModalTemperature.value || DEFAULT_MCP_TEMPERATURE).trim() : DEFAULT_MCP_TEMPERATURE;
        const parsedTemperature = Number.parseFloat(temperatureRaw);
        const normalizedTemperature = Number.isFinite(parsedTemperature)
            ? Math.min(2, Math.max(0, parsedTemperature))
            : Number.parseFloat(DEFAULT_MCP_TEMPERATURE);
        const systemPromptRaw = mcpModalSystemPrompt ? String(mcpModalSystemPrompt.value || '').trim() : '';
        const settingsBody = {
            llm_connector_id: '',
            model: mcpModalModel ? (String(mcpModalModel.value || '').trim() || DEFAULT_MCP_MODEL) : DEFAULT_MCP_MODEL,
            temperature: String(normalizedTemperature),
            max_tool_rounds: Number.isNaN(parsedRounds) || parsedRounds < 1 ? DEFAULT_MCP_MAX_ROUNDS : String(parsedRounds),
            system_prompt: systemPromptRaw !== '' ? systemPromptRaw : DEFAULT_MCP_SYSTEM_PROMPT,
        };

        mcpModalSaveBtn.disabled = true;
        const priorLabel = mcpModalSaveBtn.textContent;
        mcpModalSaveBtn.textContent = 'Saving...';

        try {
            const settingsResponse = await fetch(herikaMcpConfigApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(settingsBody)
            });
            const settingsPayload = await settingsResponse.json();
            if (!settingsResponse.ok || !settingsPayload || !settingsPayload.success) {
                throw new Error(settingsPayload && settingsPayload.error ? String(settingsPayload.error) : 'Failed to save MCP runtime settings');
            }

            const response = await fetch(mcpConnectionConfigApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ api_key_source: requestedSource })
            });
            const payload = await response.json();
            if (!response.ok || !payload || !payload.success || !payload.data) {
                throw new Error(payload && payload.error ? String(payload.error) : 'Failed to save MCP key source');
            }

            mcpApiKeySource = normalizeMcpSource(payload.data.api_key_source || 'herika');
            updateMcpSourceUi();
            appendMcpMessage('assistant', 'MCP settings saved. AI key source: ' + mcpSourceLabel(mcpApiKeySource) + '.');
            if (payload.warning) {
                appendMcpMessage('assistant', 'Warning: ' + String(payload.warning));
            }
            await loadMcpConnectionConfig(false);
            await loadHerikaMcpSettings();
            await checkMcpConnection();
        } catch (error) {
            appendMcpMessage('error', 'Failed to save MCP settings: ' + String(error && error.message ? error.message : error));
            updateMcpSourceUi();
        } finally {
            mcpModalSaveBtn.disabled = false;
            mcpModalSaveBtn.textContent = priorLabel || 'Save Settings';
        }
    }

    async function loadMcpSettingsModalData(showMessage) {
        try {
            await loadMcpConnectionConfig(false);
            await loadHerikaMcpSettings();
            if (showMessage) {
                appendMcpMessage('assistant', 'MCP settings loaded.');
            }
        } catch (error) {
            if (showMessage) {
                appendMcpMessage('error', 'Failed to load MCP settings: ' + String(error && error.message ? error.message : error));
            }
            throw error;
        }
    }

    async function loadHerikaMcpSettings() {
        try {
            const response = await fetch(herikaMcpConfigApi, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();
            if (!payload || !payload.success || !payload.data) {
                throw new Error('Herika MCP config unavailable');
            }
            const cfg = payload.data || {};
            const parsedPort = parseInt(String(cfg.port || defaultMcpPort), 10);
            if (!Number.isNaN(parsedPort) && parsedPort > 0 && parsedPort <= 65535) {
                mcpResolvedPort = parsedPort;
            }
            if (mcpModalModel) {
                const modelValue = String(cfg.model || '').trim();
                mcpModalModel.value = modelValue !== '' ? modelValue : DEFAULT_MCP_MODEL;
            }
            if (mcpModalTemperature) {
                const temperatureValue = String(cfg.temperature || '').trim();
                mcpModalTemperature.value = temperatureValue !== '' ? temperatureValue : DEFAULT_MCP_TEMPERATURE;
            }
            if (mcpModalMaxRounds) {
                const roundsValue = String(cfg.max_tool_rounds || '').trim();
                mcpModalMaxRounds.value = roundsValue !== '' ? roundsValue : DEFAULT_MCP_MAX_ROUNDS;
            }
            if (mcpModalSystemPrompt) {
                const promptValue = String(cfg.system_prompt || '').trim();
                mcpModalSystemPrompt.value = promptValue !== '' ? promptValue : DEFAULT_MCP_SYSTEM_PROMPT;
            }
        } catch (error) {
            // Keep fallback values.
        }
    }

    async function checkMcpConnection() {
        if (!mcpStatusPill || !mcpStatusMeta) {
            return;
        }

        mcpStatusPill.textContent = 'Checking...';
        mcpStatusPill.classList.remove('ok', 'fail');

        try {
            const statusUrl = mcpStatusApiBase + '?mcp_status=1&port=' + encodeURIComponent(String(mcpResolvedPort));
            const response = await fetch(statusUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' }
            });
            const payload = await response.json();
            const ok = !!(payload && payload.ok);

            if (ok) {
                mcpStatusPill.textContent = 'Connected';
                mcpStatusPill.classList.add('ok');
            } else {
                mcpStatusPill.textContent = 'Disconnected';
                mcpStatusPill.classList.add('fail');
            }
            renderMcpMeta(ok);
        } catch (error) {
            mcpStatusPill.textContent = 'Disconnected';
            mcpStatusPill.classList.add('fail');
            renderMcpMeta(false);
        }
    }

    async function sendMcpMessage() {
        if (!mcpChatInput || !mcpSendBtn || mcpRequestInFlight) {
            return;
        }

        const text = (mcpChatInput.value || '').trim();
        if (!text) {
            return;
        }

        appendMcpMessage('user', text);
        mcpChatInput.value = '';
        mcpRequestInFlight = true;
        mcpSendBtn.disabled = true;
        mcpSendBtn.textContent = 'Sending...';

        try {
            const response = await fetch(currentMcpBaseUrl() + '/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            if (!response.ok) {
                const raw = await response.text();
                throw new Error(raw || ('HTTP ' + String(response.status)));
            }
            const payload = await response.json();
            const answer = (payload && payload.response) ? String(payload.response) : JSON.stringify(payload || {});
            appendMcpMessage('assistant', answer || '(empty response)');
        } catch (error) {
            appendMcpMessage('error', 'MCP request failed: ' + String(error && error.message ? error.message : error));
        } finally {
            mcpRequestInFlight = false;
            mcpSendBtn.disabled = false;
            mcpSendBtn.textContent = 'Send';
        }
    }

    function applySearchToContainer(container, query) {
        const q = query.trim().toLowerCase();
        container.querySelectorAll('.log-entry, tr').forEach((row) => {
            if (!q) {
                row.style.display = '';
                return;
            }
            const text = (row.textContent || '').toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    }

    function updateLevelCounts(containerId) {
        const container = document.getElementById(containerId + '_container');
        if (!container) {
            return;
        }
        ['error', 'warn', 'info', 'debug', 'trace'].forEach((level) => {
            const countEl = document.getElementById(containerId + '_' + level + '_count');
            if (!countEl) {
                return;
            }
            const count = container.querySelectorAll('.log-entry[data-level="' + level + '"]').length;
            countEl.textContent = String(count);
        });
    }

    function applyLevelFilters(containerId) {
        const container = document.getElementById(containerId + '_container');
        if (!container) {
            return;
        }

        const queryInput = document.querySelector('.search-input[data-target="' + containerId + '_container"]');
        const query = queryInput ? (queryInput.value || '').trim().toLowerCase() : '';

        const levelEnabled = {};
        document.querySelectorAll('.level-filter[data-container="' + containerId + '"]').forEach((checkbox) => {
            levelEnabled[checkbox.dataset.level] = checkbox.checked;
        });

        container.querySelectorAll('.log-entry').forEach((entry) => {
            const level = entry.dataset.level || '';
            const levelPass = !level || !!levelEnabled[level];
            const searchPass = !query || ((entry.textContent || '').toLowerCase().includes(query));
            entry.style.display = levelPass && searchPass ? '' : 'none';
        });
    }

    function setActiveTab(tabName) {
        document.querySelectorAll('.tab-button').forEach((button) => {
            const isActive = button.dataset.tab === tabName;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        document.querySelectorAll('.tab-panel').forEach((panel) => {
            const panelTab = panel.id.replace('tab-', '');
            panel.classList.toggle('active', panelTab === tabName);
        });

        localStorage.setItem(TAB_KEY, tabName);
    }

    function downloadPanelLogs(panelId, prefix) {
        const panel = document.getElementById(panelId);
        if (!panel) {
            return;
        }

        const chunks = [];
        panel.querySelectorAll('.log-section').forEach((section) => {
            const titleNode = section.querySelector('.section-header h2');
            const title = titleNode ? titleNode.textContent.trim() : 'Log';
            chunks.push('==== ' + title + ' ====');
            section.querySelectorAll('.log-entry').forEach((row) => {
                if (row instanceof HTMLElement) {
                    const display = window.getComputedStyle(row).display;
                    if (display === 'none') {
                        return;
                    }
                }
                const line = String(row.textContent || '').replace(/\r?\n$/, '');
                if (line !== '') {
                    chunks.push(line);
                }
            });
            chunks.push('');
        });

        const blob = new Blob([chunks.join('\n')], { type: 'text/plain;charset=utf-8' });
        const now = new Date();
        const stamp = [
            now.getUTCFullYear(),
            String(now.getUTCMonth() + 1).padStart(2, '0'),
            String(now.getUTCDate()).padStart(2, '0'),
            String(now.getUTCHours()).padStart(2, '0'),
            String(now.getUTCMinutes()).padStart(2, '0'),
            String(now.getUTCSeconds()).padStart(2, '0')
        ].join('');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = prefix + '_logs_' + stamp + '.txt';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(link.href);
    }

    document.querySelectorAll('.search-input').forEach((input) => {
        input.addEventListener('input', () => {
            const targetId = input.dataset.target || '';
            const containerId = targetId.replace('_container', '');
            applyLevelFilters(containerId);
        });
    });

    document.querySelectorAll('.modal-search-input').forEach((input) => {
        input.addEventListener('input', () => {
            const targetId = input.dataset.target || '';
            const target = document.getElementById(targetId);
            if (!target) {
                return;
            }
            applySearchToContainer(target, input.value || '');
        });
    });

    document.querySelectorAll('.level-filter').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const containerId = checkbox.dataset.container || '';
            if (!containerId) {
                return;
            }
            applyLevelFilters(containerId);
        });
    });

    document.querySelectorAll('.filter-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const containerId = button.dataset.container || '';
            const action = button.dataset.action || '';
            if (!containerId) {
                return;
            }
            document.querySelectorAll('.level-filter[data-container="' + containerId + '"]').forEach((checkbox) => {
                checkbox.checked = action === 'all';
            });
            applyLevelFilters(containerId);
        });
    });

    document.querySelectorAll('.log-container[data-level-filter="1"]').forEach((container) => {
        const containerId = (container.id || '').replace('_container', '');
        if (containerId === '') {
            return;
        }
        updateLevelCounts(containerId);
        applyLevelFilters(containerId);
    });

    document.querySelectorAll('.expand-button').forEach((button) => {
        button.addEventListener('click', () => {
            const sourceId = button.getAttribute('data-source') || '';
            const modalId = button.getAttribute('data-modal') || '';
            if (!sourceId || !modalId) {
                return;
            }

            const source = document.getElementById(sourceId);
            const modal = document.getElementById(modalId);
            const modalContent = document.getElementById(modalId + '_content');
            if (!source || !modal || !modalContent) {
                return;
            }

            modalContent.innerHTML = '<div class="log-container">' + source.innerHTML + '</div>';
            modal.style.display = 'block';
            convertTimestampElements(modal);
        });
    });

    document.body.addEventListener('click', async (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const copyBtn = target ? target.closest('.copy-llm-btn') : null;
        if (!(copyBtn instanceof HTMLElement)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const llmBlock = copyBtn.closest('.llm-block');
        if (!(llmBlock instanceof HTMLElement)) {
            return;
        }

        const copyChunks = Array.from(llmBlock.querySelectorAll('.llm-copy-content'));
        let content = '';
        if (copyChunks.length > 0) {
            content = copyChunks
                .map((node) => String(node.textContent || ''))
                .join('\n')
                .replace(/\s+$/, '');
        } else {
            const contentNode = llmBlock.querySelector('.log-message .llm-content');
            content = contentNode ? String(contentNode.textContent || '').replace(/\s+$/, '') : '';
        }
        const copied = content !== '' ? await copyMcpMessageToClipboard(content) : false;

        copyBtn.classList.remove('copied', 'failed');
        copyBtn.classList.add(copied ? 'copied' : 'failed');
        copyBtn.setAttribute('title', copied ? 'Copied' : 'Copy failed');
        window.setTimeout(() => {
            copyBtn.classList.remove('copied', 'failed');
            copyBtn.setAttribute('title', 'Copy to clipboard');
        }, 1200);
    });

    document.querySelectorAll('.close-modal').forEach((button) => {
        button.addEventListener('click', () => {
            const modalId = button.getAttribute('data-close-modal');
            if (!modalId) {
                return;
            }
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });

    window.addEventListener('click', (event) => {
        if (event.target && event.target.classList && event.target.classList.contains('log-modal')) {
            event.target.style.display = 'none';
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.log-modal').forEach((modal) => {
            modal.style.display = 'none';
        });
    });

    document.querySelectorAll('.tab-refresh-button').forEach((refreshBtn) => {
        refreshBtn.addEventListener('click', () => {
            window.location.reload();
        });
    });

    document.querySelectorAll('.tab-download-button').forEach((downloadBtn) => {
        downloadBtn.addEventListener('click', () => {
            const panelId = downloadBtn.getAttribute('data-panel') || 'tab-distro';
            const prefix = downloadBtn.getAttribute('data-download-prefix') || 'distro_debugger';
            downloadPanelLogs(panelId, prefix);
        });
    });

    document.querySelectorAll('.tab-timezone-button').forEach((timezoneBtn) => {
        timezoneBtn.addEventListener('click', () => {
            useLocalTime = !useLocalTime;
            setTimezoneMode(useLocalTime ? LOCAL_MODE : UTC_MODE);
            updateTimezoneToggleText();
            convertTimestampElements(document);
        });
    });

    document.querySelectorAll('.tab-button').forEach((button) => {
        button.addEventListener('click', () => {
            const tabName = button.dataset.tab || 'distro';
            setActiveTab(tabName);
        });
    });

    if (mcpSendBtn) {
        mcpSendBtn.addEventListener('click', () => {
            sendMcpMessage();
        });
    }

    if (mcpChatInput) {
        mcpChatInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMcpMessage();
            }
        });
    }

    async function openMcpSettingsModal() {
        if (!mcpSettingsModal) {
            return;
        }
        mcpSettingsModal.style.display = 'block';
        try {
            await loadMcpSettingsModalData(false);
        } catch (error) {
            // Error message already emitted when requested.
        }
    }

    if (mcpOpenSettingsBtnTop) {
        mcpOpenSettingsBtnTop.addEventListener('click', () => {
            openMcpSettingsModal();
        });
    }

    if (mcpModalApiSourceSelect) {
        mcpModalApiSourceSelect.addEventListener('change', () => {
            mcpApiKeySource = normalizeMcpSource(mcpModalApiSourceSelect.value || 'herika');
            updateMcpSourceUi();
            renderMcpMeta(mcpStatusPill ? mcpStatusPill.classList.contains('ok') : false);
        });
    }

    if (mcpModalSaveBtn) {
        mcpModalSaveBtn.addEventListener('click', () => {
            saveMcpConnectionConfig();
        });
    }

    if (mcpModalReloadBtn) {
        mcpModalReloadBtn.addEventListener('click', async () => {
            try {
                await loadMcpSettingsModalData(true);
            } catch (error) {
                // Error handling and chat status are managed in loader.
            }
            await checkMcpConnection();
        });
    }

    const initialTab = localStorage.getItem(TAB_KEY) || 'distro';
    if (document.querySelector('.tab-button[data-tab="' + initialTab + '"]')) {
        setActiveTab(initialTab);
    } else {
        setActiveTab('distro');
    }

    updateTimezoneToggleText();
    convertTimestampElements(document);
    updateMcpSourceUi();
    renderMcpMeta(false);

    if (mcpChatHistory) {
        appendMcpMessage('assistant', 'Kagrenac MCP chat ready. Current AI key source: ' + mcpSourceLabel(mcpApiKeySource) + '.');
        (async () => {
            try {
                await loadMcpSettingsModalData(false);
            } catch (error) {
                // Keep loading flow alive even if settings endpoint is unavailable.
            }
            await checkMcpConnection();
        })();
    }

    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        window.setTimeout(() => {
            loadingOverlay.classList.remove('active');
        }, 180);
    }
})();
</script>
</body>
</html>
