<?php

namespace FA\Mcp\OAuth\Http;

/**
 * Renders the authorize flow's three pages (login, consent, error) from
 * src/OAuth/Page, inside one self-contained HTML document: inline CSS, no
 * script, no external asset, so the strict CSP AuthorizeAction sends holds.
 *
 * Each template gets $e, the escaper (htmlspecialchars, ENT_QUOTES, UTF-8),
 * and passes every dynamic value through it.
 */
final class Pages
{
    private const STYLE = <<<'CSS'
:root { color-scheme: light dark; --bg: #f3f4f6; --card: #fff; --text: #1f2937; --muted: #6b7280;
        --border: #d1d5db; --accent: #1d4ed8; --accent-text: #fff; --error: #b91c1c; }
@media (prefers-color-scheme: dark) {
  :root { --bg: #111827; --card: #1f2937; --text: #f3f4f6; --muted: #9ca3af;
          --border: #374151; --accent: #3b82f6; --error: #f87171; }
}
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
       padding: 16px; background: var(--bg); color: var(--text);
       font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
main { width: 100%; max-width: 420px; background: var(--card); border: 1px solid var(--border);
       border-radius: 8px; padding: 28px; }
h1 { font-size: 20px; margin: 0 0 12px; }
p { margin: 0 0 16px; }
.muted { color: var(--muted); font-size: 13px; }
.error { color: var(--error); font-weight: 600; }
label { display: block; margin-bottom: 12px; }
input[type=text], input[type=password], select { display: block; width: 100%; margin-top: 4px; padding: 8px;
       border: 1px solid var(--border); border-radius: 6px; background: var(--card); color: var(--text);
       font: inherit; }
fieldset { border: 1px solid var(--border); border-radius: 6px; margin: 0 0 16px; padding: 8px 12px; }
fieldset label { margin: 6px 0; }
.buttons { display: flex; gap: 8px; justify-content: flex-end; }
button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border); background: var(--card);
         color: var(--text); font: inherit; cursor: pointer; }
button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); }
CSS;

    private string $dir;

    /** @param string|null $dir the templates' directory; src/OAuth/Page by default */
    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? dirname(__DIR__) . '/Page', '/');
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $template 'login', 'consent' or 'error'
     * @param array<string, mixed> $vars the template's variables
     */
    public function render(string $template, string $title, array $vars): string
    {
        $file = $this->dir . '/' . basename($template) . '.php';
        $e = static function ($value): string {
            return self::escape(is_scalar($value) ? (string) $value : '');
        };

        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__vars, callable $e): void {
                extract($__vars, EXTR_SKIP);
                include $__file;
            })($file, $vars, $e);
            $main = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
            . "<meta name=\"referrer\" content=\"no-referrer\">\n"
            . '<title>' . self::escape($title) . "</title>\n"
            . '<style>' . self::STYLE . "</style>\n</head>\n<body>\n"
            . $main
            . "</body>\n</html>\n";
    }
}
