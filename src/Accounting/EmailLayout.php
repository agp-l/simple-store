<?php
declare(strict_types=1);

namespace SimpleStore\Accounting;

/** Table-based, image-independent layout with inline styles for mail clients. */
final class EmailLayout
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function paragraph(string $text): string
    {
        return '<p style="margin:0 0 18px;font-size:16px;line-height:1.65;overflow-wrap:anywhere">' . nl2br(self::escape($text)) . '</p>';
    }

    public static function heading(string $text): string
    {
        return '<h2 style="margin:28px 0 12px;font-size:18px;line-height:1.4;color:#18212b">' . self::escape($text) . '</h2>';
    }

    public static function notice(string $text): string
    {
        return '<div style="margin:20px 0;padding:16px 18px;border-left:3px solid #607d99;background:#f0f5fa;color:#18212b;font-size:15px;line-height:1.65">' . nl2br(self::escape($text)) . '</div>';
    }

    /** Ordered pairs keep fields intact even if two editable labels are identical. */
    public static function facts(array $facts): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:15px;line-height:1.6">';
        foreach ($facts as [$label, $value]) {
            if ($value === '') continue;
            $html .= '<tr><th width="40%" align="left" style="padding:10px 12px 10px 0;border-bottom:1px solid #e7ebef;color:#283443;font-weight:normal;vertical-align:top;overflow-wrap:anywhere">' . self::escape($label) .
                '</th><td style="padding:10px 0;border-bottom:1px solid #e7ebef;vertical-align:top;overflow-wrap:anywhere;word-break:break-word">' . nl2br(self::escape($value)) . '</td></tr>';
        }
        return $html . '</table>';
    }

    public static function factLines(array $facts): array
    {
        $lines = [];
        foreach ($facts as [$label, $value]) if ($value !== '') $lines[] = $label . ': ' . $value;
        return $lines;
    }

    public static function safeUrl(string $url): string
    {
        return $url !== '' && strlen($url) <= 1000 && preg_match('/[\x00-\x20\x7f]/', $url) !== 1 &&
            filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https' &&
            parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null ? $url : '';
    }

    public static function actions(array $links): string
    {
        $html = ''; $first = true;
        foreach ($links as [$label, $url]) {
            if (self::safeUrl($url) === '') continue;
            $style = $first ? 'background:#365d40;color:#fff;padding:13px 20px;text-decoration:none;' :
                'color:#254e3b;padding:10px 0;text-decoration:underline;';
            $html .= '<p style="margin:12px 0"><a href="' . self::escape($url) . '" style="display:inline-block;max-width:100%;box-sizing:border-box;font-size:16px;font-weight:bold;line-height:1.5;overflow-wrap:anywhere;border-radius:4px;' . $style . '">' . self::escape($label) . '</a></p>';
            $first = false;
        }
        return $html === '' ? '' : '<div style="margin-top:24px">' . $html . '</div>';
    }

    public static function message(string $subject, string $title, string $intro, string $content,
        array $lines, array $configuration = [], string $eyebrow = '', array $footerLinks = []): array
    {
        $copy = MailContent::copy($configuration);
        $vars = MailContent::variables($configuration);
        $signoff = MailContent::fill($copy['signoff'], $vars);
        $footer = MailContent::fill($copy['footer'], $vars);
        $brandLine = MailContent::fill($copy['brand_line'], $vars);
        $linksHtml = '';
        foreach ($footerLinks as [$label, $url]) {
            if (self::safeUrl($url) === '') continue;
            $lines[] = $label . ': ' . $url;
            $linksHtml .= '<a href="' . self::escape($url) . '" style="display:inline-block;margin:8px 14px 0 0;color:#254e3b;text-decoration:underline">' . self::escape($label) . '</a>';
        }
        $text = implode("\n", array_merge([$intro, '', $title, ''], $lines, ['', $signoff, '', $footer])) . "\n";
        $html = '<!doctype html><html lang="cs"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::escape($subject) . '</title>' .
            '<style>@media only screen and (max-width:480px){.mail-gutter{padding:12px 6px!important}.mail-content{padding:24px 18px!important}.mail-title{font-size:26px!important}.mail-brand{padding:24px 18px!important}}</style></head>' .
            '<body style="margin:0;padding:0;background:#f1f3f6;color:#18212b;font:16px/1.6 Arial,Helvetica,sans-serif">' .
            '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all">' . self::escape(str_replace("\n", ' ', $intro)) . '</div>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;background:#f1f3f6"><tr><td align="center" class="mail-gutter" style="padding:32px 12px">' .
            '<!--[if mso]><table role="presentation" width="640"><tr><td><![endif]-->' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;border-collapse:collapse;background:#fff;border:1px solid #e0e5eb">' .
            '<tr><td class="mail-brand" style="padding:26px 32px;background:#222831;color:#fff"><strong style="font-size:24px;line-height:1.25;overflow-wrap:anywhere">' . self::escape($vars['shop_name']) . '</strong>' .
            ($brandLine === '' ? '' : '<div style="font-size:13px;color:#e3e8ed;padding-top:5px">' . self::escape($brandLine) . '</div>') .
            '</td></tr><tr><td height="4" style="height:4px;background:#aac98a;font-size:0;line-height:0">&nbsp;</td></tr>' .
            '<tr><td class="mail-content" style="padding:32px">' . ($eyebrow === '' ? '' : '<p style="margin:0 0 10px;font-size:12px;font-weight:bold;letter-spacing:1px;color:#365d40;text-transform:uppercase">' . self::escape($eyebrow) . '</p>') .
            '<h1 class="mail-title" style="margin:0 0 20px;font-size:30px;line-height:1.25;letter-spacing:-.5px;overflow-wrap:anywhere">' . self::escape($title) . '</h1>' . self::paragraph($intro) . $content .
            '<p style="margin:30px 0 0;padding-top:20px;border-top:1px solid #e7ebef;font-size:15px;line-height:1.65">' . nl2br(self::escape($signoff)) . '</p></td></tr>' .
            '<tr><td class="mail-content" style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e7ebef;font-size:13px;line-height:1.7;color:#283443">' . nl2br(self::escape($footer)) .
            ($linksHtml === '' ? '' : '<div>' . $linksHtml . '</div>') . '</td></tr></table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';
        return ['subject' => MailContent::subject($subject), 'text' => $text, 'html' => $html];
    }
}
