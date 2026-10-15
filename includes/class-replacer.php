<?php

namespace CubeDigital\ImageReplacer;

if (!defined('ABSPATH')) {
    exit;
}

class Replacer {
    const MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public static function is_supported($attachment_id) {
        if (!wp_attachment_is_image($attachment_id)) {
            return false;
        }
        return in_array(get_post_mime_type($attachment_id), self::MIMES, true);
    }

    public static function preflight($attachment_id) {
        if (!self::is_supported($attachment_id)) {
            return new \WP_Error('cdair_type', __('This file is not a supported image.', 'cubedigital-image-replacer'));
        }
        $targets = self::live_targets($attachment_id);
        if (!$targets) {
            return new \WP_Error('cdair_missing', __('The image file was not found on disk.', 'cubedigital-image-replacer'));
        }
        foreach ($targets as $path) {
            if (self::is_animated_gif($path)) {
                return new \WP_Error('cdair_gif', __('Animated GIFs are left unchanged.', 'cubedigital-image-replacer'));
            }
            if (!is_writable($path)) {
                return new \WP_Error('cdair_writable', __('The image file is not writable, so nothing was changed.', 'cubedigital-image-replacer'));
            }
        }
        $source = self::source_file($attachment_id);
        if ($source === '' || !is_readable($source)) {
            return new \WP_Error('cdair_source', __('The source image could not be read.', 'cubedigital-image-replacer'));
        }
        return true;
    }

    public static function source_file($attachment_id) {
        $full = self::path_from_meta($attachment_id, '_cdair_backup_full');
        if ($full !== '' && is_file($full)) {
            return $full;
        }
        $attached = self::path_from_meta($attachment_id, '_cdair_backup_attached');
        if ($attached !== '' && is_file($attached)) {
            return $attached;
        }
        if (function_exists('wp_get_original_image_path')) {
            $original = wp_get_original_image_path($attachment_id);
            if ($original && is_file($original)) {
                return $original;
            }
        }
        $live = get_attached_file($attachment_id);
        if ($live && is_file($live)) {
            return $live;
        }
        return '';
    }

    public static function commit($attachment_id, $binary, $saturation) {
        wp_raise_memory_limit('image');
        $targets = self::live_targets($attachment_id);
        if (!$targets) {
            return new \WP_Error('cdair_missing', __('The image file was not found on disk.', 'cubedigital-image-replacer'));
        }

        $backup = self::ensure_backup($attachment_id, $targets);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $snapshot = self::snapshot($attachment_id, $targets);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        foreach ($targets as $path) {
            $written = self::write_processed($binary, $path, $saturation);
            if (is_wp_error($written)) {
                self::restore_snapshot($snapshot);
                return $written;
            }
        }

        self::delete_snapshot($snapshot);
        $regenerated = self::regenerate($attachment_id);
        update_post_meta($attachment_id, '_cdair_has_original', 1);
        update_post_meta($attachment_id, '_cdair_replaced_at', time());
        $count = (int) get_post_meta($attachment_id, '_cdair_replace_count', true);
        update_post_meta($attachment_id, '_cdair_replace_count', $count + 1);
        clean_post_cache($attachment_id);
        do_action('cdair_image_replaced', $attachment_id);

        return ['regenerated' => $regenerated === true];
    }

    public static function restore($attachment_id) {
        $backup = self::path_from_meta($attachment_id, '_cdair_backup_attached');
        $attached = get_attached_file($attachment_id);
        if ($backup === '' || !is_file($backup) || !$attached) {
            return new \WP_Error('cdair_no_backup', __('No original backup was found for this image.', 'cubedigital-image-replacer'));
        }
        if (!copy($backup, $attached)) {
            return new \WP_Error('cdair_restore', __('Could not restore the original file.', 'cubedigital-image-replacer'));
        }

        $full_backup = self::path_from_meta($attachment_id, '_cdair_backup_full');
        $full = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($attachment_id) : '';
        if ($full_backup !== '' && is_file($full_backup) && $full && wp_normalize_path($full) !== wp_normalize_path($attached)) {
            copy($full_backup, $full);
        }

        self::regenerate($attachment_id);
        update_post_meta($attachment_id, '_cdair_replaced_at', time());
        clean_post_cache($attachment_id);
        do_action('cdair_image_restored', $attachment_id);
        return true;
    }

    public static function render($binary, $width, $height, $mime, $saturation) {
        wp_raise_memory_limit('image');
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = wp_tempnam('cdair');
        if (!$tmp) {
            return new \WP_Error('cdair_temp', __('Could not create a temporary image file.', 'cubedigital-image-replacer'));
        }

        if (class_exists('Imagick')) {
            $result = self::render_imagick($binary, $width, $height, $mime, $saturation, $tmp);
            if (!is_wp_error($result) && @getimagesize($tmp)) {
                return $tmp;
            }
        }

        $result = self::render_gd($binary, $width, $height, $mime, $saturation, $tmp);
        if (is_wp_error($result) || !@getimagesize($tmp)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }
            return is_wp_error($result) ? $result : new \WP_Error('cdair_render', __('The new image could not be saved in the original format, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        return $tmp;
    }

    private static function live_targets($attachment_id) {
        $targets = [];
        $attached = get_attached_file($attachment_id);
        if ($attached && is_file($attached)) {
            $targets[] = $attached;
        }
        if (function_exists('wp_get_original_image_path')) {
            $full = wp_get_original_image_path($attachment_id);
            if ($full && is_file($full) && wp_normalize_path($full) !== wp_normalize_path((string) $attached)) {
                $targets[] = $full;
            }
        }
        return $targets;
    }

    private static function ensure_backup($attachment_id, $targets) {
        $existing = self::path_from_meta($attachment_id, '_cdair_backup_attached');
        if ($existing !== '') {
            if (!is_file($existing)) {
                return new \WP_Error('cdair_backup_missing', __('The original backup is missing, so this image was not changed.', 'cubedigital-image-replacer'));
            }
            return true;
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new \WP_Error('cdair_uploads', $uploads['error']);
        }

        $folder = 'cdair-backups/' . $attachment_id . '-' . wp_generate_password(8, false, false);
        $dir = trailingslashit($uploads['basedir']) . $folder;
        if (!wp_mkdir_p($dir)) {
            return new \WP_Error('cdair_backup', __('Could not create the original backup, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        self::protect(trailingslashit($uploads['basedir']) . 'cdair-backups');
        self::protect($dir);

        $attached = get_attached_file($attachment_id);
        $saved = [];
        foreach ($targets as $path) {
            $key = ($attached && wp_normalize_path($path) === wp_normalize_path($attached)) ? 'attached' : 'full';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $dest = $dir . '/' . $key . '.' . $ext;
            if (!copy($path, $dest)) {
                self::rmdir($dir);
                return new \WP_Error('cdair_backup', __('Could not copy the original, so nothing was changed.', 'cubedigital-image-replacer'));
            }
            $saved[$key] = self::relative($dest);
        }
        foreach ($saved as $key => $rel) {
            update_post_meta($attachment_id, '_cdair_backup_' . $key, $rel);
        }
        return true;
    }

    private static function protect($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\nexit;\n");
        }
        $web = $dir . '/web.config';
        if (!file_exists($web)) {
            file_put_contents($web, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <remove users=\"*\" roles=\"\" verbs=\"\" />\n      <add accessType=\"Deny\" users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n");
        }
    }

    private static function snapshot($attachment_id, $targets) {
        $dir = get_temp_dir() . 'cdair-' . $attachment_id . '-' . wp_generate_password(6, false, false);
        if (!wp_mkdir_p($dir)) {
            return new \WP_Error('cdair_temp', __('Could not create a temporary copy, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        $map = [];
        foreach ($targets as $index => $path) {
            $dest = $dir . '/' . $index . '.' . pathinfo($path, PATHINFO_EXTENSION);
            if (!copy($path, $dest)) {
                self::rmdir($dir);
                return new \WP_Error('cdair_temp', __('Could not create a temporary copy, so nothing was changed.', 'cubedigital-image-replacer'));
            }
            $map[$path] = $dest;
        }
        return $map;
    }

    private static function restore_snapshot($map) {
        foreach ($map as $dest => $snap) {
            if (is_file($snap)) {
                copy($snap, $dest);
            }
        }
        self::delete_snapshot($map);
    }

    private static function delete_snapshot($map) {
        if (!is_array($map) || !$map) {
            return;
        }
        $dir = dirname((string) reset($map));
        self::rmdir($dir);
    }

    private static function write_processed($binary, $dest, $saturation) {
        $info = @getimagesize($dest);
        if (!$info || empty($info['mime'])) {
            return new \WP_Error('cdair_dest', __('Could not read the current image, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        $tmp = self::render($binary, (int) $info[0], (int) $info[1], $info['mime'], $saturation);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $ok = copy($tmp, $dest);
        unlink($tmp);
        clearstatcache(true, $dest);
        if (!$ok || !@getimagesize($dest)) {
            return new \WP_Error('cdair_write', __('Could not overwrite the image file, so the previous file was kept.', 'cubedigital-image-replacer'));
        }
        return true;
    }

    private static function regenerate($attachment_id) {
        self::delete_subsizes($attachment_id);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return false;
        }
        add_filter('big_image_size_threshold', '__return_false', 99);
        $meta = wp_generate_attachment_metadata($attachment_id, $file);
        remove_filter('big_image_size_threshold', '__return_false', 99);
        if (!is_array($meta) || empty($meta['width'])) {
            return false;
        }
        wp_update_attachment_metadata($attachment_id, $meta);
        return true;
    }

    private static function delete_subsizes($attachment_id) {
        $meta = wp_get_attachment_metadata($attachment_id);
        $file = get_attached_file($attachment_id);
        if (!$file || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return;
        }
        $dir = dirname($file);
        foreach ($meta['sizes'] as $size) {
            if (!is_array($size)) {
                continue;
            }
            self::delete_named_file($dir, isset($size['file']) ? $size['file'] : '');
            if (empty($size['sources']) || !is_array($size['sources'])) {
                continue;
            }
            foreach ($size['sources'] as $source) {
                if (is_array($source)) {
                    self::delete_named_file($dir, isset($source['file']) ? $source['file'] : '');
                }
            }
        }
    }

    private static function delete_named_file($dir, $name) {
        $name = wp_basename((string) $name);
        if ($name === '') {
            return;
        }
        $path = $dir . '/' . $name;
        if (is_file($path)) {
            wp_delete_file($path);
        }
    }

    private static function render_imagick($binary, $width, $height, $mime, $saturation, $dest) {
        try {
            $img = new \Imagick();
            $img->readImageBlob($binary);
            $img->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);
            if ((int) $saturation !== 100) {
                $img->modulateImage(100, (float) $saturation, 100);
            }
            if ($mime === 'image/jpeg') {
                $img->setImageBackgroundColor('white');
                $flat = $img->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $img->clear();
                $img = $flat;
                $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality(90);
                $img->stripImage();
            }
            $img->setImageFormat(self::imagick_format($mime));
            $ok = $img->writeImage($dest);
            $img->clear();
            if (!$ok) {
                return new \WP_Error('cdair_render', __('The new image could not be saved in the original format, so nothing was changed.', 'cubedigital-image-replacer'));
            }
            return true;
        } catch (\Exception $e) {
            return new \WP_Error('cdair_render', __('The new image could not be processed.', 'cubedigital-image-replacer'));
        }
    }

    private static function render_gd($binary, $width, $height, $mime, $saturation, $dest) {
        $src = @imagecreatefromstring($binary);
        if (!$src) {
            return new \WP_Error('cdair_render', __('The generated image could not be read.', 'cubedigital-image-replacer'));
        }
        $dst = imagecreatetruecolor($width, $height);
        if (!$dst) {
            imagedestroy($src);
            return new \WP_Error('cdair_render', __('The new image could not be processed.', 'cubedigital-image-replacer'));
        }
        if ($mime !== 'image/jpeg') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $clear = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $width, $height, $clear);
            imagealphablending($dst, true);
        } else {
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $width, $height, $white);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, imagesx($src), imagesy($src));
        imagedestroy($src);
        if ((int) $saturation !== 100) {
            self::saturate_gd($dst, (int) $saturation);
        }
        $saved = self::save_gd($dst, $dest, $mime);
        imagedestroy($dst);
        if (!$saved) {
            return new \WP_Error('cdair_render', __('The new image could not be saved in the original format, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        return true;
    }

    private static function saturate_gd($im, $saturation) {
        $factor = $saturation / 100;
        $width = imagesx($im);
        $height = imagesy($im);
        imagealphablending($im, false);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $gray = (int) round((0.299 * $r) + (0.587 * $g) + (0.114 * $b));
                $r = self::clamp_byte((int) round($gray + ($factor * ($r - $gray))));
                $g = self::clamp_byte((int) round($gray + ($factor * ($g - $gray))));
                $b = self::clamp_byte((int) round($gray + ($factor * ($b - $gray))));
                imagesetpixel($im, $x, $y, ($a << 24) | ($r << 16) | ($g << 8) | $b);
            }
        }
        imagesavealpha($im, true);
    }

    private static function save_gd($im, $dest, $mime) {
        switch ($mime) {
            case 'image/jpeg':
                return imagejpeg($im, $dest, 90);
            case 'image/png':
                imagealphablending($im, false);
                imagesavealpha($im, true);
                return imagepng($im, $dest, 6);
            case 'image/gif':
                return imagegif($im, $dest);
            case 'image/webp':
                return function_exists('imagewebp') ? imagewebp($im, $dest, 90) : false;
            case 'image/avif':
                return function_exists('imageavif') ? imageavif($im, $dest, 90) : false;
            default:
                return false;
        }
    }

    private static function imagick_format($mime) {
        $formats = [
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];
        return isset($formats[$mime]) ? $formats[$mime] : 'jpeg';
    }

    private static function is_animated_gif($path) {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'gif') {
            return false;
        }
        if (class_exists('Imagick')) {
            try {
                $img = new \Imagick($path);
                $frames = $img->getNumberImages();
                $img->clear();
                return $frames > 1;
            } catch (\Exception $e) {
                return false;
            }
        }
        $data = file_get_contents($path);
        if (!is_string($data)) {
            return false;
        }
        return preg_match_all("/\x00\x21\xF9\x04/", $data) > 1;
    }

    private static function path_from_meta($attachment_id, $key) {
        $rel = get_post_meta($attachment_id, $key, true);
        if (!is_string($rel) || $rel === '' || strpos($rel, '..') !== false) {
            return '';
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (strpos($rel, 'cdair-backups/') !== 0) {
            return '';
        }
        $uploads = wp_upload_dir();
        $base = wp_normalize_path(trailingslashit($uploads['basedir']) . 'cdair-backups');
        $path = wp_normalize_path(trailingslashit($uploads['basedir']) . $rel);
        if (strpos($path, $base . '/') !== 0) {
            return '';
        }
        return $path;
    }

    private static function relative($path) {
        $uploads = wp_upload_dir();
        $base = wp_normalize_path(trailingslashit($uploads['basedir']));
        $path = wp_normalize_path($path);
        return ltrim(substr($path, strlen($base)), '/');
    }

    private static function clamp_byte($value) {
        return max(0, min(255, $value));
    }

    private static function rmdir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
