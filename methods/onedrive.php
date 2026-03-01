<?php
/**
 * SiteGuard Backup - Microsoft OneDrive Storage Method
 *
 * Uses Microsoft Graph API with OAuth 2.0.
 * Upload session API for files >4MB, simple PUT for smaller.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Onedrive extends SiteGuard_Backup_Module {

    private $auth_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    private $token_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    private $graph_url = 'https://graph.microsoft.com/v1.0';

    public function get_default_options() {
        return array(
            'client_id'     => '',
            'client_secret' => '',
            'token'         => array(),
            'folder'        => 'SiteGuard Backups',
        );
    }

    public function get_auth_url() {
        $options = $this->get_options();
        $redirect = admin_url('admin-ajax.php') . '?action=sgbackup_ajax&subaction=onedrive_auth_callback';

        return $this->auth_url . '?' . http_build_query(array(
            'client_id'     => $options['client_id'],
            'redirect_uri'  => $redirect,
            'response_type' => 'code',
            'scope'         => 'Files.ReadWrite.All offline_access',
            'state'         => wp_create_nonce('sgbackup-onedrive-auth'),
        ));
    }

    public function exchange_code($code) {
        $options = $this->get_options();
        $redirect = admin_url('admin-ajax.php') . '?action=sgbackup_ajax&subaction=onedrive_auth_callback';

        $response = wp_remote_post($this->token_url, array(
            'body' => array(
                'client_id'     => $options['client_id'],
                'client_secret' => $options['client_secret'],
                'code'          => $code,
                'redirect_uri'  => $redirect,
                'grant_type'    => 'authorization_code',
            ),
        ));

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['error'])) {
            return new WP_Error('onedrive_auth', $body['error_description'] ?? $body['error']);
        }

        $options['token'] = $body;
        $options['token']['obtained_at'] = time();
        $this->save_options($options);

        return true;
    }

    private function get_access_token() {
        $options = $this->get_options();
        $token = $options['token'];

        if (empty($token['access_token'])) {
            return new WP_Error('onedrive_no_token', 'Not authenticated with OneDrive.');
        }

        $obtained = isset($token['obtained_at']) ? $token['obtained_at'] : 0;
        $expires_in = isset($token['expires_in']) ? $token['expires_in'] : 3600;

        if (time() > ($obtained + $expires_in - 300)) {
            if (empty($token['refresh_token'])) {
                return new WP_Error('onedrive_no_refresh', 'No refresh token. Re-authenticate.');
            }

            $response = wp_remote_post($this->token_url, array(
                'body' => array(
                    'client_id'     => $options['client_id'],
                    'client_secret' => $options['client_secret'],
                    'refresh_token' => $token['refresh_token'],
                    'grant_type'    => 'refresh_token',
                ),
            ));

            if (is_wp_error($response)) return $response;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['error'])) {
                return new WP_Error('onedrive_refresh', $body['error_description'] ?? $body['error']);
            }

            $options['token']['access_token'] = $body['access_token'];
            $options['token']['expires_in'] = $body['expires_in'];
            $options['token']['obtained_at'] = time();
            if (!empty($body['refresh_token'])) {
                $options['token']['refresh_token'] = $body['refresh_token'];
            }
            $this->save_options($options);

            return $body['access_token'];
        }

        return $token['access_token'];
    }

    private function ensure_folder() {
        $options = $this->get_options();
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $folder_name = $options['folder'] ?: 'SiteGuard Backups';

        // Check if folder exists
        $response = wp_remote_get(
            $this->graph_url . '/me/drive/root:/' . rawurlencode($folder_name),
            array('headers' => array('Authorization' => "Bearer {$token}"))
        );

        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return $body['id'] ?? true;
            }
        }

        // Create folder - use 'fail' conflict behavior to avoid silently creating duplicates
        $response = wp_remote_post($this->graph_url . '/me/drive/root/children', array(
            'headers' => array(
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode(array(
                'name'   => $folder_name,
                'folder' => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'fail',
            )),
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // 409 Conflict means the folder already exists - re-fetch its ID
        if ($code === 409) {
            $retry = wp_remote_get(
                $this->graph_url . '/me/drive/root:/' . rawurlencode($folder_name),
                array('headers' => array('Authorization' => "Bearer {$token}"))
            );
            if (!is_wp_error($retry) && wp_remote_retrieve_response_code($retry) === 200) {
                $retry_body = json_decode(wp_remote_retrieve_body($retry), true);
                if (isset($retry_body['id'])) {
                    return $retry_body['id'];
                }
            }
            return new WP_Error('onedrive_folder', 'Folder already exists but could not retrieve its ID.');
        }

        return isset($body['id']) ? $body['id'] : new WP_Error('onedrive_folder', 'Could not create folder.');
    }

    public function backup($file) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $this->ensure_folder();

        $options = $this->get_options();
        $folder_name = $options['folder'] ?: 'SiteGuard Backups';
        $filename = basename($file);
        $filesize = filesize($file);

        $upload_path = '/me/drive/root:/' . rawurlencode($folder_name) . '/' . rawurlencode($filename);

        // Simple PUT for small files (<4MB)
        if ($filesize < 4194304) {
            $response = wp_remote_request($this->graph_url . $upload_path . ':/content', array(
                'method'  => 'PUT',
                'headers' => array(
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/octet-stream',
                ),
                'body'    => file_get_contents($file),
                'timeout' => 120,
            ));

            if (is_wp_error($response)) return $response;
            $code = wp_remote_retrieve_response_code($response);
            return ($code >= 200 && $code < 300) ? true : new WP_Error('onedrive_upload', "Upload failed (HTTP {$code})");
        }

        // Upload session for large files
        $session_response = wp_remote_post($this->graph_url . $upload_path . ':/createUploadSession', array(
            'headers' => array(
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode(array(
                'item' => array(
                    '@microsoft.graph.conflictBehavior' => 'replace',
                    'name' => $filename,
                ),
            )),
        ));

        if (is_wp_error($session_response)) return $session_response;

        $session_body = json_decode(wp_remote_retrieve_body($session_response), true);
        if (empty($session_body['uploadUrl'])) {
            return new WP_Error('onedrive_session', 'Failed to create upload session.');
        }

        $upload_url = $session_body['uploadUrl'];
        $chunk_size = 5242880; // 5MB (must be multiple of 320KB)
        $fh = fopen($file, 'rb');
        $offset = 0;

        while ($offset < $filesize) {
            $chunk = fread($fh, $chunk_size);
            $end = $offset + strlen($chunk) - 1;

            $chunk_response = wp_remote_request($upload_url, array(
                'method'  => 'PUT',
                'headers' => array(
                    'Content-Length' => strlen($chunk),
                    'Content-Range'  => "bytes {$offset}-{$end}/{$filesize}",
                ),
                'body'    => $chunk,
                'timeout' => 120,
            ));

            if (is_wp_error($chunk_response)) {
                fclose($fh);
                return $chunk_response;
            }

            $code = wp_remote_retrieve_response_code($chunk_response);
            if ($code !== 200 && $code !== 201 && $code !== 202) {
                fclose($fh);
                return new WP_Error('onedrive_chunk', "Chunk upload failed (HTTP {$code})");
            }

            $offset += strlen($chunk);
            SiteGuard_Backup_Job_Scheduler::something_useful_happened();
        }

        fclose($fh);
        return true;
    }

    public function download($file, $dest_dir) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $options = $this->get_options();
        $folder_name = $options['folder'] ?: 'SiteGuard Backups';
        $path = '/me/drive/root:/' . rawurlencode($folder_name) . '/' . rawurlencode($file) . ':/content';
        $local_path = trailingslashit($dest_dir) . $file;

        $response = wp_remote_get($this->graph_url . $path, array(
            'headers'  => array('Authorization' => "Bearer {$token}"),
            'timeout'  => 300,
            'stream'   => true,
            'filename' => $local_path,
        ));

        if (is_wp_error($response)) {
            @unlink($local_path);
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            @unlink($local_path);
            return new WP_Error('onedrive_download', "OneDrive download failed (HTTP {$code})");
        }

        return true;
    }

    public function delete($files) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $options = $this->get_options();
        $folder_name = $options['folder'] ?: 'SiteGuard Backups';

        if (!is_array($files)) $files = array($files);
        $errors = array();

        foreach ($files as $file) {
            $path = '/me/drive/root:/' . rawurlencode($folder_name) . '/' . rawurlencode($file);
            $response = wp_remote_request($this->graph_url . $path, array(
                'method'  => 'DELETE',
                'headers' => array('Authorization' => "Bearer {$token}"),
            ));

            if (is_wp_error($response)) {
                $errors[] = $file . ': ' . $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            // OneDrive returns 204 No Content on successful delete
            if ($code >= 400) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $err_msg = isset($body['error']['message']) ? $body['error']['message'] : "HTTP {$code}";
                $errors[] = $file . ': ' . $err_msg;
            }
        }

        if (!empty($errors)) {
            return new WP_Error('onedrive_delete_failed', 'Failed to delete file(s): ' . implode('; ', $errors));
        }

        return true;
    }

    public function listfiles() {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return array();

        $options = $this->get_options();
        $folder_name = $options['folder'] ?: 'SiteGuard Backups';
        $path = '/me/drive/root:/' . rawurlencode($folder_name) . ':/children?$select=name';

        $files = array();
        $url = $this->graph_url . $path;

        while (!empty($url)) {
            $response = wp_remote_get($url, array(
                'headers' => array('Authorization' => "Bearer {$token}"),
            ));

            if (is_wp_error($response)) break;

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['error'])) break;

            if (!empty($body['value'])) {
                foreach ($body['value'] as $item) {
                    if (isset($item['name'])) {
                        $files[] = $item['name'];
                    }
                }
            }

            $url = isset($body['@odata.nextLink']) ? $body['@odata.nextLink'] : '';
        }

        return $files;
    }

    public function credentials_test() {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $response = wp_remote_get($this->graph_url . '/me/drive', array(
            'headers' => array('Authorization' => "Bearer {$token}"),
        ));

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['error'])) {
            return new WP_Error('onedrive_test', $body['error']['message']);
        }

        return true;
    }
}
