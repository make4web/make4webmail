<?php
declare(strict_types=1);

namespace M4W\Core;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $__view, array $__data = []): string
    {
        $__file = M4W_APP . '/views/' . $__view . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException("View not found: $__view");
        }
        extract(self::$shared + $__data, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
