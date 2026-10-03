<?php

namespace ProfilePress\Core\Admin\SettingsPages\Membership\ExportPage;

use ProfilePressVendor\Carbon\CarbonImmutable;
use ProfilePressVendor\League\Csv\EscapeFormula;
use ProfilePressVendor\League\Csv\Writer;

abstract class AbstractExport
{
    protected $form = [];

    public function __construct($form)
    {
        $this->form = $form;
    }

    abstract protected function headers();

    abstract protected function get_data($page = 1, $limit = 9999);

    public function execute()
    {
        $export_type = $this->camelCaseToKebabCase($this->class_basename(static::class)) . '-' . CarbonImmutable::now(wp_timezone())->toDateString();

        $filetype = '.csv';
        $filename = 'ppress-' . $export_type . $filetype;

        $page  = 1;
        $limit = 9999;

        $data = $this->get_data($page, $limit);

        if (empty($data)) {
            wp_die(esc_html__('No data found for export parameters', 'wp-user-avatar'));
        }

        // written to a temp stream rather than a guessable file in the public uploads directory.
        $writer = Writer::createFromFileObject(new \SplTempFileObject());

        // customer-supplied values could otherwise run as spreadsheet formulas when the export is opened.
        $writer->addFormatter(new EscapeFormula());

        $writer->insertOne($this->headers());
        $writer->insertAll($data);

        $loop = true;

        $page++;

        while ($loop === true) {

            $response = $this->get_data($page, $limit);

            if (is_array($response) && ! empty($response)) {

                $writer->insertAll($response);

                if (count($response) < $limit) $loop = false;

                $page++;

            } else {
                $loop = false;
            }
        }

        $writer->output($filename);

        exit;
    }

    private function class_basename($class)
    {
        $class = is_object($class) ? get_class($class) : $class;

        return basename(str_replace('\\', '/', $class));
    }

    private function camelCaseToKebabCase($input)
    {
        // Replace all occurrences of a capital letter followed by a lowercase letter with a hyphen and the lowercase letter.
        $output = preg_replace('/([a-z])([A-Z])/', '$1-$2', $input);

        return strtolower($output);
    }
}