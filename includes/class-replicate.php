<?php

namespace CubeDigital\ImageReplacer;

if (!defined('ABSPATH')) {
    exit;
}

class Replicate {
    const SDXL_VERSION = '7762fd07cf82c948538e41f63f77d685e02b063e37e496e96eefd46c929f9bdc';

    public static function create($attachment_id) {
        $token = Settings::token();
        if ($token === '') {
            return new \WP_Error('cdair_no_token', __('Add a Replicate API token under Settings → AI Image Replacer.', 'cubedigital-image-replacer'));
        }

        $source = Replacer::source_file($attachment_id);
        if ($source === '' || !is_file($source)) {
            return new \WP_Error('cdair_source', __('The source image could not be read.', 'cubedigital-image-replacer'));
        }

        $info = @getimagesize($source);
        if (!$info) {
            return new \WP_Error('cdair_source', __('The source image could not be read.', 'cubedigital-image-replacer'));
        }

        $size = self::api_size((int) $info[0], (int) $info[1]);
        $binary = file_get_contents($source);
        if (!is_string($binary) || $binary === '') {
            return new \WP_Error('cdair_source', __('The source image could not be read.', 'cubedigital-image-replacer'));
        }

        $jpeg = Replacer::render($binary, $size[0], $size[1], 'image/jpeg', 100);
        if (is_wp_error($jpeg)) {
            return $jpeg;
        }

        $encoded = file_get_contents($jpeg);
        unlink($jpeg);
        if (!is_string($encoded) || $encoded === '') {
            return new \WP_Error('cdair_source', __('The source image could not be prepared for Replicate.', 'cubedigital-image-replacer'));
        }

        $saved = @getimagesizefromstring($encoded);
        $width = $saved ? (int) $saved[0] : $size[0];
        $height = $saved ? (int) $saved[1] : $size[1];
        $width = max(64, (int) (round($width / 8) * 8));
        $height = max(64, (int) (round($height / 8) * 8));

        $input = self::input($encoded, $width, $height, $attachment_id);
        return self::request_prediction(Settings::model(), $input, $token);
    }

    private static function request_prediction($model, $input, $token) {
        $last = null;
        foreach (self::prediction_attempts($model, $input) as $attempt) {
            $response = self::post_prediction($attempt, $token);
            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            if ($code === 429) {
                sleep(self::retry_after($response, $decoded));
                $response = self::post_prediction($attempt, $token);
                if (is_wp_error($response)) {
                    return $response;
                }
                $code = (int) wp_remote_retrieve_response_code($response);
                $decoded = json_decode(wp_remote_retrieve_body($response), true);
            }
            if ($code >= 200 && $code < 300 && is_array($decoded) && !empty($decoded['urls']['get'])) {
                return $decoded;
            }
            $last = new \WP_Error('cdair_replicate', self::error_message($decoded, $code));
            if ($code !== 404) {
                return $last;
            }
        }
        return $last instanceof \WP_Error ? $last : new \WP_Error('cdair_replicate', __('Replicate did not start a prediction.', 'cubedigital-image-replacer'));
    }

    private static function prediction_attempts($model, $input) {
        $attempts = [];
        if (preg_match('/^[a-f0-9]{64}$/', $model)) {
            $attempts[] = [
                'url' => 'https://api.replicate.com/v1/predictions',
                'body' => [
                    'version' => $model,
                    'input' => $input,
                ],
            ];
            return $attempts;
        }
        $parts = explode('/', $model);
        if (self::is_sdxl($model)) {
            $attempts[] = [
                'url' => 'https://api.replicate.com/v1/predictions',
                'body' => [
                    'version' => self::SDXL_VERSION,
                    'input' => $input,
                ],
            ];
            return $attempts;
        }
        if (count($parts) === 2) {
            $attempts[] = [
                'url' => 'https://api.replicate.com/v1/models/' . rawurlencode($parts[0]) . '/' . rawurlencode($parts[1]) . '/predictions',
                'body' => ['input' => $input],
            ];
        }
        return $attempts;
    }

    public static function fetch($get_url) {
        $token = Settings::token();
        if ($token === '') {
            return new \WP_Error('cdair_no_token', __('Add a Replicate API token under Settings → AI Image Replacer.', 'cubedigital-image-replacer'));
        }
        if (!self::allowed_api_url($get_url)) {
            return new \WP_Error('cdair_replicate', __('The Replicate status URL was not recognised.', 'cubedigital-image-replacer'));
        }

        $response = wp_remote_get($get_url, [
            'headers' => self::headers($token),
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($decoded)) {
            return new \WP_Error('cdair_replicate', self::error_message($decoded, $code));
        }
        return $decoded;
    }

    public static function download($url) {
        if (!self::allowed_output_url($url)) {
            return new \WP_Error('cdair_download', __('The generated file URL was not recognised, so nothing was changed.', 'cubedigital-image-replacer'));
        }

        $response = wp_remote_get($url, ['timeout' => 60]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || !is_string($body) || strlen($body) < 32 || strlen($body) > 30 * 1024 * 1024) {
            return new \WP_Error('cdair_download', __('The generated file could not be downloaded, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        if (!@getimagesizefromstring($body)) {
            return new \WP_Error('cdair_download', __('Replicate did not return a valid image, so nothing was changed.', 'cubedigital-image-replacer'));
        }
        return $body;
    }

    public static function output_url($prediction) {
        if (!is_array($prediction) || empty($prediction['output'])) {
            return '';
        }
        $output = $prediction['output'];
        if (is_string($output)) {
            return $output;
        }
        if (!is_array($output) || !isset($output[0])) {
            return '';
        }
        if (is_string($output[0])) {
            return $output[0];
        }
        if (is_array($output[0]) && !empty($output[0]['url'])) {
            return (string) $output[0]['url'];
        }
        return '';
    }

    public static function test() {
        $token = Settings::token();
        if ($token === '') {
            return new \WP_Error('cdair_no_token', __('Save an API token first.', 'cubedigital-image-replacer'));
        }
        $response = wp_remote_get('https://api.replicate.com/v1/account', [
            'headers' => self::headers($token),
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 401 || $code === 403) {
            return new \WP_Error('cdair_token', __('Replicate rejected that token.', 'cubedigital-image-replacer'));
        }
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('cdair_token', self::error_message($decoded, $code));
        }
        $name = is_array($decoded) && !empty($decoded['username']) ? sanitize_text_field($decoded['username']) : '';
        if ($name !== '') {
            return sprintf(__('Connected as %s.', 'cubedigital-image-replacer'), $name);
        }
        return __('Connected.', 'cubedigital-image-replacer');
    }

    private static function input($jpeg, $width, $height, $attachment_id) {
        $input = [
            'image' => 'data:image/jpeg;base64,' . base64_encode($jpeg),
            'prompt' => Settings::prompt(),
            'negative_prompt' => Settings::negative_prompt(),
            'prompt_strength' => Settings::strength(),
        ];
        if (self::is_sdxl(Settings::model())) {
            $input['width'] = $width;
            $input['height'] = $height;
            $input['num_outputs'] = 1;
            $input['num_inference_steps'] = 30;
            $input['guidance_scale'] = 7.5;
            $input['refine'] = 'no_refiner';
            $input['apply_watermark'] = false;
        }
        return apply_filters('cdair_replicate_input', $input, $attachment_id);
    }

    private static function is_sdxl($model) {
        return $model === 'stability-ai/sdxl'
            || $model === self::SDXL_VERSION
            || $model === '39ed52f2a78e934b3ba6e2a89f5b1c712de7dfea535525255b1aa35c5565e08b';
    }

    private static function api_size($width, $height) {
        $width = max(1, (int) $width);
        $height = max(1, (int) $height);
        $scale = min(1024 / $width, 1024 / $height, 1);
        $w = (int) (round(($width * $scale) / 8) * 8);
        $h = (int) (round(($height * $scale) / 8) * 8);
        return [max(64, min(1024, $w)), max(64, min(1024, $h))];
    }

    private static function post_prediction($attempt, $token) {
        return wp_remote_post($attempt['url'], [
            'headers' => self::headers($token),
            'body' => wp_json_encode($attempt['body']),
            'timeout' => 60,
        ]);
    }

    private static function retry_after($response, $body) {
        $header = (int) wp_remote_retrieve_header($response, 'retry-after');
        if ($header > 0) {
            return min(20, $header);
        }
        $detail = is_array($body) && !empty($body['detail']) ? (string) $body['detail'] : '';
        if (preg_match('/(\d+)\s*s/', $detail, $matches)) {
            return min(20, max(1, (int) $matches[1] + 1));
        }
        return 9;
    }

    private static function headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private static function error_message($body, $code) {
        $message = '';
        if (is_array($body)) {
            if (!empty($body['detail'])) {
                $message = is_string($body['detail']) ? $body['detail'] : wp_json_encode($body['detail']);
            } elseif (!empty($body['error'])) {
                $message = is_string($body['error']) ? $body['error'] : wp_json_encode($body['error']);
            } elseif (!empty($body['title'])) {
                $message = (string) $body['title'];
            }
        }
        if ($message === '') {
            $message = sprintf(__('Replicate returned HTTP %d.', 'cubedigital-image-replacer'), (int) $code);
        }
        $message = preg_replace('/data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+\/=]+/', '[image]', (string) $message);
        if (!is_string($message) || $message === '') {
            $message = __('Replicate returned an error.', 'cubedigital-image-replacer');
        }
        return $message . ' (HTTP ' . (int) $code . ')';
    }

    private static function allowed_output_url($url) {
        $host = self::https_host($url);
        if ($host === '') {
            return false;
        }
        return (bool) preg_match('/(^|\.)replicate\.(delivery|com)$/', $host);
    }

    private static function allowed_api_url($url) {
        $host = self::https_host($url);
        return $host === 'api.replicate.com';
    }

    private static function https_host($url) {
        $parts = wp_parse_url((string) $url);
        if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https' || empty($parts['host'])) {
            return '';
        }
        return strtolower($parts['host']);
    }
}
