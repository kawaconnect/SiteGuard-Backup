<?php
/**
 * SiteGuard Backup - WebDAV Storage Method
 *
 * HTTP PUT/GET/DELETE/PROPFIND via wp_remote_request() with Basic auth.
 * Uploads over 10MB are streamed from disk via cURL to avoid memory exhaustion.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Webdav extends SiteGuard_Backup_Module {

    public function get_default_options() {
        return array(
            'url'  => '',
            'user' => '',
            'pass' => '',
            'path' => '/',
        );
    }

    private function get_base_url() {
        $options = $this->get_options();
        $url = rtrim($options['url'], '/');
        $this->cleartext_warning($url, 'WebDAV');
        $path = trim($options['path'], '/');
        if (!empty($path)) {
            $url .= '/' . $path;
        }
        return $url;
    }

    private function get_auth_header() {
        $options = $this->get_options();
        return 'Basic ' . base64_encode($options['user'] . ':' . $options['pass']);
    }

    /**
     * Encode a request path for use in URLs, preserving forward slashes.
     *
     * @param string $path Remote path.
     * @return string
     */
    private function encode_path($path) {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function request($method, $remote_path, $body = null, $extra_headers = array()) {
        $options = $this->get_options();
        if (empty($options['url'])) {
            return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
        }

        $url = $this->get_base_url() . '/' . $this->encode_path(ltrim($remote_path, '/'));

        $headers = array_merge(array(
            'Authorization' => $this->get_auth_header(),
        ), $extra_headers);

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 300,
        );

        if ($body !== null) {
            $args['body'] = $body;
        }

        return wp_remote_request($url, $args);
    }

    public function backup($file) {
        $filename = basename($file);
        $filesize = filesize($file);

        // Ensure remote directory exists
        $this->request('MKCOL', '', null, array('Content-Type' => 'application/xml'));

        // For files over 10MB, stream the upload directly from disk via cURL.
        // WebDAV standard does not support Content-Range on PUT (unlike HTTP/1.1 PATCH),
        // and wp_remote_request() cannot stream a request body from a file handle.
        if ($filesize > 10485760) {
            return $this->stream_upload($file, $filename, $filesize);
        }

        // Simple PUT for smaller files (<10MB)
        $body = file_get_contents($file);
        $response = $this->request('PUT', $filename, $body, array(
            'Content-Type' => 'application/octet-stream',
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return true;

        return new WP_Error('webdav_upload', "WebDAV upload failed (HTTP {$code})");
    }

    /**
     * Stream a large file to the WebDAV server via cURL PUT.
     *
     * Reads directly from the file handle (CURLOPT_UPLOAD + CURLOPT_INFILE)
     * so the archive is never loaded into memory.
     *
     * @param string $file     Full path to the local file.
     * @param string $filename Remote filename.
     * @param int    $filesize File size in bytes.
     * @return bool|WP_Error
     */
    private function stream_upload($file, $filename, $filesize) {
        if (!function_exists('curl_init')) {
            return new WP_Error(
                'webdav_no_curl',
                'Uploading files larger than 10MB to WebDAV requires the PHP cURL extension. Please enable cURL or use a different storage method.'
            );
        }

        $options = $this->get_options();
        if (empty($options['url'])) {
            return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
        }

        $fh = fopen($file, 'rb');
        if (!$fh) {
            return new WP_Error('webdav_read', 'Failed to open file for reading.');
        }

        $url = $this->get_base_url() . '/' . $this->encode_path(ltrim($filename, '/'));

        $ch = curl_init($url);
        if (false === $ch) {
            fclose($fh);
            return new WP_Error('webdav_curl_init', 'Failed to initialize cURL for WebDAV upload.');
        }

        // CURLOPT_UPLOAD performs an HTTP PUT streamed from CURLOPT_INFILE.
        // TLS peer/host verification is left at cURL defaults (enabled).
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $filesize);
        curl_setopt($ch, CURLOPT_USERPWD, $options['user'] . ':' . $options['pass']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/octet-stream'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        if (false === $result) {
            return new WP_Error('webdav_upload', 'WebDAV streaming upload failed: ' . $curl_error);
        }

        if ($code >= 200 && $code < 300) return true;

        return new WP_Error('webdav_upload', "WebDAV upload failed (HTTP {$code})");
    }

    public function download($file, $dest_dir) {
        $local_path = trailingslashit($dest_dir) . basename($file);

        $options = $this->get_options();
        if (empty($options['url'])) {
            return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
        }

        $url = $this->get_base_url() . '/' . $this->encode_path(ltrim($file, '/'));

        // Use streaming download to avoid loading entire file into memory
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header(),
            ),
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $local_path,
        ));

        if (is_wp_error($response)) {
            @unlink($local_path); // a transport error mid-stream leaves a truncated file
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            @unlink($local_path);
            return new WP_Error('webdav_download', "WebDAV download failed (HTTP {$code})");
        }

        return true;
    }

    public function delete($files) {
        $files = $this->safe_remote_names($files);
        if (!$files) return true;
        if (!is_array($files)) $files = array($files);
        $errors = array();

        foreach ($files as $file) {
            $response = $this->request('DELETE', $file);
            if (is_wp_error($response)) {
                $errors[] = $file . ': ' . $response->get_error_message();
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 400 && $code !== 404) {
                    $errors[] = $file . ": HTTP {$code}";
                }
            }
        }

        if (!empty($errors)) {
            return new WP_Error('webdav_delete', 'Failed to delete: ' . implode('; ', $errors));
        }

        return true;
    }

    public function listfiles() {
        $response = $this->request('PROPFIND', '', null, array(
            'Content-Type' => 'application/xml',
            'Depth'        => '1',
        ));

        if (is_wp_error($response)) return array();

        $body = wp_remote_retrieve_body($response);
        $files = array();

        // Parse PROPFIND XML response
        if (preg_match_all('/<(?:[a-zA-Z0-9]+:)?displayname>([^<]+)<\/(?:[a-zA-Z0-9]+:)?displayname>/i', $body, $matches)) {
            foreach ($matches[1] as $name) {
                if (preg_match('/^backup_/', $name)) {
                    $files[] = $name;
                }
            }
        }

        return $files;
    }

    public function credentials_test() {
        $response = $this->request('PROPFIND', '', null, array(
            'Content-Type' => 'application/xml',
            'Depth'        => '0',
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 207 || $code === 200) {
            $warn = $this->cleartext_warning($this->get_options()['url'], 'WebDAV');
            return '' === $warn ? true : array('success' => true, 'message' => 'Connected. Warning: ' . $warn);
        }
        if ($code === 401) return new WP_Error('webdav_auth', 'Authentication failed.');

        return new WP_Error('webdav_test', "WebDAV test failed (HTTP {$code})");
    }
}
