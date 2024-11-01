<?php

namespace Smart_Send;

if (! defined('ABSPATH')) {
    exit;
}
/**
 * Autoloader.
 *
 * @version     1.0.0
  */
class Autoloader
{
    /**
     * Path to the includes directory.
     *
     * @var string
     */
    private $include_path = '';

    /**
     * Autoloader constructor.
     */
    public function __construct()
    {

        if (function_exists("__autoload")) {
            spl_autoload_register("__autoload");
        }

        spl_autoload_register(array( $this, 'autoload' ));
        $this->include_path = untrailingslashit(plugin_dir_path(__FILE__)) . '/';
    }

    /**
     * Take a class name and turn it into a file name.
     *
     * @param  string  $class
     *
     * @return string
     */
    private function get_file_name_from_class(string $class): string
    {
        $class = str_replace("\\", DIRECTORY_SEPARATOR, $class);
        $class = str_replace(__NAMESPACE__ . DIRECTORY_SEPARATOR, '', $class);
        $class = strtolower($class);
        $class = explode(DIRECTORY_SEPARATOR, $class);
        $index = count($class) - 1;
        $class[$index] = 'class-' . str_replace('_', '-', $class[$index]) . '.php';
        return implode(DIRECTORY_SEPARATOR, $class);
    }

    /**
     * Include a class file.
     *
     * @param  string  $path
     *
     * @return bool successful or not
     */
    private function load_file(string $path): bool
    {
        if ($path && is_readable($path)) {
            include_once($path);
            return true;
        }
        return false;
    }

    /**
     * Autoload classes on demand to reduce memory consumption.
     *
     * @param  string  $class
     */
    public function autoload(string $class)
    {
        if (strpos($class, __NAMESPACE__) !== 0) {
            return;
        }

        $file  = $this->get_file_name_from_class($class);
        $path  = '';

        if (empty($path) || ! $this->load_file($path . $file)) {
            $this->load_file($this->include_path . $file);
        }
    }
}
