<?php

namespace App\Logging;

use App\Log;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

class LogHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG)
    {
        parent::__construct($level);
    }

    protected function write(array $record): void
    {
        if (!empty($record['formatted'])) {
            $log = new Log();
            $log->fill($record['formatted']);
            $log->save();
        }
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        return new LogFormatter();
    }
}
