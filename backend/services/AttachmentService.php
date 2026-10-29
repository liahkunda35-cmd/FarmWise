<?php

class AttachmentService
{
    public static function modules(): array
    {
        return [
            'farms', 'fields', 'plantings', 'crop_growth', 'pest_scouting', 'pest_treatments',
            'animals', 'livestock_health', 'invoices', 'quotations', 'purchase_orders', 'equipment',
        ];
    }

    public static function allowed(string $module): bool
    {
        return in_array($module, self::modules(), true);
    }

    public static function for(string $module, int $recordId): array
    {
        if (!table_exists('attachments')) {
            return [];
        }
        return db()->select(
            'SELECT * FROM attachments WHERE module = ? AND record_id = ? ORDER BY id DESC',
            [$module, $recordId]
        );
    }

    public static function store(string $module, array $row, array $file): array
    {
        if (!self::allowed($module)) {
            return ['ok' => false, 'error' => 'Photos are not enabled for this module.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'Choose a file to upload.'];
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The file could not be uploaded.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Files must be 5 MB or smaller.'];
        }
        $name = (string) ($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        if (!isset($allowed[$ext])) {
            return ['ok' => false, 'error' => 'Allowed types: JPG, PNG, GIF, WEBP and PDF.'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']) ?: ($file['type'] ?? $allowed[$ext]);
        if (!in_array($mime, $allowed, true)) {
            return ['ok' => false, 'error' => 'That file type is not allowed.'];
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        $dir = dirname(__DIR__, 2) . '/storage/uploads/' . (int) ($row['farm_id'] ?? 0) . '/' . $module . '/' . (int) $row['id'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Could not create upload folder.'];
        }
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'Could not store the uploaded file.'];
        }
        $id = db()->insert('attachments', [
            'farm_id' => $row['farm_id'] ?? Auth::farmId(),
            'module' => $module,
            'record_id' => $row['id'],
            'original_name' => $name,
            'stored_name' => $stored,
            'mime' => $mime,
            'size_bytes' => $size,
            'user_id' => Auth::id(),
        ]);
        Audit::log('create', $module, (int) $row['id'], 'Attachment uploaded');
        return ['ok' => true, 'id' => $id, 'error' => null];
    }

    public static function path(array $att): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads/' . (int) ($att['farm_id'] ?? 0) . '/' . $att['module'] . '/' . (int) $att['record_id'] . '/' . $att['stored_name'];
    }

    public static function load(int $id): ?array
    {
        return db()->find('attachments', $id);
    }

    public static function destroy(array $att): void
    {
        $path = self::path($att);
        if (is_file($path)) {
            @unlink($path);
        }
        db()->delete('attachments', $att['id']);
        Audit::log('delete', $att['module'], (int) $att['record_id'], 'Attachment removed');
    }

    public static function isImage(array $att): bool
    {
        return str_starts_with((string) ($att['mime'] ?? ''), 'image/');
    }
}
