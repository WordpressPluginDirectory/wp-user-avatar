<?php

declare (strict_types=1);
namespace ProfilePressVendor\Sabberworm\CSS;

interface Renderable
{
    public function render(OutputFormat $outputFormat): string;
}
