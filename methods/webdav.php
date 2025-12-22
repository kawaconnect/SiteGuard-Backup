<?php
/**
 * SiteGuard Backup - WebDAV Storage Method
 *
 * HTTP PUT/GET/DELETE/PROPFIND via wp_remote_request() with Basic auth.
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

    private function request($method, $remote_path, $body = null, $extra_headers = array()) {
        $options = $this->get_options();
        if (empty($options['url'])) {
            return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
        }

        $url = $this->get_base_url() . '/' . ltrim($remote_path, '/');

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

        // For large files (>50MB), use single PUT with streaming body to avoid memory issues.
        // WebDAV standard does not support Content-Range on PUT (unlike HTTP/1.1 PATCH).
        if ($filesize > 52428800) {
            $fh = fopen($file, 'r');
            if (!$fh) {
                return new WP_Error('webdav_read', 'Failed to open file for reading.');
            }

            $options = $this->get_options();
            if (empty($options['url'])) {
                fclose($fh);
                return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
            }

            $url = $this->get_base_url() . '/' . ltrim($filename, '/');

            $response = wp_remote_request($url, array(
                'method'  => 'PUT',
                'headers' => array(
                    'Authorization' => $this->get_auth_header(),
                    'Content-Type'  => 'application/octet-stream',
                    'Content-Length' => $filesize,
                ),
                'body'    => $fh,
                'timeout' => 600,
            ));

            fclose($fh);

            if (is_wp_error($response)) return $response;

            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) return true;

            return new WP_Error('webdav_upload', "WebDAV upload failed (HTTP {$code})");
        }

        // Simple PUT for smaller files
        $body = file_get_contents($file);
        $response = $this->request('PUT', $filename, $body, array(
            'Content-Type' => 'application/octet-stream',
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) return true;

        return new WP_Error('webdav_upload', "WebDAV upload failed (HTTP {$code})");
    }

    public function download($file, $dest_dir) {
        $local_path = trailingslashit($dest_dir) . basename($file);

        $options = $this->get_options();
        if (empty($options['url'])) {
            return new WP_Error('webdav_no_url', 'WebDAV URL not configured.');
        }

        $url = $this->get_base_url() . '/' . ltrim($file, '/');

        // Use streaming download to avoid loading entire file into memory
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $this->get_auth_header(),
            ),
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $local_path,
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            @unlink($local_path);
            return new WP_Error('webdav_download', "WebDAV download failed (HTTP {$code})");
        }

        return true;
    }

    public function delete($files) {
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
        if (preg_match_all('/<d:displayname>([^<]+)<\/d:displayname>/i', $body, $matches)) {
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
        if ($code === 207 || $code === 200) return true;
        if ($code === 401) return new WP_Error('webdav_auth', 'Authentication failed.');

        return new WP_Error('webdav_test', "WebDAV test failed (HTTP {$code})");
    }
}
