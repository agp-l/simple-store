<?php
declare(strict_types=1);

namespace SimpleStore\Rendering;

/** Decorative icons share one geometry, inherit text color and never replace labels. */
final class Icon
{
    private const PATHS = [
        'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2',
        'truck' => 'M2 4h12v13H2V4z M14 9h4l4 5v3h-8 M5 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4 M18 17a2 2 0 1 0 0 4 2 2 0 0 0 0-4',
        'edit' => 'M16 3l5 5-12 12H4v-5L16 3z M13 6l5 5',
        'eye-off' => 'M3 3l18 18 M10 5c5-1 9 4 12 7-1 2-3 4-5 5 M6 6c-2 2-3 4-4 6 3 5 7 7 12 6 M10 10a3 3 0 0 0 4 4',
        'eye' => 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12 M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
        'delete' => 'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
        'save' => 'M5 3h12l4 4v14H3V3h2 M7 3v6h10V3 M7 21v-8h10v8',
        'plus' => 'M12 5v14 M5 12h14',
        'arrow-right' => 'M4 12h16 M14 6l6 6-6 6',
        'arrow-left' => 'M20 12H4 M10 6l-6 6 6 6',
        'external' => 'M14 3h7v7 M21 3L10 14 M10 3H3v18h18v-7',
        'copy' => 'M9 9h12v12H9z M15 9V3H3v12h6',
        'download' => 'M12 3v12 M7 10l5 5 5-5 M3 16v5h18v-5',
        'upload' => 'M12 16V4 M7 9l5-5 5 5 M3 16v5h18v-5',
        'search' => 'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6',
        'filter' => 'M3 4h18l-7 8v7l-4 2v-9L3 4z',
        'refresh' => 'M20 7a9 9 0 0 0-15-1L3 9 M3 3v6h6 M4 17a9 9 0 0 0 15 1l2-3 M21 21v-6h-6',
        'check' => 'M4 12l5 5L20 6',
        'cancel' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M6 6l12 12',
        'mail' => 'M3 5h18v14H3z M3 5l9 8 9-8',
        'user' => 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M4 21v-3a8 8 0 0 1 16 0v3',
        'phone' => 'M7 3H3v4c0 8 6 14 14 14h4v-4l-5-2-2 2c-3-1-6-4-7-7l2-2-2-5z',
        'pin' => 'M12 22s8-7 8-13a8 8 0 0 0-16 0c0 6 8 13 8 13 M12 6a3 3 0 1 0 0 6 3 3 0 0 0 0-6',
        'home' => 'M2 11l10-8 10 8 M5 9v12h14V9 M9 21v-8h6v8',
        'hash' => 'M9 3L7 21 M17 3l-2 18 M4 9h17 M3 15h17',
        'globe' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M3 12h18 M12 3c-5 5-5 13 0 18 5-5 5-13 0-18',
        'document' => 'M5 3h10l4 4v14H5z M14 3v5h5 M8 12h8 M8 16h8',
        'package' => 'M3 7l9-4 9 4v10l-9 4-9-4V7z M3 7l9 4 9-4 M12 11v10 M7 5l10 4',
        'users' => 'M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M2 21v-3a7 7 0 0 1 14 0v3 M17 4a4 4 0 0 1 0 8 M19 15a5 5 0 0 1 3 4v2',
        'settings' => 'M4 6h16 M4 12h16 M4 18h16 M8 3v6 M16 9v6 M10 15v6',
        'database' => 'M3 6c0-4 18-4 18 0s-18 4-18 0v12c0 4 18 4 18 0V6 M3 12c0 4 18 4 18 0',
        'image' => 'M3 3h18v18H3z M3 17l6-6 4 4 3-3 5 5 M16 6h.01',
        'list' => 'M8 5h13 M8 12h13 M8 19h13 M3 5h.01 M3 12h.01 M3 19h.01',
        'folder' => 'M3 5h7l2 3h9v13H3V5z',
        'card' => 'M3 5h18v14H3z M3 9h18 M6 15h4',
        'lock' => 'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4',
        'logout' => 'M9 3H3v18h6 M9 12h12 M16 7l5 5-5 5',
        'book' => 'M12 5v16 M12 5C8 2 5 2 2 4v15c4-2 7-1 10 2 3-3 6-4 10-2V4c-3-2-6-2-10 1',
    ];

    public static function render(string $name): string
    {
        if (!isset(self::PATHS[$name])) {
            throw new \InvalidArgumentException('Unknown interface icon: ' . $name);
        }
        return '<svg class="panel-icon" width="18" height="18" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="'
            . self::PATHS[$name] . '"/></svg>';
    }
}
