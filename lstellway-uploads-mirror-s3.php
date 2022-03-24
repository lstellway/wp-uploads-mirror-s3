<?php

/*
Plugin Name: Mirror uploads to S3
Plugin URI: https://github.com/lstellway/wp-uploads-mirror-s3
Description: Mirror uploads to S3 bucket
Version: 0.1.0
Author: Logan Stellway
Author URI: https://github.com/lstellway/
*/

namespace LStellway\Uploads;

if (!defined('ABSPATH') || !function_exists('is_blog_installed()') || !is_blog_installed()) {
    return;
}

class MirrorS3
{
    /**
     * @var \Aws\S3\S3Client
     */
    private $s3;

    /**
     * @var array{path: string, basedir: string, baseurl: string, url: string}
     */
    private $uploadDir;

    /**
     * @var array
     */
    private $bucketAndKeyPath;

    /**
     * Initialize
     */
    public function __construct()
    {
        add_filter('upload_dir', [$this, 'filter_upload_dir']);
        add_filter('wp_generate_attachment_metadata', [$this, 'filter_wp_generate_attachment_metadata'], 10, 3);
        add_filter('wp_delete_file', [$this, 'filter_wp_delete_file']);
    }

    /**
     * Temporary logger
     */
    protected function log(string $message, $error = false)
    {
        $file = $error ? 'php://stderr' : 'php://stdout';
        file_put_contents($file, $message . "\n", FILE_APPEND);
    }

    /**
     * Get WordPress upload directory paths array
     * 
     * @return array{path: string, basedir: string, baseurl: string, url: string}
     */
    protected function get_wp_upload_dir(): array
    {
        if (!$this->uploadDir) {
            $this->uploadDir = wp_upload_dir(null, false);
        }

        return $this->uploadDir;
    }

    /**
     * Get S3 bucket and key path
     * 
     * @return array
     */
    protected function get_s3_bucket_and_key_path(): array
    {
        if (!$this->bucketAndKeyPath) {
            // Get configured bucket path
            $config_path = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : '';

            // Split path by path separator (`/`)
            $bucket_path = explode('/', $config_path);

            // Remote first value from the returned array
            $bucket = array_shift($bucket_path);

            $this->bucketAndKeyPath = [$bucket, implode('/', $bucket_path)];
        }

        return $this->bucketAndKeyPath;
    }

    /**
     * Overwrite the default wp_upload_dir.
     *
     * @param array{path: string, basedir: string, baseurl: string, url: string} $dirs
     * @return array{path: string, basedir: string, baseurl: string, url: string}
     */
    public function filter_upload_dir(array $dirs)
    {
        // Get configured uploads URL
        $url = defined('S3_UPLOADS_BUCKET_URL') ? S3_UPLOADS_BUCKET_URL : null;

        if ($url && isset($dirs['url']) && isset($dirs['baseurl'])) {
            // Replace the old URL's
            $old = $dirs['baseurl'];
            $dirs['url'] = str_ireplace($old, $url, $dirs['url']);
            $dirs['baseurl'] = $url;
        }

        return $dirs;
    }

    /**
     * Check if the app is configured to interface with S3
     */
    private function canInterfaceWithS3()
    {
        // Exit early if AWS SDK not installed
        if (!class_exists('Aws\S3\S3Client')) {
            return false;
        }

        // Check required variables
        $required_variables = ['S3_UPLOADS_BUCKET'];
        foreach ($required_variables as $variable) {
            if (!defined($variable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get S3 client
     * 
     * @return \Aws\S3\S3Client
     */
    private function getS3Client(): \Aws\S3\S3Client
    {
        if (!$this->s3) {
            // Initialize parameters array
            $params = [
                'version' => 'latest',
                'region'  => defined('S3_UPLOADS_REGION') ? S3_UPLOADS_REGION : 'us-west-1',
                'signature' => 'v4',
            ];

            // Add credentials
            if (defined('S3_UPLOADS_KEY') && defined('S3_UPLOADS_SECRET')) {
                $params['credentials']['key'] = S3_UPLOADS_KEY;
                $params['credentials']['secret'] = S3_UPLOADS_SECRET;
            }

            // Use path style
            if (defined('S3_UPLOADS_ENDPOINT')) {
                $params['endpoint'] = S3_UPLOADS_ENDPOINT;
                $params['use_path_style_endpoint'] = true;
            }

            $params = apply_filters('s3_uploads_s3_client_params', $params);
            $this->s3 = new \Aws\S3\S3Client($params);
        }

        return $this->s3;
    }

    /**
     * When file is uploaded
     */
    public function filter_wp_generate_attachment_metadata(array $metadata, int $attachment_id, string $context)
    {
        // Exit early if AWS SDK not installed
        if (!$this->canInterfaceWithS3()) {
            return $metadata;
        }

        // Get WordPress uploads directory data array
        $upload_dir = $this->get_wp_upload_dir();

        // Return early if missing required data not met
        if (!isset($metadata['file']) || !isset($upload_dir['basedir'])) {
            return $metadata;
        }

        // Build
        //   (1) absolute path on local filesystem and 
        //   (2) relative bucket path for S3
        list($bucket, $key_path) = $this->get_s3_bucket_and_key_path();
        $uploads_subpath = ltrim(dirname($metadata['file']), '/');
        $absolute_dir = path_join($upload_dir['basedir'], $uploads_subpath);
        $bucket_path = path_join($key_path, $uploads_subpath);

        // Initialize files array
        $files = [path_join(
            $upload_dir['basedir'],
            ltrim($metadata['file'], '/')
        )];

        // Add resized files
        if (isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $meta) {
                $file_path = ltrim($meta['file'], '/');
                $files[] = path_join($absolute_dir, $file_path);
            }
        }

        try {
            // Get S3 client instance
            $s3 = $this->getS3Client();

            $acl = defined('S3_UPLOADS_OBJECT_ACL') ? S3_UPLOADS_OBJECT_ACL : 'public-read';

            // Buld Build array of commands to upload files to S3 in bulk
            $commands = [];
            foreach ($files as $file_path) {
                $file_name = basename($file_path);
                $commands[] = $s3->getCommand('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => path_join($bucket_path, $file_name),
                    'Body' => fopen($file_path, 'r'),
                    'ACL' => $acl,
                ]);
            }

            if (!empty($commands)) {
                $pool = new \Aws\CommandPool($s3, $commands);
                $promise = $pool->promise();
                $promise->wait();
            }
        } catch (\Exception $e) {
            $this->log(json_encode([
                'error' => $e->getMessage(),
                'message' => "Could not upload files to S3 via command pool",
                'files' => $files,
            ]), true);
        }

        return $metadata;
    }

    /**
     * Delete remote attachment files related to a post being deleted
     */
    public function filter_wp_delete_file($file)
    {
        // Exit early if AWS SDK not installed
        if (!$this->canInterfaceWithS3()) {
            return $file;
        }

        // Get WordPress uploads directory data array
        $upload_dir = $this->get_wp_upload_dir();

        // If file path starts with base uploads directory
        if (isset($upload_dir['basedir']) && strpos($file, $upload_dir['basedir']) === 0) {
            // Get the bucket name and configured key path
            list($bucket, $bucket_path) = $this->get_s3_bucket_and_key_path();

            // Build key path
            $uploads_subpath = str_replace($upload_dir['basedir'], '', $file);

            try {
                $s3 = $this->getS3Client();
                $s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => path_join($bucket_path, ltrim($uploads_subpath, '/')),
                ]);
            } catch (\Exception $e) {
                $this->log(json_encode([
                    'error' => $e->getMessage(),
                    'message' => "Could not delete file from S3",
                    'file' => $file,
                ]), true);
            }
        }

        return $file;
    }
}

new MirrorS3();
