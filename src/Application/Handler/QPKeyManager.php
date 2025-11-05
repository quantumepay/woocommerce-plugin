<?php

namespace WooQuantum\Application\Handler;

class QPKeyManager
{

    private $constant_name = 'QP_KMS_KEY';

    public function handle_activation()
    {
        // Check if constant is already defined
        if (defined($this->constant_name)) {
            $this->cleanup_temporary_data();
            return;
        }

        // Generate secure key
        $key = $this->generate_secure_key();
        $key_base64 = base64_encode($key);

        // Try to automatically add to wp-config.php
        $auto_result = $this->auto_add_to_wp_config($key_base64);

        if ($auto_result['success']) {
            // Success! Define constant for current request and cleanup
            define($this->constant_name, $key_base64);
            $this->cleanup_temporary_data();
            update_option('qp_key_auto_installed', true);
        } else {
            // Auto-failed, fallback to manual method
            update_option('qp_generated_key', $key_base64);
            update_option('qp_key_installation_pending', true);
            update_option('qp_auto_install_failed', $auto_result['reason']);
            set_transient('qp_show_key_instructions', true, 3600);
        }
    }

    private function generate_secure_key()
    {
        if (function_exists('sodium_crypto_secretbox_keygen')) {
            return sodium_crypto_secretbox_keygen();
        } else {
            return openssl_random_pseudo_bytes(32);
        }
    }

    private function auto_add_to_wp_config($key)
    {
        $wp_config_path = $this->locate_wp_config();

        if (!$wp_config_path || !is_writable($wp_config_path)) {
            return ['success' => false, 'reason' => 'file_not_writable'];
        }

        // Create backup
        if (!$this->create_backup($wp_config_path)) {
            return ['success' => false, 'reason' => 'backup_failed'];
        }

        // Read current content
        $content = file_get_contents($wp_config_path);
        if ($content === false) {
            return ['success' => false, 'reason' => 'read_failed'];
        }

        // Check if constant already exists
        if (strpos($content, $this->constant_name) !== false) {
            return ['success' => false, 'reason' => 'constant_exists'];
        }

        // Add the constant
        $new_content = $this->insert_constant($content, $key);
        if ($new_content === false) {
            return ['success' => false, 'reason' => 'insert_failed'];
        }

        // Write back to file
        if (file_put_contents($wp_config_path, $new_content, LOCK_EX) === false) {
            // Restore backup on failure
            $this->restore_backup($wp_config_path);
            return ['success' => false, 'reason' => 'write_failed'];
        }

        return ['success' => true];
    }

    private function locate_wp_config()
    {
        $paths = [
            ABSPATH . 'wp-config.php',
            ABSPATH . '../wp-config.php',
            dirname(ABSPATH) . '/wp-config.php'
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }

        return false;
    }

    private function create_backup($file_path)
    {
        $backup_path = $file_path . '.backup.qp.' . date('Y-m-d-His');
        return copy($file_path, $backup_path);
    }

    private function restore_backup($file_path)
    {
        $backup_path = $file_path . '.backup.qp.' . date('Y-m-d-His');
        if (file_exists($backup_path)) {
            return copy($backup_path, $file_path);
        }
        return false;
    }

    private function insert_constant($content, $key)
    {
        $constant_definition = "define( '{$this->constant_name}', '" . $key . "' ); // Added by Quantum ePay plugin";

        // Try to insert after the opening PHP tag
        $pattern = '/<\?php\s*\n/';
        $replacement = "<?php\n{$constant_definition}\n";

        $new_content = preg_replace($pattern, $replacement, $content, 1);

        if ($new_content === null || $new_content === $content) {
            // Fallback: insert before "That's all, stop editing!"
            $pattern = '/(\s*\/\*\*.*?stop editing.*?\*\/)/';
            $replacement = "{$constant_definition}\n\n$1";
            $new_content = preg_replace($pattern, $replacement, $content, 1);
        }

        return $new_content !== $content ? $new_content : false;
    }

    private function cleanup_temporary_data()
    {
        delete_option('qp_generated_key');
        delete_option('qp_key_installation_pending');
        delete_option('qp_auto_install_failed');
        delete_transient('qp_show_key_instructions');
    }

    /**
     * Manual installation method for when auto-install fails
     */
    public function get_manual_installation_instructions()
    {
        $key = get_option('qp_generated_key');
        if (!$key) {
            return false;
        }

        return [
            'key' => $key,
            'instruction' => "Add the following line to your wp-config.php file:\n\ndefine( '{$this->constant_name}', '{$key}' );"
        ];
    }
}
